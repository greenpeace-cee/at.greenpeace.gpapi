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
  CRM_Gpapi_Processor::preprocessCall($params, 'OSF.getcampaigns');

  // restrict IDs: load all subcampaigns under campaign "Online Marketing" (Web)
  $root_campaign_id = CRM_Core_DAO::singleValueQuery("SELECT id AS campaign_id FROM civicrm_campaign WHERE external_identifier='Web'");
  if (empty($root_campaign_id)) {
    return civicrm_api3_create_error('Root campaign "Online Marketing" [Web] not found!');
  }

  $max_depth     = (int) $params['max_depth'];
  $depth         = 0;
  $all_campaigns = array();
  $new_campaigns = array($root_campaign_id);

  // now keep loading the next generations until there's no more new IDs
  while (count($new_campaigns) > 0 && $depth < $max_depth) {
    // load the next generation
    $current_generation = implode(',', $new_campaigns);
    $nexgen_query = CRM_Core_DAO::executeQuery("SELECT id AS campaign_id FROM civicrm_campaign WHERE parent_id IN ($current_generation)");
    $new_campaigns = array();
    while ($nexgen_query->fetch()) {
      $new_campaigns[] = $nexgen_query->campaign_id;
      $all_campaigns[] = $nexgen_query->campaign_id;
    }
    $depth += 1;
  }

  // add campaigns where "Available in Online Donation Form?" is set
  $odf_field = 'custom_' . CRM_Core_BAO_CustomField::getCustomFieldID(
    'campaign_odf_enabled',
    'campaign_information'
  );
  $extra_campaigns = civicrm_api3('Campaign', 'get', [
    $odf_field => 1,
  ]);

  foreach ($extra_campaigns['values'] as $campaign) {
    if (!in_array($campaign['id'], $all_campaigns)) {
      $all_campaigns[] = $campaign['id'];
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
  $params['max_depth'] = array(
    'name'         => 'max_depth',
    'title'        => 'Max Search Depth',
    'api.default'  => 10,
    'description'  => 'Maximum number of generations to pull below "Web" campaign',
    );
}
