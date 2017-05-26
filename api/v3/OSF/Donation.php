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


/**
 * Process OSF (online donation form) DONATION submission
 *
 * @param see specs below (_civicrm_api3_o_s_f_donation_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_o_s_f_donation($params) {
  gpapi_civicrm_fix_API_UID();

  // resolve campaign ID
  if (empty($params['campaign_id']) && !empty($params['campaign'])) {
    $campaign = civicrm_api3('Campaign', 'getsingle', array('external_identifier' => $params['campaign']));
    $params['campaign_id'] = $campaign['id'];
    unset($params['campaign']);
  }

  // format amount
  $params['total_amount'] = number_format($params['total_amount'], 2, '.', '');

  if ($params['payment_instrument'] == 'Credit Card') {
    // PROCESS CREDIT CARD STATEMENT
    $params['payment_instrument_id'] = 1; // 'Credit Card'
    $params['contribution_status_id'] = 1; // Completed
    unset($params['payment_instrument']);
    if (empty($params['receive_date'])) {
      $params['receive_date'] = date('YmdHis');
    }

    $result = civicrm_api3('Contribution', 'create', $params);

  } elseif ($params['payment_instrument'] == 'OOFF') {
    // PROCESS SEPA OOFF STATEMENT
    if (empty($params['iban'])) {
      return civicrm_api3_create_error("No 'iban' provided.");
    }
    if (empty($params['bic'])) {
      return civicrm_api3_create_error("No 'bic' provided.");
    }
    if (empty($params['creation_date'])) {
      $params['creation_date'] = date('YmdHis');
    }
    $params['type'] = 'OOFF';
    $params['amount'] = $params['total_amount'];
    unset($params['total_amount']);
    unset($params['payment_instrument']);

    $result = civicrm_api3('SepaMandate', 'createfull', $params);

  } else {
    return civicrm_api3_create_error("Undefined 'payment_instrument' {$params['payment_instrument']}");
  }

  // and return the good news (otherwise an Exception would have occurred)
  return civicrm_api3_create_success($result);
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
}
