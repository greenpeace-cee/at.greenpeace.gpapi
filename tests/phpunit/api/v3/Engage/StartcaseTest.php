<?php

use Civi\Api4;

/**
 * @group headless
 */
class api_v3_Engage_StartcaseTest extends api_v3_Engage_EngageTestBase {

  private static $activityTypeIDs;
  private static $encounterMediumIDs;

  public function setUp() {
    parent::setUp();

    self::$activityTypeIDs = [
      'Email'               => self::getOptionValue('activity_type', 'Email'),
      'Open Case'           => self::getOptionValue('activity_type', 'Open Case'),
      'Phone Call'          => self::getOptionValue('activity_type', 'Phone Call'),
      'Ratgeber verschickt' => self::getOptionValue('activity_type', 'Ratgeber verschickt'),
    ];

    self::$encounterMediumIDs = [
      'phone' => self::getOptionValue('encounter_medium', 'phone'),
    ];
  }

  public function testStartCaseBasic() {
    $caseID = $this->callAPISuccess('Engage', 'startcase', [
      'case_type_id' => $this->caseType['id'],
      'contact_id'   => $this->contact['id'],
      'medium_id'    => self::$encounterMediumIDs['phone'],
    ])['id'];

    $case = $this->callAPISuccess('Case', 'getsingle', [ 'id' => $caseID ]);

    $this->assertEquals('Case (Engage/Phone)', $case['subject']);

    $caseContactRoles = array_column($case['contacts'], 'role');
    $clientContactIdx = array_search('Client', $caseContactRoles);
    $caseClient = $case['contacts'][$clientContactIdx];

    $this->assertEquals($this->contact['id'], $caseClient['contact_id']);

    $activities = array_values($this->callAPISuccess('Activity', 'get', [
      'case_id' => $caseID,
      'options' => [
        'sort' => ['activity_date_time asc'],
      ],
    ])['values']);

    $this->assertEquals(3, count($activities));

    $this->assertEquals(
      self::$activityTypeIDs['Open Case'],
      $activities[0]['activity_type_id']
    );

    $this->assertEquals(
      self::$activityTypeIDs['Email'],
      $activities[1]['activity_type_id']
    );

    $this->assertEquals(
      self::$activityTypeIDs['Ratgeber verschickt'],
      $activities[2]['activity_type_id']
    );
  }

  public function testAddUTMFields() {
    $randomID = bin2hex(random_bytes(8));

    $caseID = $this->callAPISuccess('Engage', 'startcase', [
      'case_type_id' => $this->caseType['id'],
      'contact_id'   => $this->contact['id'],
      'utm_campaign' => "utm_campaign_$randomID",
      'utm_content'  => "utm_content_$randomID",
      'utm_medium'   => "utm_medium_$randomID",
      'utm_source'   => "utm_source_$randomID",
      'utm_id'       => "utm_id_$randomID",
      'utm_term'     => "utm_term_$randomID",
    ])['id'];

    $openCaseActivity = Api4\Activity::get(FALSE)
      ->addSelect(
        'utm.utm_campaign',
        'utm.utm_content',
        'utm.utm_medium',
        'utm.utm_term',
        'utm.utm_id',
        'utm.utm_source'
      )
      ->setJoin([
        ['CaseActivity AS ca', 'INNER', NULL, ['ca.activity_id', '=', 'id']],
        ['Case AS c', 'INNER', NULL, ['c.id', '=', 'ca.case_id']],
      ])
      ->addWhere('c.id', '=', $caseID)
      ->addWhere('activity_type_id:name', '=', 'Open Case')
      ->setLimit(1)
      ->execute()
      ->first();

    $this->assertEquals(
      "utm_campaign_$randomID",
      $openCaseActivity['utm.utm_campaign']
    );

    $this->assertEquals(
      "utm_content_$randomID",
      $openCaseActivity['utm.utm_content']
    );

    $this->assertEquals(
      "utm_medium_$randomID",
      $openCaseActivity['utm.utm_medium']
    );

    $this->assertEquals(
      "utm_source_$randomID",
      $openCaseActivity['utm.utm_source']
    );

    $this->assertEquals(
      "utm_id_$randomID",
      $openCaseActivity['utm.utm_id']
    );

    $this->assertEquals(
      "utm_term_$randomID",
      $openCaseActivity['utm.utm_term']
    );
  }

}
