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

include_once __DIR__ . '/Contract.php';

/**
 * Process OSF (online donation form) DONATION submission
 *
 * @param see specs below (_civicrm_api3_o_s_f_donation_spec)
 *
 * @return array API result array
 * @access public
 * @throws \Exception
 */
function civicrm_api3_o_s_f_donation($params) {
  try {
    return _civicrm_api3_o_s_f_donation_process($params);
  } catch (Exception $e) {
    CRM_Gpapi_Error::create('OSF.donation', $e, $params);
    throw $e;
  }
}

/**
 * Process OSF.donate in single transaction
 *
 * @param $params
 *
 * @return array
 * @throws \Exception
 */
function _civicrm_api3_o_s_f_donation_process($params) {
  $tx = new CRM_Core_Transaction();
  try {
    CRM_Gpapi_Processor::preprocessCall($params, 'OSF.donation');

    if (empty($params['contact_id'])) {
      return civicrm_api3_create_error("No 'contact_id' provided.");
    }

    CRM_Gpapi_Processor::identifyContactID($params['contact_id']);

    if (empty($params['contact_id'])) {
      return civicrm_api3_create_error('No contact found.');
    }

    $params['check_permissions'] = 0;

    CRM_Gpapi_Processor::resolveCampaign($params);

    // format amount
    $params['total_amount'] = number_format($params['total_amount'], 2, '.', '');

    if (_civicrm_api3_is_donation_failed($params)) {
      $params['cancel_date'] = date('YmdHis');
    }

    switch (strtolower($params['payment_instrument'])) {
      case 'credit card':
        // CREATE CREDIT CARD CONTRIBUTION
        $contribution = _civicrm_api3_o_s_f_donation_create_nonsepa_contribution($params, _civicrm_api3_get_payment_instrument_id('Credit Card'));
        break;

      case 'paypal':
        // CREATE PAYPAL CONTRIBUTION
        $contribution = _civicrm_api3_o_s_f_donation_create_nonsepa_contribution($params, _civicrm_api3_get_payment_instrument_id('PayPal'));
        break;

      case 'sofortüberweisung':
      case 'sofortueberweisung':
        // CREATE SOFORTÜBERWEISUNG CONTRIBUTION
        $contribution = _civicrm_api3_o_s_f_donation_create_nonsepa_contribution($params, _civicrm_api3_get_payment_instrument_id('Sofortüberweisung'));
        break;

      case 'eps':
        // CREATE EPS CONTRIBUTION
        $contribution = _civicrm_api3_o_s_f_donation_create_nonsepa_contribution($params, _civicrm_api3_get_payment_instrument_id('EPS'));
        break;

      case 'ooff':
        // PROCESS SEPA OOFF STATEMENT
        if (empty($params['iban'])) {
          return civicrm_api3_create_error("No 'iban' provided.");
        }
        if (!empty($params['trxn_id'])) {
          return civicrm_api3_create_error("Cannot use 'trxn_id' with payment_instrument=OOFF.");
        }
        if (empty($params['creation_date'])) {
          $params['creation_date'] = date('YmdHis');
        }
        $params['type'] = 'OOFF';
        $params['amount'] = $params['total_amount'];
        unset($params['total_amount']);
        unset($params['payment_instrument']);

        // add bank accounts
        _civicrm_api3_o_s_f_contract_getBA($params['iban'], $params['contact_id']);

        // create mandate
        // add a mutex lock (see GP-1731)
        $lock = new CRM_Core_Lock('contribute.OSF.mandate', 90, TRUE);
        $lock->acquire();
        if (!$lock->isAcquired()) {
          return civicrm_api3_create_error("OSF.mandate lock timeout. Sorry. Try again later.");
        }

        try {
          $mandate = civicrm_api3('SepaMandate', 'createfull', $params);
        } catch (Exception $ex) {
          $lock->release();
          throw $ex;
        }
        $lock->release();

        // reload mandate
        $mandate = civicrm_api3('SepaMandate', 'getsingle', array(
          'id'     => $mandate['id'],
          'return' => 'entity_id'));

        // return the created contribution (see GP-1029)
        $contribution = civicrm_api3('Contribution', 'get', array(
          'id'         => $mandate['entity_id'],
          'sequential' => CRM_Utils_Array::value('sequential', $params, '0')));

        break;

      default:
        return civicrm_api3_create_error("Undefined 'payment_instrument' {$params['payment_instrument']}");
    }

    if (count(CRM_Gpapi_Processor::extractUTMData($params)) > 0) {
      try {
        $activity_id = civicrm_api3('Activity', 'getvalue', [
          'return' => 'id',
          'activity_type_id' => 'Contribution',
          'source_record_id' => $contribution['id'],
        ]);
      } catch (CiviCRM_API3_Exception $e) {
        $activity = civicrm_api3('Activity', 'create', [
          'source_contact_id' => $params['contact_id'],
          'source_record_id' => $contribution['id'],
          'activity_type_id' => 'UTM Tracking',
          'subject' => 'Contribution',
        ]);
        $activity_id = $activity['id'];
      }

      CRM_Gpapi_Processor::updateActivityWithUTM($params, $activity_id);
    }

    //creates bank account by 'iban' and 'bic' fields from 'psp_result_data' params
    if (!empty($params['psp_result_data']['iban']) && !empty($params['psp_result_data']['bic'])) {
      _civicrm_api3_o_s_f_contract_getBA($params['psp_result_data']['iban'], $params['contact_id'], ['BIC' => $params['psp_result_data']['bic']]);
    }

    return $contribution;
  } catch (Exception $e) {
    $tx->rollback();
    throw $e;
  }
}

/**
 * Adjust Metadata for Payment action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_o_s_f_donation_spec(&$params) {
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
  $params['total_amount'] = array(
    'name'         => 'total_amount',
    'api.required' => 1,
    'title'        => 'Amount',
    );
  $params['currency'] = array(
    'name'         => 'currency',
    'api.default'  => 'EUR',
    'title'        => 'Currency',
    );
  $params['payment_instrument'] = array(
    'name'         => 'payment_instrument',
    'api.default'  => 'Credit Card',
    'title'        => 'Payment type ("Credit Card" or "OOFF" for SEPA)',
    );
  $params['financial_type_id'] = array(
    'name'         => 'financial_type_id',
    'api.default'  => 1, // Donation
    'title'        => 'Financial Type, e.g. 1="Donation"',
    );
  $params['source'] = array(
    'name'         => 'source',
    'api.default'  => "OSF",
    'title'        => 'Source of donation',
    );
  $params['iban'] = array(
    'name'         => 'iban',
    'api.required' => 0,
    'title'        => 'IBAN (only for payment_instrument=OOFF)',
    );
  $params['bic'] = array(
    'name'         => 'bic',
    'api.required' => 0,
    'title'        => 'BIC (only for payment_instrument=OOFF)',
    );
  $params['gp_iban'] = array(
    'name'         => 'gp_iban',
    'api.required' => 0,
    'title'        => 'GP IBAN (incoming bank account)',
    );
  $params['trxn_id'] = [
    'name'         => 'trxn_id',
    'api.required' => 0,
    'title'        => 'Transaction ID (only for payment_instrument!=OOFF)',
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

  // Accept failed donation attempts GP-13219
  $params['failed'] = [
    'name' => 'failed',
    'title' => 'Failed Donation',
    'api.required' => 0,
    'api.default'  => false,
    'type'         => CRM_Utils_TYPE::T_BOOLEAN,
  ];
  $params['cancel_reason'] = [
    'name' => 'cancel_reason',
    'title' => 'Cancel Reason',
    'api.required' => 0
  ];
}

/**
 * Helper function to generate NON-SEPA payments
 */
function _civicrm_api3_o_s_f_donation_create_nonsepa_contribution($params, $payment_instrument_id) {
  $params['payment_instrument_id']  = $payment_instrument_id;
  // Accept failed donation attempts GP-13219
  $contribution_status = _civicrm_api3_is_donation_failed($params) ? 'Failed' : 'Completed';
  $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey(
    'CRM_Contribute_BAO_Contribution','contribution_status_id', $contribution_status
  );
  unset($params['payment_instrument']);
  if (empty($params['receive_date'])) {
    $params['receive_date'] = date('YmdHis');
  }

  // add bank account (see GP-1356)
  if (!empty($params['gp_iban'])) {
    $to_ba_field = civicrm_api3('CustomField', 'get', array(
      'name'            => 'to_ba',
      'custom_group_id' => 'contribution_information',
      'return'          => 'id'));
    if (!empty($to_ba_field['id'])) {
      $params["custom_{$to_ba_field['id']}"] = _civicrm_api3_o_s_f_contract_getBA($params['gp_iban'], GPAPI_GP_ORG_CONTACT_ID, array());
    }
  }

  return civicrm_api3('Contribution', 'create', $params);
}

/**
 * Get payment instrument id by value
 *
 * @param $value
 * @return bool|int|string|null
 */
function _civicrm_api3_get_payment_instrument_id($value) {
  return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution','payment_instrument_id',$value);
}

/**
 * Is donation failed?
 *
 * @param $params
 * @return bool
 */
function _civicrm_api3_is_donation_failed($params): bool
{
  return !empty($params['failed']);
}
