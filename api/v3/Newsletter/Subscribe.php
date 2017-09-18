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
 * Process Newsletter newsletter subscription
 *
 * @param see specs below (_civicrm_api3_newsletter_subscribe_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_newsletter_subscribe($params) {
  CRM_Gpapi_Processor::preprocessCall($params, 'Newsletter.subscribe');
  $result = array();

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

  $subscribed = FALSE;

  // Subscribe to "Donation Info"
  if (!empty($params['donation_info']) && strtolower($params['donation_info']) != 'no') {
    $donation_info_group = civicrm_api3('Group', 'getsingle', array(
      'check_permissions' => 0,
      'title'             => 'Donation Info',
      ));
    civicrm_api3('GroupContact', 'create', array(
      'check_permissions' => 0,
      'contact_id'        => $contact_id,
      'group_id'          => $donation_info_group['id']));
    $subscribed = TRUE;
  }

  // Subscribe to "Group Community NL"
  if (!empty($params['newsletter']) && strtolower($params['newsletter']) != 'no') {
    $newsletter_group = civicrm_api3('Group', 'getsingle', array(
      'check_permissions' => 0,
      'title'             => 'Community NL'));
    civicrm_api3('GroupContact', 'create', array(
      'check_permissions' => 0,
      'contact_id'        => $contact_id,
      'group_id'          => $newsletter_group['id']));
    $subscribed = TRUE;
  }

  // remove "Opt Out" and "do not email"
  if ($subscribed) {
    $contact = civicrm_api3('Contact', 'getsingle', array(
      'check_permissions' => 0,
      'id'                => $contact_id,
      'return'            => 'do_not_email,is_opt_out'));
    if (!empty($contact['do_not_email']) || !empty($contact['is_opt_out'])) {
      civicrm_api3('Contact', 'create', array(
        'check_permissions' => 0,
        'id'                => $contact_id,
        'is_opt_out'        => 0,
        'do_not_email'      => 0));
    }
  }


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
function _civicrm_api3_newsletter_subscribe_spec(&$params) {
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
  $params['prefix'] = array(
    'name'         => 'prefix',
    'api.required' => 0,
    'title'        => 'Prefix',
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
    'api.required' => 1,
    'title'        => 'Email',
    );
  $params['campaign'] = array(
    'name'         => 'campaign',
    'api.required' => 0,
    'title'        => 'CiviCRM Campaign (external identifier)',
    );

  // NEWSLETTER
  $params['newsletter'] = array(
    'name'         => 'newsletter',
    'api.default'  => '0',
    'title'        => 'Sign up for community newsletter',
    );

  $params['donation_info'] = array(
    'name'         => 'donation_info',
    'api.default'  => '0',
    'title'        => 'Sign up for donation info mailing group',
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
