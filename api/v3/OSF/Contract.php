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

use \Civi\Gpapi\ContractHelper;

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
    _civicrm_api3_o_s_f_contract_preprocessCall($params);

    $contract_helper = ContractHelper\Factory::create($params);

    $lock = _civicrm_api3_o_s_f_contract_acquireLock($params);

    try {
      $contract_helper->create($params);

      $activity_id = $contract_helper->signActivity['id'];
      CRM_Gpapi_Processor::updateActivityWithUTM($params, $activity_id);

      if (isset($params['referrer_contact_id'])) {
        $contract_helper->createReferrerOfRelationship($params);
      }

      if (isset($params['payment_received'])) {
        $contract_helper->createInitialContribution($params);
      }

      $psp_result_data = CRM_Utils_Array::value('psp_result_data', $params, []);

      if (isset($psp_result_data['bic']) && isset($psp_result_data['iban'])) {
        $contract_helper::createBankAccount($params);
      }
    } catch (Exception $ex) {
      throw $ex;
    } finally {
      $lock->release();
    }

    $result_id = $contract_helper->membership['id'];
    $null = NULL;

    return civicrm_api3_create_success(
      [[ 'id' => $result_id ]],
      $params,
      'OSF',
      'contract',
      $null,
      [ 'id' => $result_id ]
    );
  } catch (Exception $e) {
    $tx->rollback();

    _civicrm_api3_o_s_f_contract_handleException($e);

    throw $e;
  }
}

function _civicrm_api3_o_s_f_contract_acquireLock(array $params) {
  $lock = new CRM_Core_Lock('contribute.OSF.mandate', 90, TRUE);
  $lock->acquire();

  if (!$lock->isAcquired()) {
    $error = CRM_Gpapi_Error::create(
      'OSF.contract',
      "Mandate lock timeout. Try again later.",
      $params
    );

    throw new API_Exception($error['error_message'], 'mandate_lock_timeout');
  }

  return $lock;
}

function _civicrm_api3_o_s_f_contract_handleException(Exception $e) {
  if ($e instanceof ContractHelper\Exception) {
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

      case Civi\Gpapi\ContractHelper\Exception::PAYMENT_SERVICE_PROVIDER_UNSUPPORTED:
        throw new API_Exception(
          'Contract has unsupported payment service provider',
          'payment_service_provider_unsupported'
        );
    }
  }
}

function _civicrm_api3_o_s_f_contract_preprocessCall(array &$params) {
  CRM_Gpapi_Processor::preprocessCall($params, 'OSF.contract');

  // --- Identify contact --- //

  $contact_id = $params['contact_id'];
  $contact_id = CRM_Gpapi_Processor::identifyContactID($contact_id);

  if (empty($contact_id)) {
    throw new API_Exception(
      "No contact found with ID '{$params['contact_id']}'",
      'contact_not_found'
    );
  }

  $params['contact_id'] = $contact_id;

  // --- Resolve campaign name to ID --- //

  CRM_Gpapi_Processor::resolveCampaign($params);

  // --- Resolve payment instrument name to ID --- //

  ContractHelper\AbstractHelper::resolvePaymentInstrument($params);

  // --- `trxn_id` must not be set when `payment_received` is empty --- //

  if (empty($params['payment_received']) && !empty($params['trxn_id'])) {
    $error = CRM_Gpapi_Error::create(
      'OSF.contract',
      "Cannot use parameter 'trxn_id' when 'payment_received' is not set",
      $params
    );

    throw new API_Exception($error['error_message'], 'unexpected_trxn_id');
  }

  // --- Assert that the referrer is not the target contact --- //

  $referrer_id = ContractHelper\AbstractHelper::getReferrerContactID($params);

  if (!empty($referrer_id) && (int) $referrer_id === (int) $params['contact_id']) {
    $error = CRM_Gpapi_Error::create(
      'OSF.contract',
      "Parameter 'referrer_contact_id' must not match 'contact_id'",
      $params
    );

    throw new API_Exception($error['error_message'], 'invalid_referrer_id');
  }
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
  $params['cycle_day'] = array(
    'name'         => 'cycle_day',
    'api.required' => 0,
    'title'        => 'Cycle day',
  );
  $params['membership_type_id'] = array(
    'name'         => 'membership_type_id',
    'api.required' => 0,
    'api.default'  => Civi::settings()->get('gpapi_membership_type_id'),
    'title'        => 'Membership Type (CiviCRM ID)',
  );
  $params['iban'] = array(
    'name'         => 'iban',
    'api.required' => 0,
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
    'api.required' => 0,
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
