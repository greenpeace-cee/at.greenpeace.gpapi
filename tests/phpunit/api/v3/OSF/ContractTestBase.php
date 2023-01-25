<?php

use Civi\Api4;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * OSF.Getcontract API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_OSF_ContractTestBase extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;

  protected $pspCreditorId;
  protected $contact;

  private $defaultErrorHandler;

  /**
   * Set up for headless tests.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
   */
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->install('org.project60.sepa')
      ->install('de.systopia.pspsepa')
      ->install('org.project60.banking')
      ->install('de.systopia.contract')
      ->install('de.systopia.identitytracker')
      ->install('mjwshared')
      ->install('adyen')
      ->apply(TRUE);
  }

  /**
   * Setup contract extension and its dependencies
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function setUpContractExtension() {
    $default_creditor_id = (int) CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor');
    if (empty($default_creditor_id)) {
      // create if there isn't
      $creditor = $this->callAPISuccess('SepaCreditor', 'create', [
        'creditor_type'  => 'SEPA',
        'currency'       => 'EUR',
        'mandate_active' => 1,
        'iban'           => 'AT483200000012345864',
        'uses_bic'       => FALSE,
      ]);
      CRM_Sepa_Logic_Settings::setSetting($creditor['id'], 'batching_default_creditor');
    }
    $default_creditor_id = (int) CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor');
    $this->assertNotEmpty($default_creditor_id, "There is no default SEPA creditor set");
    $this->pspCreditorId = $this->callAPISuccess('SepaCreditor', 'create', [
      'creditor_type'       => 'PSP',
      'currency'            => 'EUR',
      'mandate_active'      => 1,
      'iban'                => 'DK9520000123456789',
      'uses_bic'            => TRUE,
      'sepa_file_format_id' => 'adyen',
      'pi_rcur'             => CRM_Core_PseudoConstant::getKey(
        'CRM_Contribute_BAO_Contribution',
        'payment_instrument_id',
        'Credit Card'
      ),
    ])['id'];
  }

  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp() {
    parent::setUp();

    $this->defaultErrorHandler = set_error_handler(function ($errno, $errstr) {
      return TRUE;
    }, E_USER_DEPRECATED);

    $this->setUpContractExtension();

    $this->callApiSuccess('OptionValue', 'create', [
      'option_group_id' => 'activity_type',
      'name'            => 'streetimport_error',
      'label'           => 'Import Error',
      'is_active'       => 1
    ]);

    self::createUTMCustomFields();
    self::createReferralInfoCustomFields();
    self::createReferrerOfRelationship();

    $this->createTestContact();

    $session = CRM_Core_Session::singleton();
    $session->set('userID', 1);

    $config = CRM_Core_Config::singleton();
    $config->userPermissionClass->permissions = ['all CiviCRM permissions and ACLs'];
  }

  /**
   * The tearDown() method is executed after the test was executed (optional)
   * This can be used for cleanup.
   */
  public function tearDown() {
    set_error_handler($this->defaultErrorHandler, E_USER_DEPRECATED);

    parent::tearDown();
  }

  protected function getLastImportError() {
    return reset(civicrm_api3('Activity', 'get', [
      'activity_type_id' => 'streetimport_error',
      'options'          => [
        'limit' => 1,
        'sort'  => 'activity_date_time DESC'
      ],
    ])['values']);
  }

  private function createTestContact() {
    $this->contact = Api4\Contact::create()
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'Contact_1')
      ->execute()
      ->first();

    $this->contact['email'] = 'test@example.org';
  }

  private static function createUTMCustomFields() {
    Api4\CustomGroup::create()
      ->addValue('extends'    , 'Activity')
      ->addValue('name'       , 'utm')
      ->addValue('table_name' , 'civicrm_value_utm')
      ->addValue('title'      , 'UTM Tracking Information')
      ->execute();

    Api4\CustomField::create()
      ->addValue('column_name'         , 'utm_content')
      ->addValue('custom_group_id:name', 'utm')
      ->addValue('data_type'           , 'String')
      ->addValue('html_type'           , 'Text')
      ->addValue('is_required'         , FALSE)
      ->addValue('label'               , 'Content')
      ->addValue('name'                , 'utm_content')
      ->execute();

    Api4\CustomField::create()
      ->addValue('column_name'         , 'utm_campaign')
      ->addValue('custom_group_id:name', 'utm')
      ->addValue('data_type'           , 'String')
      ->addValue('html_type'           , 'Text')
      ->addValue('is_required'         , FALSE)
      ->addValue('label'               , 'Campaign')
      ->addValue('name'                , 'utm_campaign')
      ->execute();

    Api4\CustomField::create()
      ->addValue('column_name'         , 'utm_medium')
      ->addValue('custom_group_id:name', 'utm')
      ->addValue('data_type'           , 'String')
      ->addValue('html_type'           , 'Text')
      ->addValue('is_required'         , FALSE)
      ->addValue('label'               , 'Medium')
      ->addValue('name'                , 'utm_medium')
      ->execute();

    Api4\CustomField::create()
      ->addValue('column_name'         , 'utm_source')
      ->addValue('custom_group_id:name', 'utm')
      ->addValue('data_type'           , 'String')
      ->addValue('html_type'           , 'Text')
      ->addValue('is_required'         , FALSE)
      ->addValue('label'               , 'Source')
      ->addValue('name'                , 'utm_source')
      ->execute();
  }

  private static function createReferralInfoCustomFields() {
    Api4\CustomGroup::create()
      ->addValue('extends'   , 'Membership')
      ->addValue('name'      , 'membership_referral')
      ->addValue('table_name', 'civicrm_value_membership_referral')
      ->addValue('title'     , 'Referral Information')
      ->execute();

    Api4\CustomField::create()
      ->addValue('column_name'         , 'membership_referrer')
      ->addValue('custom_group_id:name', 'membership_referral')
      ->addValue('data_type'           , 'ContactReference')
      ->addValue('html_type'           , 'Autocomplete-Select')
      ->addValue('is_required'         , FALSE)
      ->addValue('label'               , 'Referrer')
      ->addValue('name'                , 'membership_referrer')
      ->execute();
  }

  private static function createReferrerOfRelationship() {
    Api4\RelationshipType::create()
      ->addValue('contact_type_a', 'Individual')
      ->addValue('contact_type_b', 'Individual')
      ->addValue('label_a_b', 'Referrer of')
      ->addValue('label_b_a', 'Referrer by')
      ->addValue('name_a_b', 'Referrer of')
      ->addValue('name_b_a', 'Referrer by')
      ->execute();
  }

}
