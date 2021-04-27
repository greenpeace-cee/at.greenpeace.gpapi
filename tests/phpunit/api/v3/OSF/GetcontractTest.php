<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * OSF.Getcontract API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_OSF_GetcontractTest extends api_v3_OSF_ContractTestBase {

  public function testContractGet() {
    // create test contact
    $contact = reset($this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'email' => 'doe@example.com',
    ])['values']);
    $now = new DateTime();
    $mandate_data = [
      'iban'               => 'DE75512108001245126199',
      'frequency_unit'     => 'month',
      'contact_id'         => $contact['id'],
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
      'contact_id'                                           => $contact['id'],
      'membership_type_id'                                   => $membershipType,
      'join_date'                                            => $now->format('Ymd'),
      'start_date'                                           => $now->format('Ymd'),
      'membership_payment.membership_recurring_contribution' => $mandate['entity_id'],
    ];
    $membership = $this->callAPISuccess('Contract', 'create', $contract_data);
    $contract = reset($this->callAPISuccess('OSF', 'getcontract', [
      'check_permissions' => '1',
      'hash'              => $contact['hash'],
      'contract_id'       => $membership['id'],
    ])['values']);

    $this->assertEquals(1, $contract['frequency']);
    $this->assertEquals(12.60, $contract['amount']);
    $this->assertEquals(151.20, $contract['annual_amount']);
    $this->assertEquals(1, $contract['cycle_day']);
    $this->assertEquals('EUR', $contract['currency']);
    $this->assertEquals('Foerderer', $contract['membership_type']);
    $this->assertEquals('Current', $contract['status']);
    $this->assertEquals('RCUR', $contract['payment_instrument']);
    $this->assertEquals('civicrm', $contract['payment_service_provider']);
    $this->assertEquals('DE75 **** **** **** **61 99', $contract['payment_label']);
    $this->assertEquals('DE75512108001245126199', $contract['payment_details']['iban']);

    // run the test with a PSP contract
    $mandate_data = array_merge($mandate_data, [
      'iban'                  => 'ADYEN-123',
      'bic'                   => 'MERCH-123',
      'creditor_id'           => $this->pspCreditorId,
      'payment_instrument_id' => CRM_Core_PseudoConstant::getKey(
        'CRM_Contribute_BAO_Contribution',
        'payment_instrument_id',
        'Credit Card'
      ),
    ]);

    $mandate = $this->callAPISuccess('SepaMandate', 'createfull', $mandate_data);
    $mandate = $this->callAPISuccess('SepaMandate', 'getsingle', ['id' => $mandate['id']]);
    $contract_data['membership_payment.membership_recurring_contribution'] = $mandate['entity_id'];
    $membership = $this->callAPISuccess('Contract', 'create', $contract_data);
    $contract = reset($this->callAPISuccess('OSF', 'getcontract', [
      'check_permissions' => '1',
      'hash'              => $contact['hash'],
      'contract_id'       => $membership['id'],
    ])['values']);

    $this->assertEquals(1, $contract['frequency']);
    $this->assertEquals(12.60, $contract['amount']);
    $this->assertEquals(151.20, $contract['annual_amount']);
    $this->assertEquals(1, $contract['cycle_day']);
    $this->assertEquals('EUR', $contract['currency']);
    $this->assertEquals('Foerderer', $contract['membership_type']);
    $this->assertEquals('Current', $contract['status']);
    $this->assertEquals('Credit Card', $contract['payment_instrument']);
    $this->assertEquals('adyen', $contract['payment_service_provider']);
    $this->assertEmpty($contract['payment_label']);
    $this->assertEquals('ADYEN-123', $contract['payment_details']['shopper_reference']);
    $this->assertEquals('MERCH-123', $contract['payment_details']['merchant_account']);

    // delete contact
    $this->callAPISuccess('Contact', 'create', [
      'id' => $contact['id'],
      'is_deleted' => TRUE,
    ]);

    // ensure the call fails
    $result = $this->callAPIFailure('OSF', 'getcontact', [
      'hash' => $contact['hash'],
      'check_permissions' => 1,
    ], 'Unknown contact hash');
    $this->assertEquals('unknown_hash', $result['error_code']);

    // create second contact without membership
    $contact = reset($this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'email' => 'doe2@example.com',
    ])['values']);

    $result = $this->callAPIFailure('OSF', 'getcontract', [
      'check_permissions' => '1',
      'hash'              => $contact['hash'],
      'contract_id'       => $membership['id'],
    ], 'Unknown contract');
    $this->assertEquals('unknown_contract', $result['error_code']);

  }

}
