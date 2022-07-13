<?php

use Civi\Api4;

/**
 * @group headless
 */
class api_v3_Engage_SignpetitionTest extends api_v3_Engage_EngageTestBase {

  private static $encounterMediumIDs;

  public function setUp() {
    parent::setUp();

    self::$encounterMediumIDs = [
      'phone' => self::getOptionValue('encounter_medium', 'phone'),
    ];
  }

  public function testSignPetitionBasic() {
    $result = $this->callAPISuccess('Engage', 'signpetition', [
      'email'       => $this->contact['email'],
      'first_name'  => $this->contact['first_name'],
      'last_name'   => $this->contact['last_name'],
      'petition_id' => CRM_Gpapi_CaseHandler::$case_petition_offset + 1,
    ]);

    $this->assertEquals($this->contact['id'], $result['id']);

    $case = $this->callAPISuccess('Case', 'getsingle', [
      'case_type_id' => $this->caseType['id'],
      'client_id'    => $this->contact['id'],
    ]);

    $this->assertEquals('Case (Engage/Web)', $case['subject']);
  }

  /**
   * GP-23165: Medium und UTM-Source für "Ratgeber verschickt"-Activity
   */
  public function testPassMediumAndUTMFields() {
    $randomID = bin2hex(random_bytes(8));

    $this->callAPISuccess('Engage', 'signpetition', [
      'email'        => $this->contact['email'],
      'first_name'   => $this->contact['first_name'],
      'last_name'    => $this->contact['last_name'],
      'medium_id'    => self::$encounterMediumIDs['phone'],
      'petition_id'  => CRM_Gpapi_CaseHandler::$case_petition_offset + 1,
      'utm_campaign' => "utm_campaign_$randomID",
      'utm_content'  => "utm_content_$randomID",
      'utm_medium'   => "utm_medium_$randomID",
      'utm_source'   => "utm_source_$randomID",
    ]);

    $caseID = $this->callAPISuccess('Case', 'getvalue', [
      'case_type_id' => $this->caseType['id'],
      'client_id'    => $this->contact['id'],
      'return'       => 'id',
    ]);

    $ratgeberVerschicktActivity = Api4\Activity::get(FALSE)
      ->addSelect(
        'medium_id',
        'utm.utm_campaign',
        'utm.utm_content',
        'utm.utm_medium',
        'utm.utm_source'
      )
      ->setJoin([
        ['CaseActivity AS ca', 'INNER', NULL, ['ca.activity_id', '=', 'id']],
        ['Case AS c', 'INNER', NULL, ['c.id', '=', 'ca.case_id']],
      ])
      ->addWhere('c.id', '=', $caseID)
      ->addWhere('activity_type_id:name', '=', 'Ratgeber verschickt')
      ->setLimit(1)
      ->execute()
      ->first();

    $this->assertEquals(self::$encounterMediumIDs['phone'], $ratgeberVerschicktActivity['medium_id']);
    $this->assertEquals("utm_campaign_$randomID", $ratgeberVerschicktActivity['utm.utm_campaign']);
    $this->assertEquals("utm_content_$randomID", $ratgeberVerschicktActivity['utm.utm_content']);
    $this->assertEquals("utm_medium_$randomID", $ratgeberVerschicktActivity['utm.utm_medium']);
    $this->assertEquals("utm_source_$randomID", $ratgeberVerschicktActivity['utm.utm_source']);
  }

}

?>
