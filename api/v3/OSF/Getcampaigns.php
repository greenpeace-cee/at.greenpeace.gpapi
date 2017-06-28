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
 * Get all Campaigns relevant for OSF
 *
 * @param see specs below (_civicrm_api3_o_s_f_contract_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_o_s_f_getcampaigns($params) {
  CRM_Core_Error::debug_log_message("OSF.getcampaigns: " . json_encode($params));

  // restrict IDs: load all subcampaigns under campaign "Online Marketing" (Web)
  $root_campaign_id = CRM_Core_DAO::singleValueQuery("SELECT id AS campaign_id FROM civicrm_campaign WHERE external_identifier='Web'");
  if (empty($root_campaign_id)) {
    return civicrm_api3_create_error('Root campaign "Online Marketing" [Web] not found!');
  }

  $all_campaigns = array();
  $new_campaigns = array($root_campaign_id);

  // now keep loading the next generations until there's no more new IDs
  while (count($new_campaigns) > 0) {
    // load the next generation
    $all_campaigns += $new_campaigns;
    $current_generation = implode(',', $new_campaigns);
    $nexgen_query = CRM_Core_DAO::executeQuery("SELECT id AS campaign_id FROM civicrm_campaign WHERE parent_id IN ($current_generation)");
    $new_campaigns = array();
    while ($nexgen_query->fetch()) {
      $new_campaigns[] = $nexgen_query->campaign_id;
    }
  }

  // prepare call to pass on to Campaign.get
  $params['option.limit']      = 0;
  $params['is_active']         = 1;
  $params['id']                = array('IN' => $all_campaigns);
  $params['check_permissions'] = 0;

  // pass to Campaign.get
  return civicrm_api3('Campaign', 'get', $params);
}

/**
 * Adjust Metadata for Getcampaigns action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_o_s_f_getcampaigns_spec(&$params) {
}
