<?php

/**
 * Get all Campaigns relevant for OSF
 *
 * @param see specs below (_civicrm_api3_engage_getcampaigns_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_engage_getcampaigns($params) {
    CRM_Gpapi_Processor::preprocessCall($params, 'Engage.getcampaigns');

    $etEnabledId = civicrm_api3("CustomField", "getsingle", [
        "name"   => "campaign_et_enabled",
        "return" => [ "id" ],
    ])["id"];

    // Select root campaigns (no parent_id) or campaigns flagged with 'campaign_et_enabled'
    return civicrm_api3("Campaign", "get", [
        "sequential"          => 1,
        "parent_id"           => [ "IS NULL" => 1 ],
        "custom_$etEnabledId" => 1,
        "options"             => [ "or" => [ [ "parent_id", "custom_$etEnabledId" ] ] ],
    ]);
}

function _civicrm_api3_engage_getcampaigns_spec(&$params) {}

?>
