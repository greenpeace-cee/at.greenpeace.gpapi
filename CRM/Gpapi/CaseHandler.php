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
 * Adding case inteface to the GP-API by shoe-horning
 *  extra functionality into the Engage.signpetition API
 *  by adding 'fake' petition IDs to be treated as cases
 *
 * @see https://redmine.greenpeace.at/issues/1413
 */
class CRM_Gpapi_CaseHandler {

  /** start of ID range for 'fake petitions' */
  public static $case_petition_offset = 100000;

  /**
   * Add the 'fake' cases to the getPetitions call
   */
  public static function addCases(&$get_petition_result) {
    // get cases
    $case_types = civicrm_api3('CaseType', 'get', array(
      'return'    => 'title,id',
      'is_active' => 1
    ));

    // error_log("PRE  " . json_encode($get_petition_result));

    // add to result
    $get_petition_result['count'] += $case_types['count'];
    foreach ($case_types['values'] as $case_type) {
      $petition_id = $case_type['id'] + self::$case_petition_offset;
      $get_petition_result['values'][] = array(
        'id'             => $petition_id,
        'is_active'      => 1,
        'is_default'     => 0,
        'is_share'       => 0,
        'bypass_confirm' => 0,
        'title'          => $case_type['title'],
      );
      // not set:
      // "activity_type_id": "32",
      // "campaign_id": "19",
      // "created_date": "2018-01-10 07:48:34",
      // "created_id": "23",
      // "last_modified_date": "2018-02-26 13:53:41",
      // "last_modified_id": "23",
    }

    // error_log("POST " . json_encode($get_petition_result));
  }

  /**
   * Check if the given petition ID is really a CiviCase
   */
  public static function isCase($petition_id) {
    return $petition_id > self::$case_petition_offset;
  }

  /**
   * Will start a case given the fake petition ID
   */
  public static function apiStartCase($fake_petition_id, $contact_id, $params) {
    $case_type_id = (int) ($fake_petition_id - self::$case_petition_offset);
    if (!$case_type_id) {
      throw new Exception("Bad (fake) petition ID: {$fake_petition_id}");
    }

    // create subject
    if (empty($params['medium_id'])) {
      $subject = "Case (Engage)";
    } else {
      $medium = civicrm_api3('OptionValue', 'getvalue', array(
      'return'          => 'label',
      'option_group_id' => "encounter_medium",
      'value'           => $params['medium_id']));
      $subject = "Case (Engage/{$medium})";
    }

    // create a new case
    return civicrm_api3('Case', 'create', array(
      'contact_id'   => $contact_id,
      'case_type_id' => $case_type_id,
      'subject'      => $subject));
  }
}

