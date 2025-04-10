<?php

use Civi\Api4;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class api_v3_OSF_GetcontractTest extends api_v3_OSF_ContractTestBase {

  public function testGetSepaContract() {

    $campaign = $this->defaultCampaign;
    $contact = $this->defaultContact;
    $now = new DateTimeImmutable();

    // Create a contract via `Contract.create`

    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $join_date = $now->format('Ymd');
    $membership_type_id = self::getMembershipTypeID('Foerderer');
    $payment_instrument_id = self::getOptionValue('payment_instrument', 'RCUR');

    $create_contract_params = [
      'campaign_id'                          => $campaign['id'],
      'contact_id'                           => $contact['id'],
      'join_date'                            => $join_date,
      'membership_type_id'                   => $membership_type_id,
      'payment_method.adapter'               => 'sepa_mandate',
      'payment_method.amount'                => 10.0,
      'payment_method.bic'                   => 'GENODEM1GLS',
      'payment_method.campaign_id'           => $campaign['id'],
      'payment_method.contact_id'            => $contact['id'],
      'payment_method.currency'              => 'EUR',
      'payment_method.cycle_day'             => 13,
      'payment_method.financial_type_id'     => $financial_type_id,
      'payment_method.frequency_interval'    => 1,
      'payment_method.frequency_unit'        => 'month',
      'payment_method.iban'                  => "AT695400056324339424",
      'payment_method.payment_instrument_id' => $payment_instrument_id,
      'payment_method.type'                  => 'RCUR',
      'source'                               => 'OSF',
      'start_date'                           => $join_date,
    ];

    $contract_result = civicrm_api3('Contract', 'create', $create_contract_params);

    $membership_id = $contract_result['id'];

    $contract = reset($this->callAPISuccess('OSF', 'getcontract', [
      'contract_id' => $membership_id,
      'hash'        => $contact['hash'],
    ])['values']);

    $this->assertEquals(12, $contract['frequency']);
    $this->assertEquals(10.0, $contract['amount']);
    $this->assertEquals(120.0, $contract['annual_amount']);
    $this->assertEquals(13, $contract['cycle_day']);
    $this->assertEquals('EUR', $contract['currency']);
    $this->assertEquals('Foerderer', $contract['membership_type']);
    $this->assertEquals('Current', $contract['status']);
    $this->assertEquals('RCUR', $contract['payment_instrument']);
    $this->assertEquals('civicrm', $contract['payment_service_provider']);
    $this->assertEquals('AT69 **** **** **** 9424', $contract['payment_label']);

    $this->assertEquals([
      'iban' => 'AT695400056324339424',
    ], $contract['payment_details']);

  }

  public function testGetAdyenContract() {

    $campaign = $this->defaultCampaign;
    $contact = $this->defaultContact;
    $now = new DateTimeImmutable();
    $payment_processor = $this->adyenPaymentProcessor;

    // Create a contract via `Contract.create`

    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $join_date = $now->format('Ymd');
    $membership_type_id = self::getMembershipTypeID('General');
    $payment_instrument_id = self::getOptionValue('payment_instrument', 'Credit Card');
    $payment_processor_id = $payment_processor['id'];
    $shopper_reference = 'OSF-TOKEN-PRODUCTION-12345-SCHEME';
    $stored_payment_method_id = '5916982445614528';

    $create_contract_params = [
      'campaign_id'                             => $campaign['id'],
      'contact_id'                              => $contact['id'],
      'debug'                                   => FALSE,
      'join_date'                               => $join_date,
      'membership_type_id'                      => $membership_type_id,
      'payment_method.account_number'           => 'Visa: 1234 5678 9999 0001',
      'payment_method.adapter'                  => 'adyen',
      'payment_method.amount'                   => 10.0,
      'payment_method.campaign_id'              => $campaign['id'],
      'payment_method.contact_id'               => $contact['id'],
      'payment_method.currency'                 => 'EUR',
      'payment_method.cycle_day'                => 13,
      'payment_method.expiry_date'              => '20241201',
      'payment_method.financial_type_id'        => $financial_type_id,
      'payment_method.frequency_interval'       => 1,
      'payment_method.frequency_unit'           => 'month',
      'payment_method.payment_instrument_id'    => $payment_instrument_id,
      'payment_method.payment_processor_id'     => $payment_processor_id,
      'payment_method.shopper_reference'        => $shopper_reference,
      'payment_method.stored_payment_method_id' => $stored_payment_method_id,
      'source'                                  => 'OSF',
      'start_date'                              => $join_date,
    ];

    $contract_result = civicrm_api3('Contract', 'create', $create_contract_params);

    $membership_id = $contract_result['id'];

    $contract = reset($this->callAPISuccess('OSF', 'getcontract', [
      'contract_id' => $membership_id,
      'hash'        => $contact['hash'],
    ])['values']);

    $this->assertEquals(12, $contract['frequency']);
    $this->assertEquals(10.0, $contract['amount']);
    $this->assertEquals(120.0, $contract['annual_amount']);
    $this->assertEquals(13, $contract['cycle_day']);
    $this->assertEquals('EUR', $contract['currency']);
    $this->assertEquals('General', $contract['membership_type']);
    $this->assertEquals('Current', $contract['status']);
    $this->assertEquals('Credit Card', $contract['payment_instrument']);
    $this->assertEquals('adyen', $contract['payment_service_provider']);
    $this->assertEquals('Visa: **** **** **** 0001 (12/2024)', $contract['payment_label']);

    $this->assertEquals([
      'merchant_account'         => 'Greenpeace',
      'shopper_reference'        => 'OSF-TOKEN-PRODUCTION-12345-SCHEME',
      'stored_payment_method_id' => '5916982445614528',
    ], $contract['payment_details']);

  }

}
