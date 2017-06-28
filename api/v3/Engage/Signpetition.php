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
  if (   (empty($params['bpk']))
      && (empty($params['first_name']) || empty($params['email']))
      && (empty($params['last_name']) || empty($params['email']))
      && (empty($params['first_name']) || empty($params['last_name'])  || empty($params['postal_code'])  || empty($params['street_address']))
      ) {
    // we don not have enough information to match/create the contact
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
  $params['check_permissions'] = 0;
  $contact_match = civicrm_api3('Contact', 'getorcreate', $params);
  $contact_id = $contact_match['id'];
  $result['id'] = $contact_id;

  // resolve campaign ID
  if (empty($params['campaign_id']) && !empty($params['campaign'])) {
    $campaign = civicrm_api3('Campaign', 'getsingle', array(
      'external_identifier' => $params['campaign'],
      'check_permissions'   => 0));
    $params['campaign_id'] = $campaign['id'];
    unset($params['campaign']);
  }

  // process email: if the email doesn't exist with the contact -> create
  if (!empty($params['email'])) {
    $contact_emails = civicrm_api3('Email', 'get', array(
      'check_permissions' => 0,
      'contact_id'        => $contact_id,
      'email'             => $params['email'],
      'option.limit'      => 2));
    if ($contact_emails['count'] == 0) {
      // email is not present -> create
      civicrm_api3('Email', 'create', array(
        'check_permissions' => 0,
        'contact_id'        => $contact_id,
        'email'             => $params['email'],
        'is_primary'        => 1,
        'location_type_id'  => 1 // TODO: which location type?
        ));
    }
  }

  // GP-463: "für Group Donation Info soll jeder Eintrag via signpetition mit email automatisch angemeldet werden"
  $newsletter_group = civicrm_api3('Group', 'getsingle', array(
    'check_permissions' => 0,
    'title'             => 'Donation Info',
    ));
  civicrm_api3('GroupContact', 'create', array(
    'check_permissions' => 0,
    'contact_id'        => $contact_id,
    'group_id'          => $newsletter_group['id']));

  // find petition
  if (empty($params['petition_id'])) {
    if (empty($params['campaign_id'])) {
      return civicrm_api3_create_error("Unable to identify campaign");
    }

    // indetify/load petition based on the campaign
    $petition = civicrm_api3('Survey', 'getsingle', array(
      'check_permissions' => 0,
      'bypass_confirm'    => '1',
      'campaign_id'       => $params['campaign_id'],
      'activity_type_id'  => CRM_Core_OptionGroup::getValue('activity_type', 'Petition Signature')
      ));
  } else {
    // simply load the petition
    $petition = civicrm_api3('Survey', 'getsingle', array(
      'id'                => (int) $params['petition_id'],
      'check_permissions' => 0));
  }

  // TODO: check if not signed already
  // TODO: add to petition group?

  // create signature activity
  civicrm_api3('Activity', 'create', array(
    'check_permissions'   => 0,
    'source_contact_id'   => $contact_id,
    'activity_type_id'    => CRM_Core_OptionGroup::getValue('activity_type', 'Petition Signature'),
    'status_id'           => CRM_Core_OptionGroup::getValue('activity_status', 'Completed'),
    'medium_id'           => $params['medium_id'],
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
  $params['birth_date'] = array(
    'name'         => 'birth_date',
    'api.required' => 0,
    'title'        => 'Birth Date',
    );
  $params['bpk'] = array(
    'name'         => 'bpk',
    'api.required' => 0,
    'title'        => 'bereichsspezifische Personenkennzeichen (AT)',
    );
  $params['phone'] = array(
    'name'         => 'phone',
    'api.required' => 0,
    'title'        => 'Phone',
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
  $params['medium_id'] = array(
    'name'         => 'medium_id',
    'api.required' => 0,
    'title'        => 'CiviCRM Medium ID',
    'description'  => 'see results of Engage.getmedia',
    );
  $params['petition_id'] = array(
    'name'         => 'petition_id',
    'api.required' => 0,
    'title'        => 'CiviCRM Petition ID',
    'description'  => 'Overwrites "campaign" and "campaign_id"',
    );

  // CONTACT ADDRESS
  $params['street_address'] = array(
    'name'         => 'street_address',
    'api.required' => 0,
    'title'        => 'Street and house number',
    );
  $params['postal_code'] = array(
    'name'         => 'postal_code',
    'api.required' => 0,
    'title'        => 'Postal Code',
    );
  $params['city'] = array(
    'name'         => 'city',
    'api.required' => 0,
    'title'        => 'City',
    );
  $params['country'] = array(
    'name'         => 'country',
    'api.required' => 0,
    'title'        => 'Country',
    );
}
