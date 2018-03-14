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
  CRM_Gpapi_Processor::preprocessCall($params, 'Engage.signpetition');
  $result = array();

  // check input
  if (   (empty($params['bpk']))
      && (empty($params['phone']))
      && (empty($params['first_name']) || empty($params['email']))
      && (empty($params['last_name']) || empty($params['email']))
      && (empty($params['first_name']) || empty($params['last_name'])  || empty($params['postal_code'])  || empty($params['street_address']))
      ) {
    // we don not have enough information to match/create the contact
    return civicrm_api3_create_error("Insufficient contact data");
  }

  // make sure phone-only works
  if (!empty($params['phone']) && empty($params['display_name'])) {
    if (empty($params['email']) && (empty($params['first_name']) || empty($params['last_name']))) {
      $params['display_name'] = $params['phone'];
    }
  }

  CRM_Gpapi_Processor::preprocessContactData($params);
  CRM_Gpapi_Processor::resolveCampaign($params);
  $contact_id = CRM_Gpapi_Processor::getOrCreateContact($params);
  $result['id'] = $contact_id;

  // store data
  CRM_Gpapi_Processor::storeEmail($contact_id, $params);
  CRM_Gpapi_Processor::storePhone($contact_id, $params);

  // GP-463: "der Group "Donation Info" Eintrag soll immer gesetzt werden..."
  CRM_Gpapi_Processor::addToGroup($contact_id, 'Donation Info');

  // GP-463: "...aber der "Group Community NL" Eintrag soll nur bei übergebenem newsletter=1 Wert gesetzt werden."
  if (!empty($params['newsletter']) && strtolower($params['newsletter']) != 'no') {
    CRM_Gpapi_Processor::addToGroup($contact_id, 'Community NL');
  }

  // simply load the petition
  $petition = civicrm_api3('Survey', 'getsingle', array(
    'id'                => (int) $params['petition_id'],
    'check_permissions' => 0));

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
    'campaign_id'         => $params['campaign_id'],
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
    'api.default'  => '6',
    'title'        => 'CiviCRM Medium ID',
    'description'  => 'see results of Engage.getmedia',
    );
  $params['petition_id'] = array(
    'name'         => 'petition_id',
    'api.required' => 1,
    'title'        => 'CiviCRM Petition ID',
    'description'  => 'ID of the petition to sign',
    );

  // NEWSLETTER
  $params['newsletter'] = array(
    'name'         => 'newsletter',
    'api.default'  => '0',
    'title'        => 'Sign up for newsletter?',
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
