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
 * @param see specs below (_civicrm_api3_o_s_f_order_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_o_s_f_order($params) {
  gpapi_civicrm_fix_API_UID();

  // resolve campaign ID
  if (empty($params['campaign_id']) && !empty($params['campaign'])) {
    $campaign = civicrm_api3('Campaign', 'getsingle', array('external_identifier' => $params['campaign']));
    $params['campaign_id'] = $campaign['id'];
    unset($params['campaign']);
  }

  // adjust fields
  $params['target_id'] = $params['contact_id'];
  unset($params['contact_id']);
  $params['activity_type_id'] = 'Webshop Order';
  $params['status_id'] = 'Scheduled';

  // resolve custom fields
  gpapi_civicrm_resolveCustomFields($params, array('webshop_information'));

  // create Webshop Order activity
  $result = civicrm_api3('Activity', 'create', $params);

  // and return the good news (otherwise an Exception would have occurred)
  return civicrm_api3_create_success($result);
}

/**
 * Adjust Metadata for Payment action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_o_s_f_order_spec(&$params) {
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
  $params['subject'] = array(
    'name'         => 'subject',
    'api.default'  => "Webshop Order",
    'title'        => 'Webshop Order Type',
    );
  $params['order_type'] = array(
    'name'         => 'order_type',
    'api.required' => 1,
    'title'        => 'Webshop Order Type',
    );
  $params['order_count'] = array(
    'name'         => 'order_count',
    'api.required' => 1,
    'title'        => 'Webshop Order Count',
    );
  $params['payment_received'] = array(
    'name'         => 'payment_received',
    'api.required' => 1,
    'title'        => 'Webshop Order Payment Received',
    );
  $params['multi_purpose'] = array(
    'name'         => 'multi_purpose',
    'api.required' => 0,
    'title'        => 'Webshop Order CustomData',
    );
}
