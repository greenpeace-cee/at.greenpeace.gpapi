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
 * Process Engage.signpetition calls
 *
 * @param see specs below (_civicrm_api3_engage_signpetition_spec)
 *
 * @return void API result array
 * @access public
 * @throws \Exception
 */
function civicrm_api3_engage_signpetition($params) {
  try {
    return _civicrm_api3_engage_signpetition_process($params);
  } catch (Exception $e) {
    CRM_Gpapi_Error::create('Engage.signpetition', $e, $params);
    throw $e;
  }
}

/**
 * Process Engage.signpetition in single transaction
 *
 * @param $params
 *
 * @return array
 * @throws \Exception
 */
function _civicrm_api3_engage_signpetition_process($params) {
  $tx = new CRM_Core_Transaction();
  try {
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
    if (!empty($params['phone']) && empty($params['display_name'])
        && empty($params['email']) && empty($params['first_name'])
        && empty($params['last_name'])) {
      // if we receive only the phone number, don't create a new contact if the
      // phone number is invalid.
      try {
        // TODO: find a more robust way to do this
        $include_file = dirname( __FILE__ ) . '/../../../../com.cividesk.normalize/packages/libphonenumber/PhoneNumberUtil.php';
        if (file_exists($include_file)) {
          require_once $include_file;
          $phoneUtil = libphonenumber\PhoneNumberUtil::getInstance();
          $phoneProto = $phoneUtil->parse(CRM_Gpapi_Processor::fixPhoneFormat($params['phone']), 'AT');
          if (!$phoneUtil->isValidNumber($phoneProto)) {
            return civicrm_api3_create_error("Invalid phone number");
          }
        }
      } catch (Exception $e) {
        return civicrm_api3_create_error("Invalid phone number");
      }
    }

    if (!empty($params['external_identifier'])) {
      $lock = new CRM_Core_Lock('Engage.signpetition.external_identifier.' . $params['external_identifier'], 60, TRUE);
      $lock->acquire();
      if (!$lock->isAcquired() || CRM_Gpapi_ActivityHandler::countByExternalIdentifier('Petition', $params['external_identifier']) > 0) {
        return civicrm_api3_create_error('Duplicate value for external_identifier');
      }
    }

    CRM_Gpapi_Processor::preprocessContactData($params);
    CRM_Gpapi_Processor::resolveCampaign($params);
    $contact_data = $params;
    // external_identifier in the context of petitions is a petition custom field
    // don't pass it to Contact.create
    if (array_key_exists('external_identifier', $contact_data)) {
      unset($contact_data['external_identifier']);
    }
    $contact_id = CRM_Gpapi_Processor::getOrCreateContact($contact_data);
    $result['id'] = $contact_id;

    // store data
    CRM_Gpapi_Processor::storeEmail($contact_id, $params);
    CRM_Gpapi_Processor::storePhone($contact_id, $params);

    // GP-463: "der Group "Donation Info" Eintrag soll immer gesetzt werden..."
    CRM_Gpapi_Processor::addToGroup($contact_id, 'Donation Info');

    // GP-463: "...aber der "Group Community NL" Eintrag soll nur bei übergebenem newsletter=1 Wert gesetzt werden."
    if (!empty($params['newsletter']) && strtolower($params['newsletter']) != 'no') {
      CRM_Gpapi_Processor::addToGroup($contact_id, 'Community NL');

      //remove "Opt Out" and "do not email"
      CRM_Gpapi_Processor::enableSubscription($contact_id);
    }

    // check if this is a 'fake petition' (and actually a case)
    if (CRM_Gpapi_CaseHandler::isCase($params['petition_id'])) {
      // it is. so let's do that:
      $case = CRM_Gpapi_CaseHandler::petitionStartCase($params['petition_id'], $contact_id, $params);

    // check if this is a 'fake petition' (and actually a webshop order)
    } elseif (CRM_Gpapi_OrderHandler::isActivity($params['petition_id'])) {
      // it is. so let's do that:
      CRM_Gpapi_OrderHandler::petitionCreateWebshopOrder($params['petition_id'], $contact_id, $params);

    // check if this is a 'fake petition' (and actually an activity)
    } elseif (CRM_Gpapi_ActivityHandler::isActivity($params['petition_id'])) {
      // it is. so let's do that:
      CRM_Gpapi_ActivityHandler::petitionCreateActivity($params['petition_id'], $contact_id, $params);

    } else {
      // default behaviour: sign petition:
      // simply load the petition first
      $petition = civicrm_api3('Survey', 'getsingle', array(
        'id'                => (int) $params['petition_id'],
        'check_permissions' => 0));

      // TODO: check if not signed already
      // TODO: add to petition group?

      // remove critical stuff from params
      if (isset($params['id'])) unset($params['id']);

      // API caller may provide fields like petition_dialoger
      CRM_Gpapi_Processor::resolveCustomFields($params, ['petition_information']);

      $activity_date = date('YmdHis');

      if (!empty($params['signature_date'])) {
        $activity_date = $params['signature_date'];
      }

      // create signature activity
      $activity = civicrm_api3('Activity', 'create', array(
        'check_permissions'   => 0,
        'source_contact_id'   => CRM_Core_Session::singleton()->getLoggedInContactID(),
        'activity_type_id'    => $petition['activity_type_id'],
        'status_id'           => CRM_Core_PseudoConstant::getKey(
          'CRM_Activity_BAO_Activity',
          'activity_status',
          'Completed'
        ),
        'medium_id'           => $params['medium_id'],
        'target_contact_id'   => $contact_id,
        'source_record_id'    => $petition['id'],
        'subject'             => $petition['title'],
        'campaign_id'         => $params['campaign_id'],
        'activity_date_time'  => $activity_date,
      ) + $params); // add other params
    }

    if (!empty($activity['id'])) {
      $activity_id = $activity['id'];
    } elseif (!empty($case['id'])) {
      $activity_id = civicrm_api3('Activity', 'getvalue', [
        'return' => 'id',
        'activity_type_id' => 'Open Case',
        'case_id' => $case['id'],
      ]);
    }

    if (!empty($activity_id)) {
      CRM_Gpapi_Processor::updateActivityWithUTM($params, $activity_id);
    }

    // create result
    if (!empty($params['sequential'])) {
      return civicrm_api3_create_success(array($result));
    } else {
      return civicrm_api3_create_success(array($contact_id => $result));
    }
  } catch (Exception $e) {
    $tx->rollback();
    throw $e;
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
  $params['external_identifier'] = [
    'name'         => 'external_identifier',
    'api.required' => 0,
    'title'        => 'External Identifier',
    'description'  => 'Unique identifier of the petition signature in an external system',
  ];
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
  $params['signature_date'] = [
    'name'         => 'signature_date',
    'api.required' => 0,
    'title'        => 'Petition Signature Date',
    'type'         => CRM_Utils_Type::T_TIMESTAMP
  ];

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

  $params['xcm_profile'] = [
    'name'         => 'xcm_profile',
    'api.required' => 0,
    'api.default'  => 'engagement',
    'title'        => 'XCM Profile',
    'description'  => 'XCM profile to be used for contact matching',
  ];

  // UTM fields:
  $params['utm_source'] = [
    'name' => 'utm_source',
    'title' => 'UTM Source',
    'api.required' => 0,
  ];
  $params['utm_medium'] = [
    'name' => 'utm_medium',
    'title' => 'UTM Medium',
    'api.required' => 0,
  ];
  $params['utm_campaign'] = [
    'name' => 'utm_campaign',
    'title' => 'UTM Campaign',
    'api.required' => 0,
  ];
  $params['utm_content'] = [
    'name' => 'utm_content',
    'title' => 'UTM Content',
    'api.required' => 0,
  ];
}
