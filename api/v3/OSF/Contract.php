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
 * @param see specs below (_civicrm_api3_o_s_f_contract_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_o_s_f_contract($params) {
  gpapi_civicrm_fix_API_UID();

  // resolve campaign ID
  if (empty($params['campaign_id']) && !empty($params['campaign'])) {
    $campaign = civicrm_api3('Campaign', 'getsingle', array('external_identifier' => $params['campaign']));
    $params['campaign_id'] = $campaign['id'];
    unset($params['campaign']);
  }

  // prepare parameters
  $params['member_since'] = date('YmdHis'); // now
  $params['start_date'] = date('YmdHis'); // now
  $params['amount'] = number_format($params['amount'], 2, '.', '');

  // create Webshop Order activity
  $result = civicrm_api3('Contract', 'create', $params);

  // and return the good news (otherwise an Exception would have occurred)
  return civicrm_api3_create_success($result);
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
    'api.required' => 1,
    'title'        => 'Membership Type (CiviCRM ID)',
    );
  $params['iban'] = array(
    'name'         => 'iban',
    'api.required' => 1,
    'title'        => 'IBAN',
    );
  $params['bic'] = array(
    'name'         => 'bic',
    'api.required' => 1,
    'title'        => 'BIC',
    );
}
