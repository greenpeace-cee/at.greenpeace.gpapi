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
 * Process OSF (online donation form) submission
 *
 * @param see specs below (_civicrm_api3_o_s_f_submit_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_o_s_f_submit($params) {
  $error_list = array();
  _civicrm_api3_fix_API_UID();

  // check input
  if (   (empty($params['bpk']))
      && (empty($params['first_name']) || empty($params['email']))
      && (empty($params['last_name']) || empty($params['email']))
      && (empty($params['iban']) || empty($params['birth_date']))
      && (empty($params['first_name']) || empty($params['last_name'])  || empty($params['postal_code'])  || empty($params['street_address']))
      ) {
    // we don not have enough information to match/create the contact
    return civicrm_api3_create_error("Insufficient contact data");
  }

  // match contact using XCM
  $contact = civicrm_api3('Contact', 'getorcreate', $params);
  $contact_id = $contact['id'];

  // TODO: process email

  // TODO: process newsletter

  // pass through WebShop orders
  if (!empty($params['wsorders']) && is_array($params['wsorders'])) {
    foreach ($params['wsorders'] as $wsorder) {
      $wsorder['contact_id'] = $contact_id;
      try {
        civicrm_api3('OSF', 'wsorder', $wsorder);
      } catch (Exception $e) {
        $error_list[] = $e->getMessage();
      }
    }
  }

  // pass through dontations
  if (!empty($params['donations']) && is_array($params['donations'])) {
    foreach ($params['donations'] as $donation) {
      $donation['contact_id'] = $contact_id;
      try {
        civicrm_api3('OSF', 'donation', $donation);
      } catch (Exception $e) {
        $error_list[] = $e->getMessage();
      }
    }
  }

  // pass through dontations
  if (!empty($params['contracts']) && is_array($params['contracts'])) {
    foreach ($params['contracts'] as $contract) {
      $contract['contact_id'] = $contact_id;
      try {
        civicrm_api3('OSF', 'contract', $contract);
      } catch (Exception $e) {
        $error_list[] = $e->getMessage();
      }
    }
  }

  // and return the good news (otherwise an Exception would have occurred)
  return civicrm_api3_create_success();
}

/**
 * Adjust Metadata for Payment action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_o_s_f_submit_spec(&$params) {
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
  $params['email'] = array(
    'name'         => 'email',
    'api.required' => 0,
    'title'        => 'Email',
    );
  $params['phone'] = array(
    'name'         => 'phone',
    'api.required' => 0,
    'title'        => 'Phone',
    );
  $params['iban'] = array(
    'name'         => 'iban',
    'api.required' => 0,
    'title'        => 'IBAN (for contact identification only)',
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

  // NEWSLETTER
  $params['newsletter'] = array(
    'name'         => 'newsletter',
    'api.required' => 0,
    'title'        => 'Sign up for newsletter?',
    );

  // PASS-THROUH entities
  $params['wsorders'] = array(
    'name'         => 'wsorders',
    'api.required' => 0,
    'title'        => 'List of orders to be passed on to OSF.wsorder',
    );
  $params['donations'] = array(
    'name'         => 'donations',
    'api.required' => 0,
    'title'        => 'List of donations to be passed on to OSF.donation',
    );
  $params['contracts'] = array(
    'name'         => 'contracts',
    'api.required' => 0,
    'title'        => 'List of contracts to be passed on to OSF.contract',
    );
}


/**
 * Fixed API bug, where activity creation needs a valid userID
 *
 * Copied from https://github.com/CiviCooP/org.civicoop.apiuidfix
 * by Jaap Jansma, CiviCoop
 */
function _civicrm_api3_fix_API_UID() {
  // see https://github.com/CiviCooP/org.civicoop.apiuidfix
  $session = CRM_Core_Session::singleton();
  $userId = $session->get('userID');
  if (empty($userId)) {
    $valid_user = FALSE;

    // Check and see if a valid secret API key is provided.
    $api_key = CRM_Utils_Request::retrieve('api_key', 'String', $store, FALSE, NULL, 'REQUEST');
    if (!$api_key || strtolower($api_key) == 'null') {
      return; // nothing we can do
    }

    $valid_user = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $api_key, 'id', 'api_key');

    // If we didn't find a valid user, die
    if (!empty($valid_user)) {
      //now set the UID into the session
      $session->set('userID', $valid_user);
    }
  }
}

