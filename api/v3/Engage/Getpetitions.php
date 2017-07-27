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
 * Get all Media
 *
 * @param see specs below
 * @return array API result array
 * @access public
 */
function civicrm_api3_engage_getpetitions($params) {
  CRM_Gpapi_Processor::preprocessCall($params, 'Engage.getpetitions');

  // get all active campaigns
  $active_campaign_query = civicrm_api3('Campaign', 'get', array(
    'check_permissions' => 0,
    'is_active'         => 1,
    'return'            => 'id',
    'option.limit'      => 0));
  $active_campaign_ids = array();
  foreach ($active_campaign_query['values'] as $campaign) {
    $active_campaign_ids[] = $campaign['id'];
  }

  $params['campaign_id']       = array('IN' => $active_campaign_ids);
  $params['activity_type_id']  = CRM_Core_OptionGroup::getValue('activity_type', 'Petition Signature');
  $params['option.limit']      = 0;
  $params['check_permissions'] = 0;

  return civicrm_api3('Survey', 'get', $params);
}

/**
 * Adjust Metadata for Getproducts action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_engage_getpetitions_spec(&$params) {
}
