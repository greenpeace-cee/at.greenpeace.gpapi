<?php

use Civi\Test;
use Civi\Test\Api3TestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class api_v3_Engage_EngageTestBase
  extends TestCase
  implements HeadlessInterface, HookInterface, TransactionalInterface {

  use Api3TestTrait;

  protected $caseType;
  protected $contact;

  public function setUpHeadless() {
    return Test::headless()
      ->installMe(__DIR__)
      ->install('org.project60.sepa')
      ->install('de.systopia.pspsepa')
      ->install('org.project60.banking')
      ->install('de.systopia.contract')
      ->install('de.systopia.xcm')
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    $session = CRM_Core_Session::singleton();
    $session->set('userID', 1);

    $config = CRM_Core_Config::singleton();
    $config->userPermissionClass->permissions = ['access Engage API'];

    $enCompSetting = Civi::settings()->get('enable_components');
    $enCompSetting[] = 'CiviCampaign';
    $enCompSetting[] = 'CiviCase';
    Civi::settings()->set('enable_components', $enCompSetting);

    self::createRequiredOptionValues();
    self::fixOptionValues();
    self::createRequiredGroups();
    self::createRequiredProfiles();
    $this->caseType = self::defineCaseType();
    $this->contact = self::createTestContact();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  protected static function getOptionValue(string $optionGroup, string $name) {
    return civicrm_api3('OptionValue', 'getvalue', [
      'name'            => $name,
      'option_group_id' => $optionGroup,
      'return'          => 'value',
    ]);
  }

  protected static function getOptionValueID(string $optionGroup, string $name) {
    return civicrm_api3('OptionValue', 'getvalue', [
      'name'            => $name,
      'option_group_id' => $optionGroup,
      'return'          => 'id',
    ]);
  }

  private static function fixOptionValues() {
    civicrm_api3('OptionValue', 'create', [
        'id'     => self::getOptionValueID('activity_type', 'Contribution'),
        'filter' => 0,
    ]);

  }

  private static function createRequiredGroups() {
    civicrm_api3('Group', 'create', [
      'title' => 'Donation Info',
    ]);
  }

  private static function createRequiredOptionValues() {
    $optionValues = [
      [
        'option_group_id' => 'activity_type',
        'name'            => 'anonymisation_request',
        'label'           => 'Anonymisation Request',
      ],
      [
        'option_group_id' => 'activity_type',
        'name'            => 'streetimport_error',
        'label'           => 'Import Error',
      ],
      [
        'option_group_id' => 'case_status',
        'name'            => 'enquirer',
        'label'           => 'Enquirer',
        'value'           => 5,
      ],
      [
        'option_group_id' => 'encounter_medium',
        'name'            => 'web',
        'label'           => 'Web',
        'value'           => 6
      ],
    ];

    foreach ($optionValues as $ovData) {
      civicrm_api3(
        'OptionValue',
        'create',
        array_merge($ovData, [ 'is_active' => 1 ])
      );
    }
  }

  private static function createRequiredProfiles() {
    Civi::settings()->set('xcm_config_profiles', [
      'engagement' => [
        'rules' => ['CRM_Xcm_Matcher_EmailFullNameMatcher'],
      ],
    ]);
  }

  private static function createTestContact() {
    $contactID = reset(civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual',
      'email'        => 'test@example.org',
      'first_name'   => 'Test',
      'last_name'    => 'Contact',
    ])['values'])['id'];

    return civicrm_api3('Contact', 'getsingle', [ 'id' => $contactID ]);
  }

  private static function defineCaseType() {
    return reset(civicrm_api3('CaseType', 'create', [
      'name'       => 'test_case_type',
      'title'      => 'Test Case Type',
      'is_active'  => 1,
      'definition' => [
        'activitySets' => [
          [
            'name'     => 'standard_timeline',
            'timeline' => 1,
            'activityTypes' => [
              [
                'name'   => 'Open Case',
                'status' => 'Completed',
              ],
            ],
          ],
          [
            'name'     => 'web_default',
            'timeline' => 1,
            'activityTypes' => [
              [
                'name'   => 'Email',
                'status' => 'Completed',
              ],
              [
                'name'   => 'Ratgeber verschickt',
                'status' => 'Completed',
              ],
            ],
          ],
          [
            'name'     => 'custom_timeline',
            'timeline' => 1,
            'activityTypes' => [
              [
                'name'   => 'Phone Call',
                'status' => 'Completed',
              ],
            ],
          ],
        ],
        'activityTypes' => [
          [ 'name' => 'Open Case' ],
          [ 'name' => 'Email' ],
          [ 'name' => 'Phone Call' ],
          [ 'name' => 'Ratgeber verschickt' ],
        ],
      ],
    ])['values']);
  }

}

?>
