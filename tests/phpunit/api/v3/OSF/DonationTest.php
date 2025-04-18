<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * OSF.DonationTest API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_OSF_DonationTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface
{
  use \Civi\Test\Api3TestTrait;

  protected $contact;

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
      ->apply(TRUE);
  }

  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp(): void {
    $this->contact = reset($this->callAPISuccess('Contact', 'create', [
      'email'        => 'test@example.org',
      'contact_type' => 'Individual',
    ])['values']);
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
  public function tearDown(): void {
    parent::tearDown();
  }

  public function testDonationBasic() {
    $params = [
      'contact_id' => $this->contact['id'],
      'total_amount' => 100,
      'trxn_id' => '1234',
    ];
    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);

    $this->assertEquals($this->contact['id'], $contribution['contact_id']);
    $this->assertEquals(100, $contribution['total_amount']);
    $this->assertEquals('OSF', $contribution['contribution_source']);
  }

  public function testDonationWithCampaign() {
    $campaign = reset($this->callApiSuccess('Campaign', 'create', [
      'title' => "Test Campaign",
      'is_active' => 1,
    ])['values']);
    $params = [
      'campaign_id' => $campaign['id'],
      'contact_id' => $this->contact['id'],
      'total_amount' => "44.44",
      'trxn_id' => '1234',
    ];
    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);

    $this->assertEquals($campaign['id'], $contribution['campaign_id']);
    $this->assertEquals($this->contact['id'], $contribution['contact_id']);
    $this->assertEquals(44.44, $contribution['total_amount']);
  }

  public function testDonationWithUtmParams() {
    $params = [
      'utm_source'   => 'modx',
      'utm_medium'   => 'organic',
      'utm_campaign' =>'for_don',
      'utm_content'  => 'waldschutz-eu',
      'utm_term'     => 'utm_term_value',
      'utm_id'       => 'utm_id_value',
      'contact_id'   => $this->contact['id'],
      'total_amount' => "52.99",
      'trxn_id'      => '1234',
    ];

    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);
    $this->assertEquals($this->contact['id'], $contribution['contact_id']);
    $this->assertEquals(52.99, $contribution['total_amount']);

    // utm params create an activity
    // simple test if utm values exist
    $activity = reset($this->callApiSuccess('Activity', 'get')['values']);
    $this->assertContains('modx', $activity, 'Activity does not contains utm_source');
    $this->assertContains('organic', $activity, 'Activity does not contains utm_medium');
    $this->assertContains('for_don', $activity, 'Activity does not contains utm_campaign');
    $this->assertContains('waldschutz-eu', $activity, 'Activity does not contains utm_content');
    $this->assertContains('utm_term_value', $activity, 'Activity does not contains utm_term');
    $this->assertContains('utm_id_value', $activity, 'Activity does not contains utm_id');
  }

  public function testFinancialTypeMemberDues() {
    $financial_type_id = 2;
    $this->callApiSuccess('FinancialType', 'create', [
      'id'        => $financial_type_id,
      'name'      => 'Member Dues',
      'is_active' => 1
    ]);
    $json_data = '{"currency":"EUR","payment_instrument":"Credit Card","financial_type_id":'.$financial_type_id.',
    "total_amount":"8.10","trxn_id":"OSF-TEST","psp_result_data":{},"check_permissions":true,"version":3}';
    $params = $this->addTestingContact($json_data);
    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);

    $this->assertEquals($this->contact['id'], $contribution['contact_id']);
    $this->assertEquals(8.10, $contribution['total_amount']);
    $this->assertEquals("EUR", $contribution['currency']);
    $this->assertEquals($financial_type_id, $contribution['financial_type_id']);
  }

  public function testPaymentInstrumentCreditCard() {
    $json_data = '{"currency":"RON","payment_instrument":"Credit Card","financial_type_id":1,"source":"OSF",
    "total_amount":"60.00","trxn_id":"OSF-TEST-1","psp_result_data":{},"check_permissions":true,"version":3}';
    $params = $this->addTestingContact($json_data);
    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);

    $this->assertEquals($this->contact['id'], $contribution['contact_id']);
    $this->assertEquals(60.00, $contribution['total_amount']);
    $this->assertEquals("RON", $contribution['currency']);

    $this->assertEquals($this->getPaymentInstrumentId('Credit Card'), $contribution['payment_instrument_id']);
    $this->assertEquals($this->getContributionStatusId('Completed'), $contribution['contribution_status_id']);
  }

  public function testPaymentInstrumentPaypal() {
    $this->callApiSuccess('OptionValue', 'create', [
      'option_group_id' => 'payment_instrument',
      'name'            => 'PayPal',
      'label'           => 'PayPal',
      'is_active'       => 1
    ]);
    $json_data = '{"currency":"USD","payment_instrument":"PayPal","financial_type_id":1,"source":"OSF",
    "sequential":"1","total_amount":"20.00","trxn_id":"OSF-TEST-2",
    "psp_result_data":{},"check_permissions":true,"version":3}';
    $params = $this->addTestingContact($json_data);
    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);

    $this->assertEquals($this->contact['id'], $contribution['contact_id']);
    $this->assertEquals(20.00, $contribution['total_amount']);
    $this->assertEquals("USD", $contribution['currency']);

    $this->assertEquals($this->getPaymentInstrumentId('PayPal'), $contribution['payment_instrument_id']);
    $this->assertEquals($this->getContributionStatusId('Completed'), $contribution['contribution_status_id']);
  }

  public function testPaymentInstrumentSofortueberweisung() {
    $this->callApiSuccess('OptionValue', 'create', [
      'option_group_id' => 'payment_instrument',
      'name'            => 'Sofortüberweisung',
      'label'           => 'Sofortüberweisung',
      'is_active'       => 1
    ]);
    $json_data = '{"currency":"CHF","payment_instrument":"Sofortüberweisung","financial_type_id":1,"source":"OSF",
    "sequential":"1","total_amount":"100.00","trxn_id":"OSF-TEST-3",
    "psp_result_data":{},"check_permissions":true,"version":3}';
    $params = $this->addTestingContact($json_data);
    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);

    $this->assertEquals($this->contact['id'], $contribution['contact_id']);
    $this->assertEquals(100.00, $contribution['total_amount']);
    $this->assertEquals("CHF", $contribution['currency']);

    $this->assertEquals($this->getPaymentInstrumentId('Sofortüberweisung'), $contribution['payment_instrument_id']);
    $this->assertEquals($this->getContributionStatusId('Completed'), $contribution['contribution_status_id']);
  }

  public function testPaymentInstrumentEPS() {
    $this->callApiSuccess('OptionValue', 'create', [
      'option_group_id' => 'payment_instrument',
      'name'            => 'EPS',
      'label'           => 'EPS',
      'is_active'       => 1
    ]);
    $json_data = '{"currency":"CZK","payment_instrument":"EPS","financial_type_id":1,"source":"OSF",
    "sequential":"1","total_amount":"62.50","trxn_id":"OSF-TEST-4",
    "psp_result_data":{},"check_permissions":true,"version":3}';
    $params = $this->addTestingContact($json_data);
    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);

    $this->assertEquals($this->contact['id'], $contribution['contact_id']);
    $this->assertEquals(62.50, $contribution['total_amount']);
    $this->assertEquals("CZK", $contribution['currency']);

    $this->assertEquals($this->getPaymentInstrumentId('EPS'), $contribution['payment_instrument_id']);
    $this->assertEquals($this->getContributionStatusId('Completed'), $contribution['contribution_status_id']);
  }

  public function testPaymentInstrumentSepa() {
    $this->setUpCreditor();
    $json_data = '{"currency":"EUR","payment_instrument":"OOFF","financial_type_id":1,"source":"OSF",
    "sequential":"1","total_amount":"35.00","iban":"AT542011182129643403","check_permissions":true,"version":3}';
    $params = $this->addTestingContact($json_data);
    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);

    $this->assertEquals($this->contact['id'], $contribution['contact_id']);
    $this->assertEquals(35.00, $contribution['total_amount']);
    $this->assertEquals("EUR", $contribution['currency']);

    $this->assertEquals($this->getPaymentInstrumentId('OOFF'), $contribution['payment_instrument_id']);
    $this->assertEquals($this->getContributionStatusId('Pending'), $contribution['contribution_status_id']);
  }

  public function testContributionStatusFailed() {
    $json_data = '{"failed":true,"cancel_date":"","cancel_reason":"XX01",
    "currency":"GBP","payment_instrument":"Credit Card","financial_type_id":1,"source":"OSF",
    "total_amount":"25.50","trxn_id":"OSF-TEST-99","psp_result_data":{},"check_permissions":true,"version":3}';
    $params = $this->addTestingContact($json_data);
    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);

    $this->assertEquals($this->contact['id'], $contribution['contact_id']);
    $this->assertEquals(25.50, $contribution['total_amount']);
    $this->assertEquals("GBP", $contribution['currency']);

    // assert cancellation (5 seconds tolerance)
    $this->assertEquals('XX01', $contribution['cancel_reason']);
    $this->assertEquals(time(), strtotime($contribution['cancel_date']), '', 5);

    $this->assertEquals($this->getPaymentInstrumentId('Credit Card'), $contribution['payment_instrument_id']);
    $this->assertEquals($this->getContributionStatusId('Failed'), $contribution['contribution_status_id']);
  }

  /**
   * @test
   * @dataProvider provideContributionPspResultData
   */
  public function testPspResultDataIbanAndBIC($json_data, $expected) {
    $this->setUpCreditor();
    $params = $this->addTestingContact($json_data);
    $contribution = reset($this->callApiSuccess('OSF', 'donation', $params)['values']);

    $this->assertEquals($this->contact['id'], $contribution['contact_id']);

    $actual = [
      'iban' => null,
      'bic' => null
    ];
    $bank_accounts_params = [];
    $bank_accounts_params['contact_id'] = $this->contact['id'];
    $contact_bank_accounts = reset($this->callApiSuccess('BankingAccount', 'get', $bank_accounts_params)['values']);

    if (isset($contact_bank_accounts['data_parsed'])) {
      $data_parsed = json_decode($contact_bank_accounts['data_parsed'], true);
      if (isset($data_parsed['BIC'])) {
        $actual['bic'] = $data_parsed['BIC'];
      }
    }

    if (isset($contact_bank_accounts['id'])) {
      $bank_account_ref_params = [];
      $bank_account_ref_params['ba_id'] = $contact_bank_accounts['id'];
      $bank_account_ref = reset($this->callApiSuccess('BankingAccountReference', 'get', $bank_account_ref_params)['values']);

      if (isset($bank_account_ref['reference'])) {
        $actual['iban'] = $bank_account_ref['reference'];
      }
    }

    // asset iban and bic arrays
    sort($expected);
    sort($actual);
    $this->assertEquals(json_encode($expected), json_encode($actual));
  }

  public function provideContributionPspResultData() {
    return [
      [
        'psp_result_data_null' => '{"currency":"EUR","payment_instrument":"EPS","financial_type_id":1,"source":"OSF",
    "sequential":"1","total_amount":"60","trxn_id":"IBAN-BIC-TEST-1","check_permissions":true,"version":3,
        "psp_result_data":{}}',
        [
          'iban' => null,
          'bic' => null
        ]
      ],
      [
        'psp_result_data_old' => '{"currency":"EUR","payment_instrument":"EPS","financial_type_id":1,"source":"OSF",
    "sequential":"1","total_amount":"62.50","trxn_id":"IBAN-BIC-TEST-2","check_permissions":true,"version":3,
    "psp_result_data":{"iban":"AT483200000012345864","bic":"BKAUATWW"}}',
        [
          'iban' => "AT483200000012345864",
          'bic' => "BKAUATWW"
        ]
      ],
      [
        'psp_result_data_new' => '{"currency":"EUR","payment_instrument":"EPS","financial_type_id":1,"source":"OSF",
    "sequential":"1","total_amount":"69.50","trxn_id":"IBAN-BIC-TEST-3","check_permissions":true,"version":3,
    "psp_result_data":{"additionalData":{"iban":"AT685400067284526194","bic":"BKAUATWW"}}}',
        [
          'iban' => "AT685400067284526194",
          'bic' => "BKAUATWW"
        ]
      ],
      [
        'psp_result_data_new_iban_only' => '{"currency":"EUR","payment_instrument":"EPS","financial_type_id":1,"source":"OSF",
    "sequential":"1","total_amount":"69.50","trxn_id":"IBAN-BIC-TEST-4","check_permissions":true,"version":3,
    "psp_result_data":{"additionalData":{"iban":"AT673200064145798177"}}}',
        [
          'iban' => "AT673200064145798177",
          'bic' => null
        ]
      ],
    ];
  }

  /**
   * Setup default creditor
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function setUpCreditor() {
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
  }

  /**
   * Get Contribution Status Id from value
   *
   * @param $value
   * @return bool|int|string|null
   */
  public function getContributionStatusId($value) {
    return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution','contribution_status_id', $value);
  }

  /**
   * Get Payment Instrument Id from value
   *
   * @param $value
   * @return bool|int|string|null
   */
  public function getPaymentInstrumentId($value) {
    return CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution','payment_instrument_id', $value);
  }

  /**
   * Add Testing Contact
   * Convert json string to an array and add testing contact id to params
   *
   * @param $json_string
   * @return mixed
   */
  public function addTestingContact($json_string) {
    $params = json_decode($json_string, true);
    // testing contact id
    $params['contact_id'] = $this->contact['id'];
    return $params;
  }

}
