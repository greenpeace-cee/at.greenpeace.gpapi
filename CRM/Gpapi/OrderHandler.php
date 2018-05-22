<?php
/*-------------------------------------------------------+
| Greenpeace.at API                                      |
| Copyright (C) 2018 SYSTOPIA                            |
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
 * Handler for WebShop Orders
 *
 * @see https://redmine.greenpeace.at/issues/1588
 */
class CRM_Gpapi_OrderHandler {
  // defaults
  public static $activity_type_webshop_order   = 75; // test: 90
  public static $activity_status_webshop_order = 1;  // Scheduled

  /** start of ID range for 'fake petitions' */
  public static $order_petition_offset = 300000;

  /**
   * Add the 'fake' cases to the getPetitions call
   */
  public static function addOrders(&$get_petition_result) {
    // load order types
    $order_types = civicrm_api3('OptionValue', 'get', array(
      'check_permissions' => 0,
      'option_group_id'   => 'order_type',
      'option.limit'      => 0,
      'return'            => 'value,label',
    ));

    // add all order types as options
    foreach ($order_types['values'] as $order_type) {
      $get_petition_result['values'][] = array(
        'id'             => $order_type['value'] + self::$order_petition_offset,
        'is_active'      => 1,
        'is_default'     => 0,
        'is_share'       => 0,
        'bypass_confirm' => 0,
        'title'          => "Webshop Order {$order_type['label']}",
      );
    }
  }

  /**
   * Check if the given petition ID is really a CiviCase
   */
  public static function isActivity($petition_id) {
    return $petition_id > self::$order_petition_offset
        && $petition_id < self::$order_petition_offset + 99999;
  }

  /**
   * Will start a case given the fake petition ID
   */
  public static function petitionCreateWebshopOrder($fake_petition_id, $contact_id, $params) {
    $order_type_id = (int) ($fake_petition_id - self::$order_petition_offset);
    if ($order_type_id <= 0) {
      throw new Exception("Bad (fake) petition ID: {$fake_petition_id}");
    }

    try {
      $order_type = civicrm_api3('OptionValue', 'getsingle', array(
        'check_permissions' => 0,
        'option_group_id'   => 'order_type',
        'value'             => $order_type_id
      ));
    } catch (Exception $e) {
      $order_type = array(
        'value' => $order_type_id,
        'label' => 'Unkown Type'
      );
    }

    // set base fields
    $params['check_permissions'] = 0;
    $params['source_contact_id'] = CRM_Core_Session::singleton()->getLoggedInContactID();
    $params['target_contact_id'] = $contact_id;
    $params['activity_type_id']  = self::$activity_type_webshop_order;
    $params['status_id']         = self::$activity_status_webshop_order;
    $params['subject']           = "Engage Order {$order_type['label']}";
    $params['order_type']        = $order_type['value'];
    $params['order_count']       = 1;
    $params['free_order']        = 1;

    CRM_Gpapi_Processor::resolveCustomFields($params, array('webshop_information'));

    return civicrm_api3('Activity', 'create', $params);
  }
}

