<?php

use Civi\Api4;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class api_v3_OSF_ContractTestBase
extends TestCase
implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;

  protected $adyenPaymentProcessor;
  protected $defaultCampaign;
  protected $defaultContact;

  private $defaultErrorHandler;

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

  public function setUp(): void {
    parent::setUp();

    $this->defaultErrorHandler = set_error_handler(function ($errno, $errstr) {
      return TRUE;
    }, E_USER_DEPRECATED);

    self::createRequiredOptionValues();
    self::createMembershipTypes();
    $this->createAdyenPaymentProcessor();
    $this->createDefaultCampaign();
    $this->createDefaultContact();
    $this->setDefaultSEPACreditor();

    $session = CRM_Core_Session::singleton();
    $session->set('userID', 1);

    $config = CRM_Core_Config::singleton();
    $config->userPermissionClass->permissions = ['access OSF API'];
  }

  public function tearDown(): void {
    CRM_Gpapi_Identitytracker_Configuration::resetInstance();

    set_error_handler($this->defaultErrorHandler, E_USER_DEPRECATED);
    parent::tearDown();
  }

  protected static function getFinancialTypeID(string $name) {
    return (int) Api4\FinancialType::get(FALSE)
      ->addWhere('name', '=', $name)
      ->addSelect('id')
      ->execute()
      ->first()['id'];
  }

  protected static function getOptionValue(string $optionGroup, string $name) {
    return (int) Api4\OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', $optionGroup)
      ->addWhere('name', '=', $name)
      ->addSelect('value')
      ->setLimit(1)
      ->execute()
      ->first()['value'];
  }

  protected static function getMembershipTypeID(string $name) {
    return (int) Api4\MembershipType::get(FALSE)
      ->addWhere('name', '=', $name)
      ->addSelect('*')
      ->execute()
      ->first()['id'];
  }

  protected static function getRecurContribIdForContract(int $membership_id) {
    return civicrm_api3('ContractPaymentLink', 'getvalue', [
      'contract_id' => $membership_id,
      'is_active'   => TRUE,
      'return'      => "contribution_recur_id",
    ]);
  }

  private function createAdyenPaymentProcessor() {
    $this->adyenPaymentProcessor = Api4\PaymentProcessor::create(FALSE)
      ->addValue('financial_account_id.name'     , 'Payment Processor Account')
      ->addValue('name'                          , 'Greenpeace')
      ->addValue('payment_processor_type_id.name', 'Adyen')
      ->execute()
      ->first();
  }

  private function createDefaultCampaign() {
    $settings_result = Api4\Setting::get(FALSE)
      ->addSelect('enable_components')
      ->execute()
      ->first();

    $components = array_merge($settings_result['value'], ['CiviCampaign']);

    Api4\Setting::set(FALSE)
      ->addValue('enable_components', $components)
      ->execute();

    $this->defaultCampaign = Api4\Campaign::create(FALSE)
      ->addValue('external_identifier', 'Direct Dialog')
      ->addValue('is_active'          , TRUE)
      ->addValue('name'               , 'direct_dialog')
      ->addValue('title'              , 'DD')
      ->execute()
      ->first();
  }

  private function createDefaultContact() {
    $this->defaultContact = Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name'  , 'Test')
      ->addValue('last_name'   , 'Contact_1')
      ->execute()
      ->first();

    $this->defaultContact['email'] = 'test@example.org';
  }

  private function setDefaultSEPACreditor() {
    $default_creditor_id = CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor');

    if (isset($default_creditor_id)) return;

    $creditor = Api4\SepaCreditor::create(FALSE)
      ->addValue('creditor_type' , 'SEPA')
      ->addValue('currency'      , 'EUR')
      ->addValue('iban'          , 'AT483200000012345864')
      ->addValue('mandate_active', TRUE)
      ->addValue('mandate_prefix', 'SEPA')
      ->addValue('uses_bic'      , FALSE)
      ->execute()
      ->first();

    CRM_Sepa_Logic_Settings::setSetting($creditor['id'], 'batching_default_creditor');

    $this->assertNotEmpty(
      CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor'),
      'There is no default SEPA creditor set'
    );
  }

  private static function createMembershipTypes() {
    Api4\MembershipType::create(FALSE)
      ->addValue('duration_interval'     , 2)
      ->addValue('duration_unit'         , 'year')
      ->addValue('financial_type_id.name', 'Member Dues')
      ->addValue('member_of_contact_id'  , 1)
      ->addValue('name'                  , 'General')
      ->addValue('period_type'           , 'rolling')
      ->execute();

    Api4\MembershipType::create(FALSE)
      ->addValue('duration_interval'     , 1)
      ->addValue('duration_unit'         , 'lifetime')
      ->addValue('financial_type_id.name', 'Member Dues')
      ->addValue('member_of_contact_id'  , 1)
      ->addValue('name'                  , 'Foerderer')
      ->addValue('period_type'           , 'rolling')
      ->execute();
  }

  private static function createRequiredOptionValues() {
    Api4\OptionValue::create(FALSE)
      ->addValue('is_active', TRUE)
      ->addValue('label', 'Import Error')
      ->addValue('name', 'streetimport_error')
      ->addValue('option_group_id.name', 'activity_type')
      ->execute();
  }

}
