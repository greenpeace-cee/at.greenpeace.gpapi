<?php
/*-------------------------------------------------------+
| Greenpeace.at API                                      |
| Copyright (C) 2017 SYSTOPIA                            |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

define('GPAPI_GP_ORG_CONTACT_ID', 1);

/**
 * Process OSF (online donation form) DONATION submission
 *
 * @param see specs below (_civicrm_api3_o_s_f_contract_spec)
 *
 * @return array API result array
 * @throws \Exception
 * @access public
 */
function civicrm_api3_o_s_f_contract($params) {
  // Use default error handler. See GP-23825
  $tempErrorScope = CRM_Core_TemporaryErrorScope::useException();

  try {
    return _civicrm_api3_o_s_f_contract_process($params);
  } catch (Exception $e) {
    CRM_Gpapi_Error::create('OSF.contract', $e, $params);
    throw $e;
  }
}

/**
 * Process OSF.contract in single transaction
 *
 * @param $params
 *
 * @return array
 * @throws \Exception
 */
function _civicrm_api3_o_s_f_contract_process(&$params) {
  $tx = new CRM_Core_Transaction();

  try {
    CRM_Gpapi_Processor::preprocessCall($params, 'OSF.contract');

    if (empty($params['contact_id'])) {
      return CRM_Gpapi_Error::create('OSF.contract', "No 'contact_id' provided.", $params);
    }

    if (empty($params['iban'])) {
      return CRM_Gpapi_Error::create('OSF.contract', "No 'iban' provided.", $params);
    }

    if (empty($params['payment_received']) && !empty($params['trxn_id'])) {
      return CRM_Gpapi_Error::create(
        'OSF.contract',
        "Cannot use parameter 'trxn_id' when 'payment_received' is not set.",
        $params
      );
    }

    CRM_Gpapi_Processor::identifyContactID($params['contact_id']);

    if (empty($params['contact_id'])) {
      return civicrm_api3_create_error('No contact found.');
    }

    $lock = new CRM_Core_Lock('contribute.OSF.mandate', 90, TRUE);
    $lock->acquire();

    if (!$lock->isAcquired()) {
      return CRM_Gpapi_Error::create(
        'OSF.contract',
        "Mandate lock timeout. Try again later.",
        $params
      );
    }

    try {
      $contract_helper = \Civi\Gpapi\ContractHelper\Factory::createWithoutExistingMembership($params);
      $contract = $contract_helper->create($params);
    } catch (Exception $ex) {
      throw $ex;
    } finally {
      $lock->release();
    }

    $null = NULL;

    return civicrm_api3_create_success(
      [['id' => $contract['id']]],
      $params,
      'OSF',
      'contract',
      $null,
      [ 'id' => $contract['id'] ]
    );
  } catch (Exception $e) {
    $tx->rollback();

    if ($e instanceof Civi\Gpapi\ContractHelper\Exception) {
      switch ($e->getCode()) {
        case Civi\Gpapi\ContractHelper\Exception::PAYMENT_INSTRUMENT_UNSUPPORTED:
          throw new API_Exception(
            'Requested payment instrument "' . $params['payment_instrument'] . '"is not supported',
            'payment_instrument_unsupported'
          );

        case Civi\Gpapi\ContractHelper\Exception::PAYMENT_METHOD_INVALID:
          throw new API_Exception(
            'Contract has invalid payment method',
            'payment_method_invalid'
          );

        case Civi\Gpapi\ContractHelper\Exception::PAYMENT_METHOD_INVALID:
          throw new API_Exception(
            'Contract has unsupported payment service provider',
            'payment_service_provider_unsupported'
          );
      }
    }

    if (!empty($referrer)) {
      $relationshipType = civicrm_api3('RelationshipType', 'getvalue', [
        'return' => 'id',
        'name_a_b' => 'Referrer of',
      ]);
      // it is necessary to wrap Relationship.create in a nested transaction to
      // prevent a rollback from bubbling up to the main API transaction when a
      // "Duplicate Relationship" exception occurs. This would otherwise cause
      // us to return a success response even though a rollback is performed.
      CRM_Core_Transaction::create(TRUE)->run(function($subTx) use ($referrer, $params, $relationshipType) {
        try {
          civicrm_api3('Relationship', 'create', [
            'contact_id_a' => $referrer,
            'contact_id_b' => $params['contact_id'],
            'relationship_type_id' => $relationshipType,
            'start_date' => date('Ymd'),
          ]);
        }
        catch (CiviCRM_API3_Exception $e) {
          if ($e->getMessage() == 'Duplicate Relationship') {
            civicrm_api3('Activity', 'create', [
              'activity_type_id' => 'manual_check_required',
              'target_id' => [$params['contact_id'], $referrer],
              'subject' => 'Potential Referrer Fraud',
              'details' => 'Contact already referred a membership to the referee.',
              'status_id' => 'Scheduled',
              'check_permissions' => 0,
            ]);
            CRM_Core_Error::debug_log_message("OSF.contract: Potential Referrer Fraud with contacts {$params['contact_id']} and {$referrer}");
          }
          else {
            throw $e;
          }
        }
      });
      $membership_data = [
        'id' => $result['id'],
        'membership_referrer' => $referrer,
        'skip_handler' => TRUE, // CE should ignore this change
      ];
      CRM_Gpapi_Processor::resolveCustomFields($membership_data, ['membership_referral']);
      civicrm_api3('Membership', 'create', $membership_data);
    }

    // creates bank account by 'iban' and 'bic' fields included in 'psp_result_data' params
    $params_iban = _civicrm_api3_get_psp_result_data_iban($params);
    if (!is_null($params_iban)) {
      $params_bic  = _civicrm_api3_get_psp_result_data_bic($params);
      $extras = !is_null($params_bic) ? ['BIC' => $params_bic] : [];
      _civicrm_api3_o_s_f_contract_getBA($params_iban, $params['contact_id'], $extras);
    }

    // and return the good news (otherwise an Exception would have occurred)
    return $result;
  } catch (Exception $e) {
    $tx->rollback();
    throw $e;
  }
}

/**
 * Get or create the PSP/Needs Rewrite tag
 *
 * @return int tag_id
 * @throws \CiviCRM_API3_Exception
 */
function _civicrm_api3_o_s_f_contract_getPSPTagId() {
  $name = 'PSP/Needs Rewrite';
  $tag = civicrm_api3('Tag', 'get', [
    'name' => $name,
  ]);
  if ($tag['count'] > 0) {
    return reset($tag['values'])['id'];
  }
  $tag = civicrm_api3('Tag', 'create', [
    'used_for' => 'Activities',
    'name' => $name,
    'is_reserved' => 1,
    'is_selectable' => 0,
  ]);
  return $tag['id'];
}

/**
 * get or create the bank account of the given contact/iban
 *
 * @return int banking_account.id
 * @throws \CiviCRM_API3_Exception
 */
function _civicrm_api3_o_s_f_contract_getBA($iban, $contact_id, $extra_data = []) {
  // look up reference type option value ID(!)
  $reference_type_value = civicrm_api3('OptionValue', 'getsingle', array(
    'value'           => 'IBAN',
    'option_group_id' => 'civicrm_banking.reference_types',
    'is_active'       => 1));

  // find existing references
  $existing_references = civicrm_api3('BankingAccountReference', 'get', array(
    'reference'         => $iban,
    'reference_type_id' => $reference_type_value['id'],
    'option.limit'      => 0));

  // get the accounts for this
  $bank_account_ids = array();
  foreach ($existing_references['values'] as $account_reference) {
    $bank_account_ids[] = $account_reference['ba_id'];
  }
  if (!empty($bank_account_ids)) {
    $contact_bank_accounts = civicrm_api3('BankingAccount', 'get', array(
      'id'           => array('IN' => $bank_account_ids),
      'contact_id'   => $contact_id,
      'option.limit' => 1));
    if ($contact_bank_accounts['count']) {
      // bank account already exists with the contact
      $bank_account = reset($contact_bank_accounts['values']);
      return $bank_account['id'];
    }
  }

  // if we get here, that means that there is no such bank account
  //  => create one
  $extra_data['country'] = substr($iban, 0, 2);
  $bank_account = civicrm_api3('BankingAccount', 'create', array(
    'contact_id'  => $contact_id,
    'description' => "Bulk Importer",
    'data_parsed' => json_encode($extra_data)));

  $bank_account_reference = civicrm_api3('BankingAccountReference', 'create', array(
    'reference'         => $iban,
    'reference_type_id' => $reference_type_value['id'],
    'ba_id'             => $bank_account['id']));
  return $bank_account['id'];
}

/**
 * Get iban from psp result data params
 *
 * @param $params
 * @return mixed|null
 */
function _civicrm_api3_get_psp_result_data_iban($params) {
  $iban = null;
  if (!empty($params['psp_result_data']['additionalData']['iban'])) {
    $iban = $params['psp_result_data']['additionalData']['iban'];
  } elseif (!empty($params['psp_result_data']['iban'])) {
    $iban = $params['psp_result_data']['iban'];
  }
  return $iban;
}

/**
 * Get bic from psp result data params
 *
 * @param $params
 * @return mixed|null
 */
function _civicrm_api3_get_psp_result_data_bic($params) {
  $bic = null;
  if (!empty($params['psp_result_data']['additionalData']['bic'])) {
    $bic = $params['psp_result_data']['additionalData']['bic'];
  } elseif (!empty($params['psp_result_data']['bic'])) {
    $bic = $params['psp_result_data']['bic'];
  }
  return $bic;
}

/**
 * Adjust Metadata for Payment action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_o_s_f_contract_spec(&$params) {
  // CONTACT BASE
  $params['contact_id'] = array(
    'name'         => 'contact_id',
    'api.required' => 1,
    'title'        => 'CiviCRM Contact ID',
  );
  $params['campaign'] = array(
    'name'         => 'campaign',
    'api.required' => 0,
    'title'        => 'CiviCRM Campaign (external identifier)',
  );
  $params['campaign_id'] = array(
    'name'         => 'campaign_id',
    'api.required' => 0,
    'title'        => 'CiviCRM Campaign ID',
    'description'  => 'Overwrites "campaign"',
  );
  $params['amount'] = array(
    'name'         => 'amount',
    'api.required' => 1,
    'title'        => 'Amount (per collection)',
  );
  $params['frequency'] = array(
    'name'         => 'frequency',
    'api.required' => 1,
    'title'        => 'Frequency (collections per year)',
  );
  $params['membership_type_id'] = array(
    'name'         => 'membership_type_id',
    'api.required' => 0,
    'api.default'  => Civi::settings()->get('gpapi_membership_type_id'),
    'title'        => 'Membership Type (CiviCRM ID)',
  );
  $params['iban'] = array(
    'name'         => 'iban',
    'api.required' => 1,
    'title'        => 'IBAN',
  );
  $params['bic'] = array(
    'name'         => 'bic',
    'api.required' => 0,
    'title'        => 'BIC',
  );
  $params['payment_received'] = [
    'name'         => 'payment_received',
    'api.required' => 0,
    'title'        => 'Whether the initial payment has already been received',
  ];
  $params['payment_service_provider'] = [
    'name'         => 'payment_service_provider',
    'api.required' => 0,
    'title'        => 'Payment service provider',
    'description'  => 'PSP for this contract. One of "adyen" or "payu". Leave empty for SEPA.',
  ];
  $params['currency'] = [
    'name'         => 'currency',
    'api.default'  => 'EUR',
    'title'        => 'Currency',
  ];
  $params['trxn_id'] = [
    'name'         => 'trxn_id',
    'api.required' => 0,
    'title'        => 'Transaction ID (only for non-SEPA with payment_received=1)',
  ];
  $params['payment_instrument'] = [
    'name'         => 'payment_instrument',
    'api.default'  => 'RCUR',
    'title'        => 'Payment type ("Credit Card" or "RCUR" for SEPA)',
  ];
  $params['referrer_contact_id'] = [
    'name'         => 'referrer_contact_id',
    'api.required' => 0,
    'title'        => 'ID of the contact who referred this contract',
  ];
  $params['psp_result_data'] = [
    'name' => 'psp_result_data',
    'title' => 'PSP Result Data',
    'api.required' => 0,
  ];

  // UTM fields:
  $params['utm_source'] = [
    'name' => 'utm_source',
    'title' => 'UTM Source',
    'api.required' => 0,
  ];
  $params['utm_medium'] = [
    'name' => 'utm_medium',
    'title' => 'UTM Medium',
    'api.required' => 0,
  ];
  $params['utm_campaign'] = [
    'name' => 'utm_campaign',
    'title' => 'UTM Campaign',
    'api.required' => 0,
  ];
  $params['utm_content'] = [
    'name' => 'utm_content',
    'title' => 'UTM Content',
    'api.required' => 0,
  ];
}
