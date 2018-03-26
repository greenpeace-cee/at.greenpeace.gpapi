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
   * list of case types to be re-opened
   * instead of creating an new one
   * along with the default activity_id
   * @todo: settings page?
   */
  public static $reopen_case_type_to_acitivity_id = array(
      3 => 87,
      // 1 => 1  // TODO: remove test code
    );

  /**
   * Add the 'fake' cases to the getPetitions call
   */
  public static function addCases(&$get_petition_result) {
    // get cases
    $case_types = civicrm_api3('CaseType', 'get', array(
      'return'    => 'title,id',
      'is_active' => 1
    ));

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
    }
  }

  /**
   * Check if the given petition ID is really a CiviCase
   */
  public static function isCase($petition_id) {
    return $petition_id > self::$case_petition_offset
        && $petition_id < self::$case_petition_offset + 99999;
  }

  /**
   * Will start a case given the fake petition ID
   */
  public static function petitionStartCase($fake_petition_id, $contact_id, $params) {
    $case_type_id = (int) ($fake_petition_id - self::$case_petition_offset);
    if (!$case_type_id) {
      throw new Exception("Bad (fake) petition ID: {$fake_petition_id}");
    }

    // pass it on to the case handler
    $params['case_type_id']      = $case_type_id;
    $params['contact_id']        = $contact_id;
    $params['check_permissions'] = 0;

    return civicrm_api3('Engage', 'startcase', $params);
  }


  /**
   * Will start a case given the fake petition ID
   *
   * Expected params:

   */
  public static function startCase($params) {

    // generate a default subject
    if (empty($params['subject'])) {
      if (empty($params['medium_id'])) {
        $params['subject'] = "Case (Engage)";
      } else {
        $medium = civicrm_api3('OptionValue', 'getvalue', array(
        'return'          => 'label',
        'option_group_id' => "encounter_medium",
        'value'           => $params['medium_id']));
        $params['subject'] = "Case (Engage/{$medium})";
      }
    }

    // check if this case exists and could/should be re-opened
    //  see GP-1413
    $case_id = self::reopenCase($params);

    if (!$case_id) {
      // create a new case
      $params['check_permissions'] = 0;
      $case = civicrm_api3('Case', 'create', $params);
      $case_id = $case['id'];

      // not set:
      // "activity_type_id": "32",
      // "campaign_id": "19",
      // "created_date": "2018-01-10 07:48:34",
      // "created_id": "23",
      // "last_modified_date": "2018-02-26 13:53:41",
      // "last_modified_id": "23",
    }

    // create a reply
    if (!empty($params['sequential'])) {
      return civicrm_api3_create_success(array(array('id' => $case_id)));
    } else {
      return civicrm_api3_create_success(array($case_id => array('id' => $case_id)));
    }
  }


  /**
   * re-open an existing case rather than creating a new one,
   *  but first check if:
   *   - this is a case type that should be re-opened
   *   - is there an existing case
   *
   * @return int case id if found and re-openend
   */
  public static function reopenCase($params) {
    // first check if this is one of the types to be re-opened
    if (!isset(self::$reopen_case_type_to_acitivity_id[$params['case_type_id']])) {
      return NULL;
    }

    // find an existing case
    $existing_cases = civicrm_api3('Case', 'get', array(
      'case_type_id' => $params['case_type_id'],
      'contact_id'   => $params['contact_id'],
      'is_deleted'   => 0,
      'return'       => 'id,status_id',
      'options'      => array('sort'  => 'status_id asc',
                              'limit' => 1),
    ));

    if (empty($existing_cases['count'])) {
      // no case found
      return NULL;
    }
    $case = reset($existing_cases['values']);

    // if it's status 2 (closed) -> re-open (status 1)
    if ($case['status_id'] == 2) {
      civicrm_api3('Case', 'create', array(
        'id'        => $case['id'],
        'status_id' => 1
      ));
    }

    // create new activity for the case
    if (empty($params['activity_type_id'])) {
      $params['activity_type_id'] = self::$reopen_case_type_to_acitivity_id[$params['case_type_id']];
    }

    // prepare params
    $params['case_id']   = $case['id'];
    $params['target_id'] = $case['contact_id'];
    $params['status_id'] = 1; // scheduled

    // finally: create activity
    civicrm_api3('Activity', 'create', $params);

    return $case['id'];
  }
}

