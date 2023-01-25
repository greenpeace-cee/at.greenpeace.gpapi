<?php

use Civi\Api4;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * OSF.Contract API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_OSF_ContractTest extends api_v3_OSF_ContractTestBase {

  private $campaign_id;
  private $credit_card_id;
  private $membership_type_id;
  private $payment_processor_id;
  private $referrer_contact_id;
  private $sepa_rcur_id;

  public function setUp() {
    parent::setUp();

    $this->campaign_id = $this->callAPISuccess('Campaign', 'create', [
      'external_identifier' => 'Direct Dialog',
      'is_active'           => '1',
      'name'                => 'direct_dialog',
      'title'               => 'DD',
    ])['id'];

    $this->payment_processor_id = $this->callAPISuccess('PaymentProcessor', 'create', [
      'financial_account_id'      => 'Payment Processor Account',
      'name'                      => 'Greenpeace',
      'payment_processor_type_id' => 'Adyen',
    ])['id'];

    $this->referrer_contact_id = $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name'   => 'Referrer',
      'last_name'    => 'Contact_2',
      'email'        => 'referrer@example.com',
    ])['id'];

    $this->credit_card_id = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution',
      'payment_instrument_id',
      'Credit Card'
    );

    $this->membership_type_id = $this->callAPISuccess('MembershipType', 'create', [
      'duration_interval'    => '2',
      'duration_unit'        => 'year',
      'financial_type_id'    => 'Member Dues',
      'member_of_contact_id' => '1',
      'name'                 => 'General',
      'period_type'          => 'rolling',
    ])['id'];

    $this->sepa_rcur_id = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution',
      'payment_instrument_id',
      'RCUR'
    );
  }

  public function testCreateAdyenContract() {

    // Create a contract via `OSF.contract`

    $date = new DateTimeImmutable();
    $one_year = DateInterval::createFromDateString('1 year');

    $cardholder_name = "{$this->contact['first_name']} {$this->contact['last_name']}";
    $event_date = $date->format('Y-m-d');
    $expiry_date = $date->add($one_year)->format('m/Y');
    $trxn_id = random_int(0, 10000);

    $psp_result_data = [
      'additionalData' => [
        'cardHolderName'                     => $cardholder_name,
        'cardSummary'                        => '1234 5678',
        'expiryDate'                         => $expiry_date,
        'paymentMethodVariant'               => 'visa',
        'recurring.recurringDetailReference' => '2916382255634620',
        'recurring.shopperReference'         => 'OSF-TOKEN-PRODUCTION-00001-EPS',
        'shopperEmail'                       => $this->contact['email'],
        'shopperIP'                          => '127.0.0.1',
      ],
      'eventDate' => $event_date,
      'merchantAccountCode' => 'Greenpeace',
    ];

    $osf_contract_params = [
      'amount'                   => 30.0,
      'campaign'                 => 'Direct Dialog',
      'contact_id'               => $this->contact['id'],
      'currency'                 => 'EUR',
      'cycle_day'                => 13,
      'frequency'                => 4,
      'membership_type_id'       => $this->membership_type_id,
      'payment_instrument'       => 'Credit Card',
      'payment_received'         => TRUE,
      'payment_service_provider' => 'adyen',
      'psp_result_data'          => $psp_result_data,
      'referrer_contact_id'      => $this->referrer_contact_id,
      'sequential'               => TRUE,
      'trxn_id'                  => $trxn_id,
      'utm_campaign'             => 'Direct Dialog',
      'utm_content'              => 'UTM content',
      'utm_medium'               => 'Phone',
      'utm_source'               => 'Unknown',
    ];

    $result = $this->callAPISuccess('OSF', 'contract', $osf_contract_params);

    // Assert that the membership was created correctly

    $membership = $this->callAPISuccess('Membership', 'getsingle', [
      'id' => $result['id'],
    ]);

    $this->assertEquals($this->campaign_id, $membership['campaign_id'], "Campaign ID should be {$this->campaign_id}");
    $this->assertEquals($this->contact['id'], $membership['contact_id'], "Contact ID should be {$this->contact['id']}");
    $this->assertEquals('General', $membership['membership_name'], 'Membership type should be "General"');
    $this->assertEquals('OSF', $membership['source'], 'Membership source should be "OSF"');

    // Assert that the recurring contribution was created correctly

    $rcur_id = $this->callAPISuccess('ContractPaymentLink', 'getvalue', [
      'contract_id' => $membership['id'],
      'return'      => "contribution_recur_id",
    ]);

    $recurring_contribution = $this->callAPISuccess('ContributionRecur', 'getsingle', [
      'id' => $rcur_id,
    ]);

    $expected_amount = number_format((float) $osf_contract_params['amount'], 2, '.', ',');
    $expected_frequency_interval = number_format(12 / (int) $osf_contract_params['frequency'], 0);

    $this->assertEquals($expected_amount, $recurring_contribution['amount'], "Contribution amount should be {$expected_amount}");
    $this->assertEquals($this->campaign_id, $recurring_contribution['campaign_id'], "Campaign ID should be {$this->campaign_id}");
    $this->assertEquals($osf_contract_params['currency'], $recurring_contribution['currency'], "Currency should be {$osf_contract_params['currency']}");
    $this->assertEquals($osf_contract_params['cycle_day'], $recurring_contribution['cycle_day'], "Cycle day should be {$osf_contract_params['cycle_day']}");
    $this->assertEquals('month', $recurring_contribution['frequency_unit'], 'Frequency unit should be "month"');
    $this->assertEquals($expected_frequency_interval, $recurring_contribution['frequency_interval'], "Frequency interval should be {$expected_frequency_interval}");
    $this->assertEquals($this->credit_card_id, $recurring_contribution['payment_instrument_id'], "Payment instrument should be Credit Card");

    // Assert that a payment token has been created

    $payment_token = $this->callAPISuccess('PaymentToken', 'getsingle', [
      'debug' => 0,
      'id' => $recurring_contribution['payment_token_id'],
    ]);

    $this->assertEquals($this->contact['id'], $payment_token['contact_id']);
    $this->assertEquals($this->contact['first_name'], $payment_token['billing_first_name']);
    $this->assertEquals($this->contact['last_name'], $payment_token['billing_last_name']);
    $this->assertEquals($this->contact['email'], $payment_token['email']);

    $actual_expiry_date = new DateTimeImmutable($payment_token['expiry_date']);
    $this->assertEquals($expiry_date, $actual_expiry_date->format('m/Y'));

    $this->assertEquals('127.0.0.1', $payment_token['ip_address']);
    $this->assertEquals('Visa: 1234 5678', $payment_token['masked_account_number']);
    $this->assertEquals('2916382255634620', $payment_token['token']);
    $this->assertEquals($this->payment_processor_id, $payment_token['payment_processor_id']);

    // Assert an initial contribution has been created

    $contribution = Api4\Contribution::get()
      ->addWhere('contact_id', '=', $this->contact['id'])
      ->addWhere('contribution_recur_id', '=', $recurring_contribution['id'])
      ->addSelect('*', 'financial_type_id:name')
      ->execute()
      ->first();

    $this->assertEquals(30.0, $contribution['total_amount']);
    $this->assertEquals('EUR', $contribution['currency']);
    $this->assertEquals($this->credit_card_id, $contribution['payment_instrument_id']);
    $this->assertEquals('Member Dues', $contribution['financial_type_id:name']);
    $this->assertEquals($trxn_id, $contribution['trxn_id']);
    $this->assertEquals('OSF', $contribution['source']);

    // Assert UTM data has been added

    $sign_activity = Api4\Activity::get()
      ->addSelect('utm.utm_campaign', 'utm.utm_content', 'utm.utm_medium', 'utm.utm_source')
      ->addWhere('activity_type_id:name', '=', 'Contract_Signed')
      ->addWhere('source_record_id', '=', $membership['id'])
      ->execute()
      ->first();

    $this->assertEquals('Direct Dialog', $sign_activity['utm.utm_campaign']);
    $this->assertEquals('UTM content', $sign_activity['utm.utm_content']);
    $this->assertEquals('Phone', $sign_activity['utm.utm_medium']);
    $this->assertEquals('Unknown', $sign_activity['utm.utm_source']);

    // Assert 'Referrer of' relationship has been created

    $referrer_count = Api4\Relationship::get()
      ->selectRowCount()
      ->addWhere('relationship_type_id:name', '=', 'Referrer of')
      ->addWhere('contact_id_a', '=', $this->referrer_contact_id)
      ->addWhere('contact_id_b', '=', $this->contact['id'])
      ->execute()
      ->rowCount;

    $this->assertEquals(1, $referrer_count);
  }

  public function testCreateSEPAContract() {

    // Create a contract via `OSF.contract`

    $osf_contract_params = [
      'amount'                   => 30.0,
      'bic'                      => 'GENODEM1GLS',
      'campaign_id'              => $this->campaign_id,
      'contact_id'               => $this->contact['id'],
      'currency'                 => 'EUR',
      'cycle_day'                => 13,
      'frequency'                => 4,
      'iban'                     => "AT695400056324339424",
      'membership_type_id'       => $this->membership_type_id,
      'payment_instrument'       => 'RCUR',
      'payment_service_provider' => 'civicrm',
    ];

    $result = $this->callAPISuccess('OSF', 'contract', $osf_contract_params);

    // Assert that the membership was created correctly

    $membership = $this->callAPISuccess('Membership', 'getsingle', [
      'id' => $result['id'],
    ]);

    $this->assertEquals($this->contact['id'], $membership['contact_id'], "Contact ID should be {$this->contact_id}");
    $this->assertEquals('General', $membership['membership_name'], 'Membership type should be "General"');
    $this->assertEquals('OSF', $membership['source'], 'Membership source should be "OSF"');

    // Assert that the recurring contribution was created correctly

    $rcur_id = $this->callAPISuccess('ContractPaymentLink', 'getvalue', [
      'contract_id' => $membership['id'],
      'return'      => "contribution_recur_id",
    ]);

    $recurring_contribution = $this->callAPISuccess('ContributionRecur', 'getsingle', [
      'id' => $rcur_id,
    ]);

    $expected_amount = number_format((float) $osf_contract_params['amount'], 2, '.', ',');
    $expected_frequency_interval = number_format(12 / (int) $osf_contract_params['frequency'], 0);

    $this->assertEquals($expected_amount, $recurring_contribution['amount'], "Contribution amount should be {$expected_amount}");
    $this->assertEquals($this->campaign_id, $recurring_contribution['campaign_id'], "Campaign ID should be {$this->campaign_id}");
    $this->assertEquals($osf_contract_params['currency'], $recurring_contribution['currency'], "Currency should be {$osf_contract_params['currency']}");
    $this->assertEquals($osf_contract_params['cycle_day'], $recurring_contribution['cycle_day'], "Cycle day should be {$osf_contract_params['cycle_day']}");
    $this->assertEquals('month', $recurring_contribution['frequency_unit'], 'Frequency unit should be "month"');
    $this->assertEquals($expected_frequency_interval, $recurring_contribution['frequency_interval'], "Frequency interval should be {$expected_frequency_interval}");
    $this->assertEquals($this->sepa_rcur_id, $recurring_contribution['payment_instrument_id'], "Payment instrument should be SEPA RCUR");

    // Assert that the SEPA mandate was created correctly

    $mandate = $this->callAPISuccess('SepaMandate', 'getsingle', [
      'entity_id' => $rcur_id,
    ]);

    $sepa_default_creditor_id = CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor');

    $this->assertEquals($osf_contract_params['bic'], $mandate['bic'], "BIC shoud be {$osf_contract_params['bic']}");
    $this->assertEquals($sepa_default_creditor_id, $mandate['creditor_id'], "Creditor ID shoud be {$sepa_default_creditor_id}");
    $this->assertEquals($osf_contract_params['iban'], $mandate['iban'], "IBAN shoud be {$osf_contract_params['iban']}");

  }

}

?>
