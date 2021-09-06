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

    $payment_instrument = (int) CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution',
      'payment_instrument_id',
      $params['payment_instrument']
    );

    $referrer = NULL;
    if (!empty($params['referrer_contact_id'])) {
      try {
        $referrer = civicrm_api3('Contact', 'identify', [
          'identifier_type' => 'internal',
          'identifier' => (int) $params['referrer_contact_id'],
        ])['id'];
        if ($referrer == $params['contact_id']) {
          return CRM_Gpapi_Error::create(
            'OSF.contract',
            "Parameter 'referrer_contact_id' must not match 'contact_id'",
            $params
          );
        }
      } catch (CiviCRM_API3_Exception $e) {
        civicrm_api3('Activity', 'create', [
          'activity_type_id' => 'manual_update_required',
          'target_id' => $params['contact_id'],
          'subject' => 'Invalid Referrer Submitted',
          'details' => 'Membership was submitted with a referrer, but no contact was found for value "' . $params['referrer_contact_id'] . '"',
          'status_id' => 'Scheduled',
          'check_permissions' => 0,
        ]);
        CRM_Core_Error::debug_log_message("OSF.contract: Unable to find referrer {$params['referrer_contact_id']}: " . $e->getMessage());
      }
    }

    if (empty($params['payment_service_provider'])) {
      $creditor = (array) CRM_Sepa_Logic_Settings::defaultCreditor();
      $referenceType = 'IBAN';
    }
    else {
      $referenceType = 'NBAN_' . strtoupper($params['payment_service_provider']);
      $fileFormat = civicrm_api3('OptionValue', 'getvalue', [
        'return' => "value",
        'option_group_id' => 'sepa_file_format',
        'name' => $params['payment_service_provider'],
      ]);
      $creditorLookup = [
        'creditor_type' => 'PSP',
        'sepa_file_format_id' => $fileFormat,
      ];
      if (empty($params['currency'])) {
        $config = CRM_Core_Config::singleton();
        $creditorLookup['currency'] = $config->defaultCurrency;
      }
      else {
        $creditorLookup['currency'] = $params['currency'];
      }
      $creditor = civicrm_api3('SepaCreditor', 'getsingle', $creditorLookup);
    }

    $currency = $creditor['currency'];
    if (!empty($params['currency'])) {
      $currency = $params['currency'];
    }

    // resolve campaign ID
    CRM_Gpapi_Processor::resolveCampaign($params);

    // prepare parameters
    $params['member_since'] = date('YmdHis');
    $params['start_date'] = date('YmdHis');
    if (empty($params['payment_received'])) {
      $buffer_days = (int) CRM_Sepa_Logic_Settings::getSetting("pp_buffer_days");
      $frst_notice_days = (int) CRM_Sepa_Logic_Settings::getSetting("batching.FRST.notice", $creditor['id']);
      $earliest_rcur_date = strtotime("+ $frst_notice_days days + $buffer_days days");
    }
    else {
      // first payment was completed within the ODF, we should look at possible
      // cycle days at least one month from now
      $earliest_rcur_date = strtotime('+1 month');
      // start_date will be set based on the cycle_day determined below
      $params['start_date'] = NULL;
    }

    $params['amount'] = number_format($params['amount'], 2, '.', '');

    if (empty($params['cycle_day'])) {
      // SEPA stuff (TODO: use new service)
      $cycle_days = CRM_Sepa_Logic_Settings::getListSetting("cycledays", range(1, 28), $creditor['id']);
      $cycle_day = $earliest_rcur_date;
      while (!in_array(date('d', $cycle_day), $cycle_days)) {
        $cycle_day = strtotime("+ 1 day", $cycle_day);
      }
      if (is_null($params['start_date'])) {
        $params['start_date'] = date('YmdHis', $cycle_day);
      }
      $cycle_day = date('d', $cycle_day);
    }
    else {
      $cycle_day = (int) $params['cycle_day'];
      if (is_null($params['start_date'])) {
        // take the first date where day=cycle_day and at least 1 month has passed
        // since the initial contribution
        $cycle_date = $earliest_rcur_date;
        while (date('d', $cycle_date) != $cycle_day) {
          $cycle_date = strtotime("+ 1 day", $cycle_date);
        }
        $params['start_date'] = date('YmdHis', $cycle_date);
      }
    }

    // add a mutex lock (see GP-1731)
    $lock = new CRM_Core_Lock('contribute.OSF.mandate', 90, TRUE);
    $lock->acquire();
    if (!$lock->isAcquired()) {
      return CRM_Gpapi_Error::create(
        'OSF.contract',
        "Mandate lock timeout. Try again later.",
        $params
      );
    }

    // first: create a mandate
    try {
      $bank_account = _civicrm_api3_o_s_f_contract_getBA($params['iban'], $params['contact_id']);

      $create_contract_params = [
        'campaign_id'                          => $params['campaign_id'],
        'check_permissions'                    => 0,
        'contact_id'                           => $params['contact_id'],
        'join_date'                            => $params['member_since'],
        'membership_payment.from_ba'           => $bank_account,
        // 'membership_payment.to_ba'             => _civicrm_api3_o_s_f_contract_getBA($creditor['iban'], GPAPI_GP_ORG_CONTACT_ID, []),
        'membership_type_id'                   => $params['membership_type_id'],
        'payment_method.amount'                => $params['amount'],
        'payment_method.campaign_id'           => $params['campaign_id'],
        'payment_method.contact_id'            => $params['contact_id'],
        'payment_method.creditor_id'           => $creditor['id'],
        'payment_method.currency'              => $currency,
        'payment_method.cycle_day'             => $cycle_day,
        'payment_method.financial_type_id'     => "2", // Membership dues
        'payment_method.frequency_interval'    => (int) (12.0 / $params['frequency']),
        'payment_method.frequency_unit'        => 'month',
        'payment_method.payment_instrument_id' => $payment_instrument,
        'payment_method.type'                  => 'RCUR',
        'sequential'                           => empty($params['sequential']) ? 0 : 1,
        'source'                               => 'OSF',
        'start_date'                           => $params['start_date'],
      ];

      if ($creditor['creditor_type'] === 'PSP') {
        $create_contract_params['payment_method.adapter'] = 'psp_sepa';
        $create_contract_params['payment_method.account_reference'] = $params['iban'];
        $create_contract_params['payment_method.account_name'] = $params['bic'];
      } else {
        $create_contract_params['payment_method.adapter'] = 'sepa_mandate';
        $create_contract_params['payment_method.iban'] = $params['iban'];
        $create_contract_params['payment_method.bic'] = $params['bic'];
      }

      $result = civicrm_api3('Contract', 'create', $create_contract_params);

      $recurring_contribution_id = civicrm_api3('ContractPaymentLink', 'getvalue', [
        'contract_id' => $result['id'],
        'return'      => "contribution_recur_id",
      ]);

      $mandate = civicrm_api3('SepaMandate', 'getsingle', [
        'check_permissions' => 0,
        'entity_id'         => $recurring_contribution_id,
      ]);
    } catch (Exception $ex) {
      throw $ex;
    } finally {
      $lock->release();
    }

    $activity_id = civicrm_api3('Activity', 'getvalue', [
      'return' => 'id',
      'activity_type_id' => 'Contract_Signed',
      'source_record_id' => $result['id'],
    ]);

    CRM_Gpapi_Processor::updateActivityWithUTM($params, $activity_id);

    // get BA reference type for IBAN to do BankingAccountReference lookup by type
    $reference_type_iban = civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'id',
      'option_group_id' => 'civicrm_banking.reference_types',
      'value' => 'IBAN',
    ]);
    $bank_account_reference = civicrm_api3('BankingAccountReference', 'getvalue', [
      'return' => 'id',
      'ba_id' => $bank_account,
      'reference_type_id' => $reference_type_iban,
    ]);
    $reference_type_id = civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'id',
      'option_group_id' => 'civicrm_banking.reference_types',
      'value' => $referenceType,
    ]);
    // update the bank account reference type (CE always creates IBAN)
    civicrm_api3('BankingAccountReference', 'create', [
      'reference_type_id' => $reference_type_id,
      'id' => $bank_account_reference,
    ]);

    if (!empty($params['payment_received'])) {
      // create the initial contribution
      $rec_contribution = civicrm_api3('ContributionRecur', 'getsingle', [
        'check_permissions' => 0,
        'id' => $mandate['entity_id']
      ]);

      // Tag "Contract_Signed" Activity for post-processing, see GP-1933
      civicrm_api3('EntityTag', 'create', [
        'tag_id' => _civicrm_api3_o_s_f_contract_getPSPTagId(),
        'entity_table' => 'civicrm_activity',
        'entity_id' => $activity_id,
      ]);

      $contribution_data = [
        'total_amount' => $rec_contribution['amount'],
        'currency' => $rec_contribution['currency'],
        'receive_date' => $params['member_since'],
        'contact_id' => $mandate['contact_id'],
        'contribution_recur_id' => $mandate['entity_id'],
        'financial_type_id' => $rec_contribution['financial_type_id'],
        'campaign_id' => $rec_contribution['campaign_id'],
        'is_test' => $rec_contribution['is_test'],
        'payment_instrument_id' => $payment_instrument,
        'contribution_status_id' => (int) CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed'),
        'trxn_id' => $params['trxn_id'],
        'source' => 'OSF',
      ];
      $to_ba_field = civicrm_api3('CustomField', 'get', [
        'name' => 'to_ba',
        'custom_group_id' => 'contribution_information',
        'return' => 'id'
      ]);
      if (!empty($to_ba_field['id'])) {
        $contribution_data["custom_{$to_ba_field['id']}"] = _civicrm_api3_o_s_f_contract_getBA($creditor['iban'], GPAPI_GP_ORG_CONTACT_ID, []);
      }
      $contribution = civicrm_api3('Contribution', 'create', $contribution_data);
      CRM_Utils_SepaCustomisationHooks::installment_created($mandate['mandate_id'], $mandate['entity_id'], $contribution['id']);
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

    //creates bank account by 'iban' and 'bic' fields from 'psp_result_data' params
    if (!empty($params['psp_result_data']['iban']) && !empty($params['psp_result_data']['bic'])) {
      _civicrm_api3_o_s_f_contract_getBA($params['psp_result_data']['iban'], $params['contact_id'], ['BIC' => $params['psp_result_data']['bic']]);
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
    'used_for' => 'Contacts',
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
