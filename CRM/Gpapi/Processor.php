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

use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;

/**
 * Common functions for GP API
 */
class CRM_Gpapi_Processor {

  // static list of address attributes
  protected static $address_attributes = array('street_address', 'postal_code', 'city', 'state_province_id', 'country', 'country_id', 'supplemental_address_1', 'supplemental_address_2', 'supplemental_address_3');
  protected static $required_address_attributes = array('street_address', 'postal_code', 'city');


  /**
   * generic preprocessor for every call
   */
  public static function preprocessCall($params, $log_id = NULL) {
    self::fixAPIUser();
    if ($log_id) {
      CRM_Core_Error::debug_log_message("{$log_id}: " . json_encode($params));
    }
  }


  /**
   * common preprocessing for contact data,
   * i.e. phone, email, address and contact base data
   */
  public static function preprocessContactData(&$params) {
    // prepare data: prefix
    if (empty($params['prefix_id']) && !empty($params['prefix'])) {
      $params['prefix_id'] = CRM_Core_OptionGroup::getValue('individual_prefix', $params['prefix']);
      if ($params['prefix'] == 'Herr') {
        $params['gender_id'] = 2; // male
      } elseif ($params['prefix'] == 'Frau') {
        $params['gender_id'] = 1; // female
      }
    }

    // country_id needs to be set for XCM
    if (empty($params['country_id']) && !empty($params['country'])) {
      if (is_numeric($params['country'])) {
        $params['country_id'] = $params['country'];
      } elseif (strlen($params['country']) == 2) {
        // country_id also accepts ISO codes
        $params['country_id'] = strtoupper($params['country']);
      } else {
        $country_search = civicrm_api3('Country', 'get', array(
          'check_permissions'   => 0,
          'name'                => $params['country']));
        if (!empty($country_search['id'])) {
          $params['country_id'] = $country_search['id'];
        }
      }
    }

    // see if we should look up the state (if postcode-AT is installed)
    //  see GP-736
    if (empty($params['state_province_id']) && function_exists('postcodeat_civicrm_config')) {
      // make sure we have the required attributes...
      if (!empty($params['country_id']) && !empty($params['postal_code'])) {
        // and then query the postal code
        try {
          $result = civicrm_api3('PostcodeAT', 'getstate', array(
            'country_id'  => $params['country_id'],
            'postal_code' => $params['postal_code']));
          if (!empty($result['id'])) {
            $params['state_province_id'] = $result['id'];
          }
        } catch (Exception $e) {
          // lookup didn't work
        }
      }
    }

    // make sure only complete addresses (street+city+ZIP)
    //  are submitted (see GP-1161)
    foreach (self::$address_attributes as $any_attribute) {
      if (!empty($params[$any_attribute])) {
        // submission contains an address attribute -
        // check if all required ones are there, too:
        foreach (self::$required_address_attributes as $required_attribute) {
          if (empty($params[$required_attribute])) {
            // one of the required atrributes is missing! remove all!!
            CRM_Core_Error::debug_log_message("Incomplete address, missing {$required_attribute}. Stripping address data.");
            foreach (self::$address_attributes as $remove_attribute) {
              if (isset($params[$remove_attribute])) {
                unset($params[$remove_attribute]);
              }
            }
            break 2;
          }
        }
        break;
      }
    }

    // normalise phone
    if (!empty($params['phone'])) {
      try { // try to normalise phone
        $include_file = dirname( __FILE__ ) . '/../../../com.cividesk.normalize/packages/libphonenumber/PhoneNumberUtil.php';
        if (file_exists($include_file)) {
          require_once $include_file;
          $phoneUtil = PhoneNumberUtil::getInstance();
          $phoneProto = $phoneUtil->parse($params['phone'], 'AT');
          if ($phoneUtil->isValidNumber($phoneProto)) {
            $params['phone'] = $phoneUtil->format($phoneProto, PhoneNumberFormat::INTERNATIONAL);
          } else {
            // remove invlid phones
            CRM_Core_Error::debug_log_message("GPAPI: Removed invalid phone number '{$params['phone']}'");
            unset($params['phone']);
          }
        }
      } catch (Exception $e) {
        CRM_Core_Error::debug_log_message("GPAPI: Exception when formatting phone number: " . $e->getMessage());
      }
    }
  }

  /**
   * resolve the campaign_id field
   */
  public static function resolveCampaign(&$params) {
    if (empty($params['campaign_id']) && !empty($params['campaign'])) {
      $campaign = civicrm_api3('Campaign', 'getsingle', array(
        'check_permissions'   => 0,
        'external_identifier' => $params['campaign']));
      $params['campaign_id'] = $campaign['id'];
      unset($params['campaign']);
    }
  }

  /**
   * Use the Extended Contact Matcher (XCM) to get a valid contact
   */
  public static function getOrCreateContact($params) {
    $params['check_permissions'] = 0;
    $contact_match = civicrm_api3('Contact', 'getorcreate', $params);
    return $contact_match['id'];
  }

  /**
   * Store the email (if given) with the contact,
   * unless it's already there
   */
  public static function storeEmail($contact_id, $params) {
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
          'is_bulkmail'       => empty($params['newsletter']) ? 0 : 1,
          'location_type_id'  => 1 // TODO: which location type?
          ));
      }
    }
  }


  /**
   * Store the phone (if given) with the contact,
   * unless it's already there
   */
  public static function storePhone($contact_id, $params) {
    if (!empty($params['phone'])) {
      $contact_phones = civicrm_api3('Phone', 'get', array(
        'check_permissions' => 0,
        'contact_id'        => $contact_id,
        'phone'             => $params['phone'],
        'option.limit'      => 2));
      if ($contact_phones['count'] == 0) {
        // phone is not present -> create
        civicrm_api3('Phone', 'create', array(
          'check_permissions' => 0,
          'contact_id'        => $contact_id,
          'phone'             => $params['phone'],
          'is_primary'        => 1,
          'location_type_id'  => 1, // TODO: which location type?
          'phone_type_id'     => 1 // TODO: which phone type?
          ));
      }
    }
  }

  /**
   * Store the phone (if given) with the contact,
   * unless it's already there
   */
  public static function addToGroup($contact_id, $group_name) {
    $selected_group = civicrm_api3('Group', 'getsingle', array(
      'check_permissions' => 0,
      'title'             => $group_name));
    civicrm_api3('GroupContact', 'create', array(
      'check_permissions' => 0,
      'contact_id'        => $contact_id,
      'group_id'          => $selected_group['id']));
  }


  /**
   * internal function to replace keys in the data
   * with the appropriate custom_XX notation.
   */
  public static function resolveCustomFields(&$data, $customgroups) {
    $custom_fields = civicrm_api3('CustomField', 'get', array(
      'custom_group_id' => array('IN' => $customgroups),
      'option.limit'    => 0,
      'return'          => 'id,name,is_active'
      ));

    // compile indexed list
    $field_list = array();
    foreach ($custom_fields['values'] as $custom_field) {
      $field_list[$custom_field['name']] = $custom_field;
    }

    // replace stuff
    foreach (array_keys($data) as $key) {
      if (isset($field_list[$key])) {
        $custom_key = 'custom_' . $field_list[$key]['id'];
        $data[$custom_key] = $data[$key];
        unset($data[$key]);
      }
    }
  }


  /**
   * Make sure the current user exists
   */
  public static function fixAPIUser() {
    // see https://github.com/CiviCooP/org.civicoop.apiuidfix
    $session = CRM_Core_Session::singleton();
    $userId = $session->get('userID');
    if (empty($userId)) {
      $valid_user = FALSE;

      // Check and see if a valid secret API key is provided.
      $api_key = CRM_Utils_Request::retrieve('api_key', 'String', $store, FALSE, NULL, 'REQUEST');
      if (!$api_key || strtolower($api_key) == 'null') {
        $session->set('userID', 2);
      }

      $valid_user = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $api_key, 'id', 'api_key');

      // If we didn't find a valid user, die
      if (!empty($valid_user)) {
        //now set the UID into the session
        $session->set('userID', $valid_user);
      }
    }
  }
}

