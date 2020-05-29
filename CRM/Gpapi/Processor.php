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
      // map OptionValue labels to names
      $params['prefix'] = str_replace(
        ['Herr', 'Frau'],
        ['Mr.', 'Ms.'],
        $params['prefix']
      );
      $params['prefix_id'] = CRM_Core_PseudoConstant::getKey(
        'CRM_Contact_BAO_Contact',
        'prefix_id',
        $params['prefix']
      );
      unset($params['prefix']);
    }

    // map prefix to gender
    if (!empty($params['prefix_id']) && empty($params['gender_id'])) {
      if ($params['prefix_id'] == 3) {
        $params['gender_id'] = 2; // male
      }
      elseif ($params['prefix_id'] == 2) {
        $params['gender_id'] = 1; // female
      }
    }

    if (!empty($params['gender_id']) && empty($params['prefix_id']) && empty($params['prefix'])) {
      $genderPrefixMap = Civi::settings()->get('gpapi_gender_to_prefix_map');
      if (isset($genderPrefixMap[$params['gender_id']])) {
        $params['prefix_id'] = CRM_Core_PseudoConstant::getKey(
          'CRM_Contact_BAO_Contact',
          'prefix_id',
          $genderPrefixMap[$params['gender_id']]
        );
        unset($params['prefix']);
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
        if ($params['country_id'] == '1014' || strtoupper($params['country_id']) == 'AT') {
          // if this is Austria use PostcodeAT::getATstate
          try {
            // compile query
            $state_query = array(
              'plznr' => trim($params['postal_code']));
            if (!empty($params['city'])) {
              $state_query['ortnam'] = trim($params['city']);
            }
            if (!empty($params['street_address'])) {
              // street has to be stripped of numbers
              if (preg_match('#^(?P<streetname>[A-Za-z-]+)#', trim($params['street_address']), $matches)) {
                $state_query['stroffi'] = $matches['streetname'];
              }
            }
            $result = civicrm_api3('PostcodeAT', 'getatstate', $state_query);
            if (!empty($result['id'])) {
              $params['state_province_id'] = $result['id'];
            }
          } catch (Exception $e) {
            // lookup didn't work
          }

        } else {
          // not AT? try to use PostcodeAT::getstate
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
    }

    // make sure only complete addresses (street+city+ZIP)
    //  are submitted (see GP-1161)
    foreach (self::$address_attributes as $any_attribute) {
      if (array_key_exists($any_attribute, $params)) {
        // submission contains an address attribute -
        // check if all required ones are there, too:
        foreach (self::$required_address_attributes as $required_attribute) {
          if (empty($params[$required_attribute])) {
            // one of the required atrributes is missing! remove all!!
            CRM_Core_Error::debug_log_message("Incomplete address, missing {$required_attribute}. Stripping address data.");
            foreach (self::$address_attributes as $remove_attribute) {
              if (array_key_exists($remove_attribute, $params)) {
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
      $params['phone'] = self::fixPhoneFormat($params['phone']);
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

    self::unsetEmpty($params);
  }

  /**
   * Unset parameters with empty values to prevent irrelevant XCM diffs
   *
   * @param $params
   */
  public static function unsetEmpty(&$params) {
    $unsetListKeys = [
      'contact_type', 'first_name', 'last_name', 'email', 'phone', 'prefix_id',
      'gender_id', 'birth_date', 'street_address', 'postal_code', 'city',
      'country_id',
    ];
    foreach ($unsetListKeys as $key) {
      if (empty($params[$key])) {
        unset($params[$key]);
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
   * Set "Opt Out" and "do not email" contact option to "0"
   */
  public static function enableSubscription($contact_id) {
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

  /**
   * Fix common phone formatting errors
   *
   * @param $phone
   *
   * @return string fixed phone number
   */
  public static function fixPhoneFormat($phone) {
    if (substr($phone, 0, 2) == '43') {
      $phone = '+' . $phone;
    }
    return $phone;
  }

  /**
   * Extract UTM tracking data from $params
   *
   * @param array $params API parameters
   *
   * @return array all non-empty UTM tracking parameters
   */
  public static function extractUTMData(array $params) {
    $utm_keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];
    $utm_params = [];
    foreach ($utm_keys as $utm_key) {
      if (!empty($params[$utm_key])) {
        $utm_params[$utm_key] = $params[$utm_key];
      }
    }
    return $utm_params;
  }

  /**
   * Updates Activity with UTM Tracking Parameters
   *
   * @param array $params
   * @param $activity_id
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function updateActivityWithUTM(array $params, $activity_id) {
    $utm_params = self::extractUTMData($params);
    if (count($utm_params) > 0) {
      $utm_params['id'] = $activity_id;
      CRM_Gpapi_Processor::resolveCustomFields($utm_params, ['utm']);
      civicrm_api3('Activity', 'create', $utm_params);
    }
  }

  /**
   * Resolves a CiviCRM contact ID by
   *  "identity tracker extension" (if installed).
   *
   * @param $contact_id
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function identifyContactID(&$contact_id) {
    if (function_exists('identitytracker_civicrm_install')) {
      // identitytracker is enabled
      $contacts = civicrm_api3('Contact', 'findbyidentity', array(
        'identifier_type' => 'internal',
        'identifier'      => $contact_id));
      if ($contacts['count'] == 1) {
        $contact_id = $contacts['id'];
        return;
      }
      $contact_id = 0;
      return;
    }
  }

  /**
   * Set param contact id if it is found by contact hash
   *
   * @param $contact_data
   *
   * @return bool
   */
  public static function setContactIdByHash(&$contact_data) {
    if (!empty($contact_data['hash'])) {
      try {
        $contact_by_hash = civicrm_api3('Contact', 'getsingle', [
          'hash' => $contact_data['hash'],
        ]);
      } catch (CiviCRM_API3_Exception $e) {
        return FALSE;
      }

      $contact_data['id'] = $contact_by_hash['id'];
    }

    return TRUE;
  }

  /**
   * Update contact.
   * Set param contact id if it is found by contact 'bpk_extern'
   *
   * @param $contact_data
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function updateContactByBpk(&$contact_data) {
    if (empty($contact_data['bpk'])) {
      return;
    }

    $bpk_extern_field_id = civicrm_api3('CustomField', 'getvalue', [
      'return' => 'id',
      'custom_group_id' => 'bpk',
      'name' => 'bpk_extern',
    ]);
    $name = 'custom_' . $bpk_extern_field_id;
    $contacts = civicrm_api3('Contact', 'get', [
      'return' => ['id'],
      $name => $contact_data['bpk'],
      'options' => ['limit' => 0],
    ]);

    if ($contacts['count'] < 1) {
      return;
    }

    if (!empty($contact_data['first_name'])) {
      $update_params['first_name'] = $contact_data['first_name'];
    }
    if (!empty($contact_data['last_name'])) {
      $update_params['last_name'] = $contact_data['last_name'];
    }
    if (!empty($contact_data['birth_date'])) {
      $update_params['birth_date'] = $contact_data['birth_date'];
    }

    foreach ($contacts['values'] as $value) {
      $update_params['id'] = $value['id'];
      civicrm_api3('Contact', 'create', $update_params);
    }
    $contact_data['id'] = min($contacts['values'])['id'];
  }

}
