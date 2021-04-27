<?php

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
    $this->setUpContractExtension();
    $this->callApiSuccess('OptionValue', 'create', [
      'option_group_id' => 'activity_type',
      'name'            => 'streetimport_error',
      'label'           => 'Import Error',
      'is_active'       => 1
    ]);
    parent::setUp();
    $session = CRM_Core_Session::singleton();
    $session->set('userID', 1);
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access OSF API'];
  }

  /**
   * The tearDown() method is executed after the test was executed (optional)
   * This can be used for cleanup.
   */
  public function tearDown() {
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

}
