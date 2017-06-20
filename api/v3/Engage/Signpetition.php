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
 * Process OSF (online donation form) base submission
 *
 * @param see specs below (_civicrm_api3_engage_signpetition_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_engage_signpetition($params) {
  CRM_Core_Error::debug_log_message("Engage.signpetition: " . json_encode($params));
  $result = array();
  gpapi_civicrm_fix_API_UID();

  // check input
  if (   (empty($params['first_name']) || empty($params['email']))
      && (empty($params['last_name']) || empty($params['email']))
      ) {
    // we do not have enough information to match/create the contact
    return civicrm_api3_create_error("Insufficient contact data");
  }

  // prepare data: prefix
  if (empty($params['prefix_id']) && !empty($params['prefix'])) {
    $params['prefix_id'] = CRM_Core_OptionGroup::getValue('individual_prefix', $params['prefix']);
    $params['prefix_id'] = $params['prefix'];
    if ($params['prefix'] == 'Herr') {
      $params['gender_id'] = 2; // male
    } elseif ($params['prefix'] == 'Frau') {
      $params['gender_id'] = 1; // female
    }
  }

  // match contact using XCM
  $contact_match = civicrm_api3('Contact', 'getorcreate', $params);
  $contact_id = $contact_match['id'];
  $result['id'] = $contact_id;

  // resolve campaign ID
  if (empty($params['campaign_id']) && !empty($params['campaign'])) {
    $campaign = civicrm_api3('Campaign', 'getsingle', array('external_identifier' => $params['campaign']));
    $params['campaign_id'] = $campaign['id'];
    unset($params['campaign']);
  }

  // process email: if the email doesn't exist with the contact -> create
  if (!empty($params['email'])) {
    $contact_emails = civicrm_api3('Email', 'get', array(
      'contact_id'   => $contact_id,
      'email'        => $params['email'],
      'option.limit' => 2));
    if ($contact_emails['count'] == 0) {
      // email is not present -> create
      civicrm_api3('Email', 'create', array(
        'contact_id'       => $contact_id,
        'email'            => $params['email'],
        'is_primary'       => 1,
        'is_bulkmail'      => empty($params['newsletter']) ? 0 : 1,
        'location_type_id' => 1 // TODO: which location type?
        ));
    }
  }

  // find petition
  if (empty($params['petition_id'])) {
    if (empty($params['campaign_id'])) {
      return civicrm_api3_create_error("Unable to identify campaign");
    }

    // indetify/load petition based on the campaign
    $petition = civicrm_api3('Survey', 'getsingle', array(
      'bypass_confirm'   => '1',
      'campaign_id'      => $params['campaign_id'],
      'activity_type_id' => CRM_Core_OptionGroup::getValue('activity_type', 'Petition Signature')
      ));
  } else {
    // simply load the petition
    $petition = civicrm_api3('Survey', 'getsingle', array('id' => (int) $params['petition_id']));
  }

  // TODO: check if not signed already
  // TODO: add to petition group?

  // create signature activity
  civicrm_api3('Activity', 'create', array(
    'source_contact_id'   => $contact_id,
    'activity_type_id'    => CRM_Core_OptionGroup::getValue('activity_type', 'Petition Signature'),
    'status_id'           => CRM_Core_OptionGroup::getValue('activity_status', 'Completed'),
    'target_contact_id'   => $contact_id,
    'source_record_id'    => $petition['id'],
    'subject'             => $petition['title'],
    'campaign_id'         => $petition['campaign_id'],
    'activity_date_time'  => date('YmdHis')
  ));


  // create result
  if (!empty($params['sequential'])) {
    return civicrm_api3_create_success(array($result));
  } else {
    return civicrm_api3_create_success(array($contact_id => $result));
  }
}

/**
 * Adjust Metadata for Payment action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_engage_signpetition_spec(&$params) {
  // CONTACT BASE
  $params['contact_type'] = array(
    'name'         => 'contact_type',
    'api.default'  => 'Individual',
    'title'        => 'Contact Type',
    );
  $params['first_name'] = array(
    'name'         => 'first_name',
    'api.required' => 0,
    'title'        => 'First Name',
    );
  $params['last_name'] = array(
    'name'         => 'last_name',
    'api.required' => 0,
    'title'        => 'Last Name',
    );
  $params['email'] = array(
    'name'         => 'email',
    'api.required' => 0,
    'title'        => 'Email',
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
  $params['petition_id'] = array(
    'name'         => 'petition_id',
    'api.required' => 0,
    'title'        => 'CiviCRM Petition ID',
    'description'  => 'Overwrites "campaign" and "campaign_id"',
    );
}
