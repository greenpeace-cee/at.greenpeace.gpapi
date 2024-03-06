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

use Civi\Core\Event\PostEvent;

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
  public static $reopen_case_type_to_acitivity_id = [
    3 => 87,
  ];

  /**
   * @var array map of closed cases statuses to the status used when reopening
   */
  public static $closedStatuses = [
    2 => 1, // Resolved => Ongoing
    9 => 5, // Rejecter => Enquirer
    4 => 5, // Pre-Enquirer (Pre-Inquirer) => Enquirer
  ];

  /**
   * Add the 'fake' cases to the getPetitions call
   */
  public static function addCases(&$get_petition_result) {
    // get cases
    $case_types = civicrm_api3('CaseType', 'get', [
      'return'    => 'title,id',
      'is_active' => 1
    ]);

    // add to result
    $get_petition_result['count'] += $case_types['count'];
    foreach ($case_types['values'] as $case_type) {
      $petition_id = $case_type['id'] + self::$case_petition_offset;
      $get_petition_result['values'][] = [
        'id'             => $petition_id,
        'is_active'      => 1,
        'is_default'     => 0,
        'is_share'       => 0,
        'bypass_confirm' => 0,
        'title'          => $case_type['title'],
      ];
    }
  }

  public static function caseActivityCallback(array &$params) {
    $utmActivityTypes = civicrm_api3('CustomGroup', 'getvalue', [
      'name'   => 'utm',
      'return' => 'extends_entity_column_value',
    ]);

    return function (PostEvent $event) use (&$params, $utmActivityTypes) {
      if ($event->entity !== 'Activity') return;
      if ($event->action !== 'create') return;

      $activityParams = [ 'id' => $event->object->id ];

      if (isset($params['medium_id'])) {
        $activityParams['medium_id'] = $params['medium_id'];
      }

      $utmData = CRM_Gpapi_Processor::extractUTMData($params);

      if (count($utmData) > 0 && in_array($event->object->activity_type_id, $utmActivityTypes)) {
        CRM_Gpapi_Processor::resolveCustomFields($utmData, ['utm']);
        $activityParams = array_merge($activityParams, $utmData);
      }

      civicrm_api3('Activity', 'create', $activityParams);
    };
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
    $case_type_definition = civicrm_api3('CaseType', 'getvalue', [
      'return' => 'definition',
      'id'     => $params['case_type_id'],
    ]);

    $timeline = NULL;

    $timeline_exists = array_search(
      $params['timeline'],
      array_column($case_type_definition['activitySets'], 'name')
    );

    if ($timeline_exists !== FALSE) {
      $timeline = $params['timeline'];
    }

    if (!$timeline && $params['timeline'] != GPAPI_DEFAULT_TIMELINE) {
      // custom timeline was provided but doesn't exist
      return civicrm_api3_create_error("Case timeline '{$params['timeline']}' does not exist.");
    }

    // generate a default subject
    if (empty($params['subject'])) {
      if (empty($params['medium_id'])) {
        $params['subject'] = "Case (Engage)";
      } else {
        $medium = civicrm_api3('OptionValue', 'getvalue', [
        'return'          => 'label',
        'option_group_id' => "encounter_medium",
        'value'           => $params['medium_id']
        ]);
        $params['subject'] = "Case (Engage/{$medium})";
      }
    }

    // check if this case exists and could/should be re-opened
    //  see GP-1413
    $case_id = self::reopenCase($params);

    if (!$case_id) {
      // create a new case
      $params['check_permissions'] = 0;
      $params['status_id'] = 5; // Enquirer (see https://redmine.greenpeace.at/issues/1586#note-22)
      $case = civicrm_api3('Case', 'create', $params);
      $case_id = $case['id'];
    } else {
      // if this is dealing with an existing case, check if there's a timeline
      // with the requested name + '_existing' and apply that instead
      $modified_timeline_name = $params['timeline'] . '_existing';
      $timeline_exists = array_search(
        $modified_timeline_name,
        array_column($case_type_definition['activitySets'], 'name')
      );
      if ($timeline_exists !== FALSE) {
        $timeline = $modified_timeline_name;
      }
    }

    if (!is_null($timeline)) {
      // add the requested timeline
      civicrm_api3('Case', 'addtimeline', [
        'case_id'  => $case_id,
        'timeline' => $timeline,
      ]);
    }

    // create a reply
    if (!empty($params['sequential'])) {
      return civicrm_api3_create_success([['id' => $case_id]]);
    } else {
      return civicrm_api3_create_success([$case_id => ['id' => $case_id]]);
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
    $existing_cases = civicrm_api3('Case', 'get', [
      'case_type_id' => $params['case_type_id'],
      'contact_id'   => $params['contact_id'],
      'is_deleted'   => 0,
      'return'       => 'id,status_id,contact_id',
      'options'      => [
        'sort'  => 'status_id asc',
        'limit' => 1
      ],
    ]);

    if (empty($existing_cases['count'])) {
      // no case found
      return NULL;
    }
    $case = reset($existing_cases['values']);

    // if it's a closed status -> re-open with corresponding status
    if (array_key_exists($case['status_id'], self::$closedStatuses)) {
      civicrm_api3('Case', 'create', [
        'check_permissions'      => 0,
        'id'                     => $case['id'],
        'status_id'              => self::$closedStatuses[$case['status_id']],
        'track_status_change'    => TRUE, // create status change activity
        'status_change_activity' => [
          'medium_id' => $params['medium_id'],
        ]
      ]);
    }

    return $case['id'];
  }
}

