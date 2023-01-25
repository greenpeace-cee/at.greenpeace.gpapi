<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * OSF.Updatecontract API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_OSF_UpdatecontractTest extends api_v3_OSF_ContractTestBase {

  public function setUp() {
    parent::setUp();
    // create test contact
    $this->contact = reset($this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'email' => 'doe@example.com',
    ])['values']);
  }

  public function testUpdateSepaContract() {
    $membership = $this->createMembership();
    $update = reset($this->callAPISuccess('OSF', 'updatecontract', [
      'check_permissions' => '1',
      'hash'              => $this->contact['hash'],
      'contract_id'       => $membership['id'],
      'frequency'         => '12',
      'amount'            => 50,
      'payment_instrument' => 'RCUR',
      'payment_service_provider' => 'civicrm',
      'payment_details' => [
        'iban' => 'IT60X0542811101000000123456',
      ],
      'currency' => 'EUR',
      'membership_type' => 'Foerderer',
      'external_identifier' => 'FOO-123',
    ])['values']);

    $this->assertNotEmpty($update['id']);

    $contract = reset($this->callAPISuccess('OSF', 'getcontract', [
      'hash'        => $this->contact['hash'],
      'contract_id' => $membership['id'],
    ])['values']);
    $this->assertEquals(50.00, $contract['amount']);
    $this->assertEquals(12, $contract['frequency']);
    $this->assertEquals('EUR', $contract['currency']);
    $this->assertEquals('Current', $contract['status']);
    $this->assertEquals('RCUR', $contract['payment_instrument']);
    $this->assertEquals('civicrm', $contract['payment_service_provider']);
    $this->assertEquals('IT60X0542811101000000123456', $contract['payment_details']['iban']);

  }

  public function testUpdateAdyenContract() {
    $membership = $this->createMembership();
    //$update = reset(
    $update = $this->callAPISuccess('OSF', 'updatecontract', [
      'check_permissions' => '1',
      'hash'              => $this->contact['hash'],
      'contract_id'       => $membership['id'],
      'frequency'         => '12',
      'amount'            => 50,
      'payment_instrument' => 'Credit Card',
      'payment_service_provider' => 'adyen',
      'payment_details' => [
        'shopper_reference' => 'ADYEN-123',
        'merchant_account' => 'Merch',
      ],
      'transaction_details' => [
        'date' => date('YmdHis'),
        'trxn_id' => "ADYEN-TRXN-123",
      ],
      'currency' => 'EUR',
      'membership_type' => 'Foerderer',
      'external_identifier' => 'FOO-123',
    ]);

    $this->assertNotEmpty($update['id']);
    $contract = reset($this->callAPISuccess('OSF', 'getcontract', [
      'hash'        => $this->contact['hash'],
      'contract_id' => $membership['id'],
    ])['values']);
    $this->assertEquals(50.00, $contract['amount']);
    $this->assertEquals(12, $contract['frequency']);
    $this->assertEquals('EUR', $contract['currency']);
    $this->assertEquals('Current', $contract['status']);
    $this->assertEquals('Credit Card', $contract['payment_instrument']);
    $this->assertEquals('adyen', $contract['payment_service_provider']);
    $this->assertEquals('ADYEN-123', $contract['payment_details']['shopper_reference']);
    $this->assertEquals('Merch', $contract['payment_details']['merchant_account']);
  }

  private function createMembership() {
    $now = new DateTime();
    $mandate_data = [
      'iban'               => 'DE75512108001245126199',
      'frequency_unit'     => 'month',
      'contact_id'         => $this->contact['id'],
      'financial_type_id'  => 2, // Membership Dues
      'currency'           => 'EUR',
      'type'               => 'RCUR',
      'frequency_interval' => 1,
      'amount'             => 12.60,
      'start_date'         => $now->format('Ymd'),
      'cycle_day'          => 1,
    ];

    $mandate = $this->callAPISuccess('SepaMandate', 'createfull', $mandate_data);
    $mandate = $this->callAPISuccess('SepaMandate', 'getsingle', ['id' => $mandate['id']]);

    $membershipType = $this->callAPISuccess('MembershipType', 'create', [
      'member_of_contact_id' => 1,
      'financial_type_id'    => 'Member Dues',
      'duration_unit'        => 'lifetime',
      'duration_interval'    => 1,
      'period_type'          => 'rolling',
      'name'                 => 'Foerderer',
    ])['id'];

    $contract_data = [
      'contact_id'                                           => $this->contact['id'],
      'membership_type_id'                                   => $membershipType,
      'join_date'                                            => $now->format('Ymd'),
      'start_date'                                           => $now->format('Ymd'),
      'membership_payment.membership_recurring_contribution' => $mandate['entity_id'],
    ];
    $membership = $this->callAPISuccess('Contract', 'create', $contract_data);
    return $membership;
  }

}
