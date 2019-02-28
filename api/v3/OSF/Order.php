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
 *
 * @return array API result array
 * @access public
 * @throws \Exception
 */
function civicrm_api3_o_s_f_order($params) {
  try {
    return civicrm_api3_o_s_f_order_process($params);
  } catch (Exception $e) {
    CRM_Gpapi_Error::create('OSF.order', $e, $params);
    throw $e;
  }
}

/**
 * Process OSF.order in single transaction
 *
 * @param $params
 *
 * @return array
 * @throws \Exception
 */
function civicrm_api3_o_s_f_order_process($params) {
  $tx = new CRM_Core_Transaction();
  try {
    CRM_Gpapi_Processor::preprocessCall($params, 'OSF.order');

    if (empty($params['contact_id'])) {
      return civicrm_api3_create_error("No 'contact_id' provided.");
    }

    if (empty($params['linked_contribution']) && empty($params['linked_membership'])) {
      return civicrm_api3_create_error("You need to provide a 'linked_contribution' or 'linked_membership' via OSF.order API.");
    }

    if (!empty($params['linked_contribution']) && !empty($params['linked_membership'])) {
      return civicrm_api3_create_error("You must not provide both 'linked_contribution' and 'linked_membership' via OSF.order API.");
    }

    // resolve campaign ID
    CRM_Gpapi_Processor::resolveCampaign($params);

    // adjust fields
    $params['target_id'] = $params['contact_id'];
    unset($params['contact_id']);
    $params['activity_type_id']  = 'Webshop Order';
    $params['status_id']         = 'Scheduled';
    $params['check_permissions'] = 0;

    // resolve custom fields
    CRM_Gpapi_Processor::resolveCustomFields($params, array('webshop_information'));

    // create Webshop Order activity
    return civicrm_api3('Activity', 'create', $params);
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
    'title'        => 'Webshop Order Subject Line',
    );
  $params['order_type'] = array(
    'name'         => 'order_type',
    'api.required' => 1,
    'title'        => 'Webshop Order Type',
    );
  $params['shirt_type'] = array(
    'name'         => 'shirt_type',
    'api.required' => 0,
    'title'        => 'T-Shirt Type: M/W',
    );
  $params['shirt_size'] = array(
    'name'         => 'shirt_size',
    'api.required' => 0,
    'title'        => 'T-Shirt Size: S/M/L/XL',
    );
  $params['order_count'] = array(
    'name'         => 'order_count',
    'api.required' => 1,
    'title'        => 'Webshop Order Count',
    );
  $params['linked_contribution'] = array(
    'name'         => 'linked_contribution',
    'api.required' => 0,
    'title'        => 'Linked Contribution ID',
    );
  $params['linked_membership'] = array(
    'name'         => 'linked_membership',
    'api.required' => 0,
    'title'        => 'Linked Membership ID',
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
