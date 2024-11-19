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

use \Civi\Api4;

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
  // Use default error handler. See GP-23825
  $tempErrorScope = CRM_Core_TemporaryErrorScope::useException();

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

    $params['contact_id'] = CRM_Gpapi_Processor::identifyContactID($params['contact_id']);

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

    // creates bank account by 'iban' and 'bic' fields included in 'psp_result_data' params
    $params_iban = _civicrm_api3_get_psp_result_data_iban($params);
    if (!is_null($params_iban)) {
      $params_bic  = _civicrm_api3_get_psp_result_data_bic($params);
      $extras = !is_null($params_bic) ? ['BIC' => $params_bic] : [];
      _civicrm_api3_o_s_f_contract_getBA($params_iban, $params['contact_id'], $extras);
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
  $params['utm_term'] = [
    'name' => 'utm_term',
    'title' => 'UTM Term',
    'api.required' => 0,
  ];
  $params['utm_id'] = [
    'name' => 'utm_id',
    'title' => 'UTM Id',
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

  $psp_result_data = $params['psp_result_data'] ?? [];
  $params['trxn_id'] = $psp_result_data['pspReference'] ?? $params['trxn_id'];
  $params['invoice_id'] = $psp_result_data['merchantReference'] ?? NULL;

  $order = civicrm_api3('Order', 'create', [
    'campaign_id'           => CRM_Utils_Array::value('campaign_id', $params),
    'contact_id'            => $params['contact_id'],
    'currency'              => $params['currency'],
    'financial_type_id'     => $params['financial_type_id'],
    'invoice_id'            => $params['invoice_id'],
    'payment_instrument_id' => $payment_instrument_id,
    'receive_date'          => $params['receive_date'],
    'sequential'            => TRUE,
    'source'                => $params['source'],
    'total_amount'          => $params['total_amount'],
  ]);

  if ($contribution_status === 'Completed') {
    // Donation completed
    $payment_processor_id = _civicrm_api3_o_s_f_donation_get_payment_processor_id($psp_result_data);
    $card_type_id = _civicrm_api3_o_s_f_donation_get_card_type_id($psp_result_data);
    $pan_truncation = _civicrm_api3_o_s_f_donation_get_pan_truncation($psp_result_data);

    civicrm_api3('Payment', 'create', [
      'card_type_id'                      => $card_type_id,
      'contribution_id'                   => $order['id'],
      'fee_amount'                        => 0.0,
      'is_send_contribution_notification' => FALSE,
      'pan_truncation'                    => $pan_truncation,
      'payment_instrument_id'             => $payment_instrument_id,
      'payment_processor_id'              => $payment_processor_id,
      'total_amount'                      => $params['total_amount'],
      'trxn_date'                         => $params['receive_date'],
      'trxn_id'                           => $params['trxn_id'],
    ]);
  } else {
    // Donation failed
    civicrm_api3('Contribution', 'create', [
      'cancel_date'            => $params['receive_date'],
      'cancel_reason'          => $params['cancel_reason'] ?? '',
      'contribution_id'        => $order['id'],
      'contribution_status_id' => $contribution_status,
    ]);
  }

  return civicrm_api3('Contribution', 'get', [
    'id'         => $order['id'],
    'sequential' => TRUE,
  ]);
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

/**
 * Get the ID of the credit card type
 *
 * @param $psp_result_data
 * @return string
 */
function _civicrm_api3_o_s_f_donation_get_card_type_id($psp_result_data) {
  if (empty($psp_result_data['additionalData']['paymentMethod']) && empty($psp_result_data['paymentMethod'])) {
    return NULL;
  }

  $card_type_name = $psp_result_data['additionalData']['paymentMethod'] ?? $psp_result_data['paymentMethod'];

  // Adyen abbreviates mastercard, everything else matches
  if ($card_type_name == 'mc') {
    $card_type_name = 'mastercard';
  }

  // Perform lookup via API4 to avoid case mismatches from cached OptionValues
  $card_type = Api4\OptionValue::get(FALSE)
    ->addSelect('value')
    ->addWhere('option_group_id:name', '=', 'accept_creditcard')
    ->addWhere('name', '=', $card_type_name)
    ->execute()
    ->first();

  return $card_type['value'] ?? NULL;
}

/**
 * Get the PAN truncation of a credit card (last 4 digits of card number)
 *
 * @param $psp_result_data
 * @return string
 */
function _civicrm_api3_o_s_f_donation_get_pan_truncation($psp_result_data) {
  if (!empty($psp_result_data['additionalData']['cardSummary'])) {
    // cardSummary contains last 4 digits for credit card
    return $psp_result_data['additionalData']['cardSummary'];
  }

  if (!empty($psp_result_data['additionalData']['iban'])) {
    // Return last 4 digits of IBAN if one is available
    return substr($psp_result_data['additionalData']['iban'], -4);
  }

  return NULL;
}

/**
 * Get the ID of the payment processor
 *
 * @param $psp_result_data
 * @return int
 */
function _civicrm_api3_o_s_f_donation_get_payment_processor_id($psp_result_data) {
  if (isset($psp_result_data['merchantAccountCode'])) {
    $payment_processor = Api4\PaymentProcessor::get(FALSE)
      ->addWhere('payment_processor_type_id:name', '=', 'Adyen')
      ->addWhere('name', '=', $psp_result_data['merchantAccountCode'])
      ->addSelect('id')
      ->execute()
      ->first();

    if (isset($payment_processor)) return (int) $payment_processor['id'];
  }

  return NULL;
}
