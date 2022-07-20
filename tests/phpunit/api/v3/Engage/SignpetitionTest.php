<?php

use Civi\Api4;

/**
 * @group headless
 */
class api_v3_Engage_SignpetitionTest extends api_v3_Engage_EngageTestBase {

  private $campaign;
  private $survey;
  private static $encounterMediumIDs;

  public function setUp() {
    parent::setUp();

    $this->campaign = self::createCampaign('TestCampaign');
    $this->survey = self::createSurvey('TestSurvey');

    self::$encounterMediumIDs = [
      'email' => self::getOptionValue('encounter_medium', 'email'),
      'phone' => self::getOptionValue('encounter_medium', 'phone'),
    ];
  }

  public function testSignPetition() {
    $randomID = bin2hex(random_bytes(8));

    $this->callAPISuccess('Engage', 'signpetition', [
      'campaign_id'  => $this->campaign['id'],
      'email'        => $this->contact['email'],
      'first_name'   => $this->contact['first_name'],
      'last_name'    => $this->contact['last_name'],
      'medium_id'    => self::$encounterMediumIDs['email'],
      'petition_id'  => $this->survey['id'],
      'utm_campaign' => "utm_campaign_$randomID",
      'utm_content'  => "utm_content_$randomID",
      'utm_medium'   => "utm_medium_$randomID",
      'utm_source'   => "utm_source_$randomID",
    ]);

    $petitionActivity = Api4\Activity::get(FALSE)
      ->addSelect(
        'medium_id',
        'subject',
        'utm.utm_campaign',
        'utm.utm_content',
        'utm.utm_medium',
        'utm.utm_source'
      )
      ->addWhere('activity_type_id:name', '=', 'Petition')
      ->addWhere('source_record_id', '=', $this->survey['id'])
      ->setLimit(1)
      ->execute()
      ->first();

    $this->assertEquals($this->survey['title'], $petitionActivity['subject']);

    $this->assertEquals(
      self::$encounterMediumIDs['email'],
      $petitionActivity['medium_id']
    );

    $this->assertEquals(
      "utm_campaign_$randomID",
      $petitionActivity['utm.utm_campaign']
    );

    $this->assertEquals(
      "utm_content_$randomID",
      $petitionActivity['utm.utm_content']
    );

    $this->assertEquals(
      "utm_medium_$randomID",
      $petitionActivity['utm.utm_medium']
    );

    $this->assertEquals(
      "utm_source_$randomID",
      $petitionActivity['utm.utm_source']
    );
  }

  public function testFakePetitionCase() {
    $randomID = bin2hex(random_bytes(8));
    $petitionID = CRM_Gpapi_CaseHandler::$case_petition_offset + $this->caseType['id'];

    $this->callAPISuccess('Engage', 'signpetition', [
      'email'        => $this->contact['email'],
      'first_name'   => $this->contact['first_name'],
      'last_name'    => $this->contact['last_name'],
      'medium_id'    => self::$encounterMediumIDs['phone'],
      'petition_id'  => $petitionID,
      'utm_campaign' => "utm_campaign_$randomID",
      'utm_content'  => "utm_content_$randomID",
      'utm_medium'   => "utm_medium_$randomID",
      'utm_source'   => "utm_source_$randomID",
    ]);

    $case = $this->callAPISuccess('Case', 'getsingle', [
      'case_type_id' => $this->caseType['id'],
      'client_id'    => $this->contact['id'],
    ]);

    $this->assertEquals('Case (Engage/Phone)', $case['subject']);

    // GP-23165: Medium und UTM-Source für "Ratgeber verschickt"-Activity
    $ratgeberVerschicktActivity = self::getCaseActivity(
      $case['id'],
      'Ratgeber verschickt', [
        'medium_id',
        'utm.utm_campaign',
        'utm.utm_content',
        'utm.utm_medium',
        'utm.utm_source',
      ]
    );

    $this->assertEquals(
      self::$encounterMediumIDs['phone'],
      $ratgeberVerschicktActivity['medium_id']
    );

    $this->assertEquals(
      "utm_campaign_$randomID",
      $ratgeberVerschicktActivity['utm.utm_campaign']
    );

    $this->assertEquals(
      "utm_content_$randomID",
      $ratgeberVerschicktActivity['utm.utm_content']
    );

    $this->assertEquals(
      "utm_medium_$randomID",
      $ratgeberVerschicktActivity['utm.utm_medium']
    );

    $this->assertEquals(
      "utm_source_$randomID",
      $ratgeberVerschicktActivity['utm.utm_source']
    );
  }

  private static function createCampaign(string $title) {
    $campaignID = reset(civicrm_api3('Campaign', 'create', [
      'title' => $title,
    ])['values'])['id'];

    return civicrm_api3('Campaign', 'getsingle', [ 'id' => $campaignID ]);
  }

  private static function createSurvey(string $title) {
    $surveyID = reset(civicrm_api3('Survey', 'create', [
      'activity_type_id' => self::getOptionValue('activity_type', 'Petition'),
      'title'            => $title,
    ])['values'])['id'];

    return civicrm_api3('Survey', 'getsingle', [ 'id' => $surveyID ]);
  }

  private static function getCaseActivity(
    int $caseID,
    string $activityType,
    array $returnFields
  ) {
    return Api4\Activity::get(FALSE)
      ->addSelect(...$returnFields)
      ->setJoin([
        ['CaseActivity AS ca', 'INNER', NULL, ['ca.activity_id', '=', 'id']],
        ['Case AS c', 'INNER', NULL, ['c.id', '=', 'ca.case_id']],
      ])
      ->addWhere('c.id', '=', $caseID)
      ->addWhere('activity_type_id:name', '=', $activityType)
      ->setLimit(1)
      ->execute()
      ->first();
  }

}

?>
