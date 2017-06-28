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
function civicrm_api3_engage_getmedia($params) {
  CRM_Core_Error::debug_log_message("Engage.getmedia: " . json_encode($params));

  // prepare call to pass on to Campaign.get
  $params['option.limit']    = 0;
  $params['option.sort']     = 'weight asc';
  $params['option_group_id'] = 'encounter_medium';

  // pass to OptionValue.get
  return civicrm_api3('OptionValue', 'get', $params);
}

/**
 * Adjust Metadata for Getproducts action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_engage_getmedia_spec(&$params) {
}
