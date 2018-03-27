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
 * Adding activity inteface to the GP-API by shoe-horning
 *  extra functionality into the Engage.signpetition API
 *  by adding 'fake' petition IDs to be treated as cases
 *
 * @see https://redmine.greenpeace.at/issues/1413
 * @see https://redmine.greenpeace.at/issues/1588
 */
class CRM_Gpapi_ActivityHandler {

  /** start of ID range for 'fake petitions' */
  public static $activity_petition_offset = 200000;

  protected static $allowed_activities = array(
      1 => 'TEST - REMOVE',
    105 => 'Contact Update',
     75 => 'Webshop Order'
  );

  /**
   * Add the 'fake' cases to the getPetitions call
   */
  public static function addActivities(&$get_petition_result) {
    // just add hard-coded activity values
    foreach (self::$allowed_activities as $activity_type_id => $activity_title) {
      $get_petition_result['values'][] = array(
        'id'             => $activity_type_id + self::$activity_petition_offset,
        'is_active'      => 1,
        'is_default'     => 0,
        'is_share'       => 0,
        'bypass_confirm' => 0,
        'title'          => $activity_title,
      );
    }
  }

  /**
   * Check if the given petition ID is really a CiviCase
   */
  public static function isActivity($petition_id) {
    return $petition_id > self::$activity_petition_offset
        && $petition_id < self::$activity_petition_offset + 99999;
    // strict checking:
    // $activity_type_id = $petition_id - self::$activity_petition_offset;
    // return isset(self::$allowed_activities[$activity_type_id]);
  }

  /**
   * Will start a case given the fake petition ID
   */
  public static function petitionCreateActivity($fake_petition_id, $contact_id, $params) {
    $activity_type_id = (int) ($fake_petition_id - self::$activity_petition_offset);
    if ($activity_type_id <= 0) {
      throw new Exception("Bad (fake) petition ID: {$fake_petition_id}");
    }

    $params['source_contact_id'] = $contact_id;
    $params['target_contact_id'] = $contact_id;
    $params['activity_type_id']  = $activity_type_id;

    return civicrm_api3('Activity', 'create', $params);
  }
}

