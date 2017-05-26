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
 * @param see specs below (_civicrm_api3_o_s_f_submit_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_o_s_f_submit($params) {
  $error_list = array();
  gpapi_civicrm_fix_API_UID();

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

  // resolve campaign ID
  if (empty($params['campaign_id']) && !empty($params['campaign'])) {
    $campaign = civicrm_api3('Campaign', 'getsingle', array('external_identifier' => $params['campaign']));
    $params['campaign_id'] = $campaign['id'];
    unset($params['campaign']);
  }

  // TODO: process email

  // TODO: process newsletter


  // pass through WebShop orders/donations/contracts
  $pass_through = array('wsorders'  => 'wsorder',
                        'donations' => 'donation',
                        'contracts' => 'contract');
  foreach ($pass_through as $field_name => $action) {
    if (!empty($params[$field_name]) && is_array($params[$field_name])) {
      foreach ($params[$field_name] as $call_data) {
        // set contact ID
        $call_data['contact_id'] = $contact_id;

        // fill campaign_id
        if (empty($call_data['campaign_id']) && !empty($params['campaign_id'])) {
          $call_data['campaign_id'] = $params['campaign_id'];
        }

        // run the sub-call
        try {
          civicrm_api3('OSF', $action, $call_data);
        } catch (Exception $e) {
          $error_list[] = $e->getMessage();
        }
      }
    }
  }

  // create result
  if (!empty($error_list)) {
    return civicrm_api3_create_error($error_list);
  }
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
  $params['campaign'] = array(
    'name'         => 'campaign',
    'api.required' => 0,
    'title'        => 'CiviCRM Campaign (external identifier)',
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
