<?php

use Civi\Api4;
use Civi\Api4\Activity;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class api_v3_OSF_UpdatecontractTest extends api_v3_OSF_ContractTestBase {

  public function testUpdateAdyenContract() {

    $campaign = $this->defaultCampaign;
    $contact = $this->defaultContact;
    $now = new DateTimeImmutable();
    $payment_processor = $this->adyenPaymentProcessor;

    // Create a contract via `Contract.create`

    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $join_date = $now->format('Ymd');
    $membership_type_id = self::getMembershipTypeID('General');
    $payment_instrument_id = self::getOptionValue('payment_instrument', 'Credit Card');

    $create_contract_params = [
      'campaign_id'                             => $campaign['id'],
      'contact_id'                              => $contact['id'],
      'join_date'                               => $join_date,
      'membership_type_id'                      => $membership_type_id,
      'payment_method.adapter'                  => 'adyen',
      'payment_method.amount'                   => 10.0,
      'payment_method.campaign_id'              => $campaign['id'],
      'payment_method.contact_id'               => $contact['id'],
      'payment_method.currency'                 => 'EUR',
      'payment_method.cycle_day'                => 13,
      'payment_method.financial_type_id'        => $financial_type_id,
      'payment_method.frequency_interval'       => 1,
      'payment_method.frequency_unit'           => 'month',
      'payment_method.payment_instrument_id'    => $payment_instrument_id,
      'payment_method.payment_processor_id'     => $payment_processor['id'],
      'payment_method.shopper_reference'        => 'OSF-TOKEN-PRODUCTION-00001-SCHEMA',
      'payment_method.stored_payment_method_id' => '2916382255634620',
      'payment_method.account_number'           => 'Visa: 2254',
      'payment_method.billing_first_name'       => 'Jane',
      'payment_method.billing_last_name'        => 'Doe',
      'payment_method.email'                    => 'old@example.com',
      'payment_method.expiry_date'              => '2050-03-01',
      'payment_method.ip_address'               => '127.0.0.1',
      'source'                                  => 'OSF',
      'start_date'                              => $join_date,
    ];

    $contract_result = civicrm_api3('Contract', 'create', $create_contract_params);

    $membership_id = $contract_result['id'];

    // Update via `OSF.updatecontract`

    $payment_details = [
      'merchant_account'  => 'Greenpeace',
      'shopper_reference' => 'OSF-TOKEN-PRODUCTION-00001-SCHEMA',
      'stored_payment_method_id' => '2916382255634620',
    ];

    $update_result = $this->callAPISuccess('OSF', 'updatecontract', [
      'amount'                   => 20,
      'contract_id'              => $membership_id,
      'cycle_day'                => 17,
      'debug'                    => FALSE,
      'frequency'                => 4,
      'hash'                     => $contact['hash'],
      'membership_type'          => 'Foerderer',
      'payment_details'          => $payment_details,
      'payment_instrument'       => 'Credit Card',
      'payment_service_provider' => 'adyen',
    ]);

    $this->assertNotEmpty($update_result['id']);

    $membership = Api4\Membership::get(FALSE)
      ->addWhere('id', '=', $update_result['id'])
      ->addSelect('*', 'membership_type_id:name')
      ->execute()
      ->first();

    $this->assertEquals('Foerderer', $membership['membership_type_id:name']);

    $recur_contrib_id = self::getRecurContribIdForContract($membership['id']);

    $recurring_contribution = Api4\ContributionRecur::get(FALSE)
      ->addJoin('PaymentToken AS payment_token', 'LEFT', ['payment_token_id', '=', 'payment_token.id'])
      ->addWhere('id', '=', $recur_contrib_id)
      ->addSelect('*', 'payment_token.*')
      ->execute()
      ->first();

    $rc_start_date = new DateTimeImmutable($recurring_contribution['start_date']);

    $this->assertEquals(20.0, $recurring_contribution['amount']);
    $this->assertEquals(17, $recurring_contribution['cycle_day']);
    $this->assertEquals(3, $recurring_contribution['frequency_interval']);
    $this->assertEquals('month', $recurring_contribution['frequency_unit']);
    $this->assertEquals('17', $rc_start_date->format('d'));

  }

  public function testUpdateAdyenContractWithNewShopperReference() {

    $campaign = $this->defaultCampaign;
    $contact = $this->defaultContact;
    $now = new DateTimeImmutable();
    $payment_processor = $this->adyenPaymentProcessor;

    // Create a contract via `Contract.create`

    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $join_date = $now->format('Ymd');
    $membership_type_id = self::getMembershipTypeID('General');
    $payment_instrument_id = self::getOptionValue('payment_instrument', 'Credit Card');

    $create_contract_params = [
      'campaign_id'                             => $campaign['id'],
      'contact_id'                              => $contact['id'],
      'join_date'                               => $join_date,
      'membership_type_id'                      => $membership_type_id,
      'payment_method.adapter'                  => 'adyen',
      'payment_method.amount'                   => 10.0,
      'payment_method.campaign_id'              => $campaign['id'],
      'payment_method.contact_id'               => $contact['id'],
      'payment_method.currency'                 => 'EUR',
      'payment_method.cycle_day'                => 13,
      'payment_method.financial_type_id'        => $financial_type_id,
      'payment_method.frequency_interval'       => 1,
      'payment_method.frequency_unit'           => 'month',
      'payment_method.payment_instrument_id'    => $payment_instrument_id,
      'payment_method.payment_processor_id'     => $payment_processor['id'],
      'payment_method.shopper_reference'        => 'OSF-TOKEN-PRODUCTION-00001-EPS',
      'payment_method.stored_payment_method_id' => '2916382255634620',
      'source'                                  => 'OSF',
      'start_date'                              => $join_date,
    ];

    $contract_result = civicrm_api3('Contract', 'create', $create_contract_params);

    $membership_id = $contract_result['id'];

    // Update via `OSF.updatecontract`
    $payment_details = [
      'merchant_account'  => 'Greenpeace',
      'shopper_reference' => 'OSF-TOKEN-STAGING-A-1088-scheme',
      'stored_payment_method_id' => 'new-recurring-detail-reference',
      'psp_result_data' => [
        'additionalData' => [
          'recurring.recurringDetailReference' => 'new-recurring-detail-reference',
          'shopperEmail' => 'new@example.com',
          'paymentMethod' => 'mc',
          'recurring.shopperReference' => 'OSF-TOKEN-STAGING-A-1088-scheme',
          'shopperReference' => 'OSF-TOKEN-STAGING-A-1088-scheme',
          'cardSummary' => '1142',
          'expiryDate' => '06/2045',
          'cardHolderName' => 'Janette Doe',
        ],
        'amount' => [
          'currency' => 'EUR',
          'value' => '0'
        ],
        'eventCode' => 'AUTHORISATION',
        'eventDate' => '2026-04-02T12:42:55+02:00',
        'merchantAccountCode' => 'Greenpeace',
        'merchantReference' => 'OSF-STAGING-A-1088',
        'paymentMethod' => 'visa',
        'pspReference' => 'psp_reference',
        'success' => 'true'
      ]
    ];

    $update_result = $this->callAPISuccess('OSF', 'updatecontract', [
      'amount'                   => 20,
      'contract_id'              => $membership_id,
      'cycle_day'                => 17,
      'debug'                    => FALSE,
      'frequency'                => 4,
      'hash'                     => $contact['hash'],
      'membership_type'          => 'Foerderer',
      'payment_details'          => $payment_details,
      'payment_instrument'       => 'Credit Card',
      'payment_service_provider' => 'adyen',
    ]);

    $this->assertNotEmpty($update_result['id']);
    $contract_activity = Activity::get(FALSE)
      ->addSelect('*', 'status_id:name')
      ->addWhere('id', '=', $update_result['contract_activity_id'])
      ->execute()
      ->first();
    $this->assertEquals('Completed', $contract_activity['status_id:name']);

    $membership = Api4\Membership::get(FALSE)
      ->addWhere('id', '=', $update_result['id'])
      ->addSelect('*', 'membership_type_id:name')
      ->execute()
      ->first();

    // TODO: enable
    $this->assertEquals('Foerderer', $membership['membership_type_id:name']);

    $recur_contrib_id = self::getRecurContribIdForContract($membership['id']);

    $recurring_contribution = Api4\ContributionRecur::get(FALSE)
      ->addJoin('PaymentToken AS payment_token', 'LEFT', ['payment_token_id', '=', 'payment_token.id'])
      ->addWhere('id', '=', $recur_contrib_id)
      ->addSelect('*', 'payment_instrument_id:name', 'payment_token.*')
      ->execute()
      ->first();

    $rc_start_date = new DateTimeImmutable($recurring_contribution['start_date']);

    $this->assertEquals(20.0, $recurring_contribution['amount']);
    $this->assertEquals(17, $recurring_contribution['cycle_day']);
    $this->assertEquals(3, $recurring_contribution['frequency_interval']);
    $this->assertEquals('month', $recurring_contribution['frequency_unit']);
    $this->assertEquals('17', $rc_start_date->format('d'));
    $this->assertEquals('new-recurring-detail-reference', $recurring_contribution['payment_token.token']);
    $this->assertEquals('OSF-TOKEN-STAGING-A-1088-scheme', $recurring_contribution['processor_id']);
    $this->assertEquals('Janette', $recurring_contribution['payment_token.billing_first_name']);
    $this->assertEquals($payment_processor['id'], $recurring_contribution['payment_processor_id']);
    $this->assertEquals('Visa: 1142', $recurring_contribution['payment_token.masked_account_number']);
    $this->assertEquals('new@example.com', $recurring_contribution['payment_token.email']);
    $this->assertEquals('2045-06-30 00:00:00', $recurring_contribution['payment_token.expiry_date']);
    $this->assertEquals('Credit Card', $recurring_contribution['payment_instrument_id:name']);
  }

  public function testReviveAdyenContract() {

    $campaign = $this->defaultCampaign;
    $contact = $this->defaultContact;
    $now = new DateTimeImmutable();
    $payment_processor = $this->adyenPaymentProcessor;

    // Create a contract via `Contract.create`

    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $join_date = $now->format('Ymd');
    $membership_type_id = self::getMembershipTypeID('General');
    $payment_instrument_id = self::getOptionValue('payment_instrument', 'Credit Card');

    $create_contract_params = [
      'campaign_id'                             => $campaign['id'],
      'contact_id'                              => $contact['id'],
      'join_date'                               => $join_date,
      'membership_type_id'                      => $membership_type_id,
      'payment_method.adapter'                  => 'adyen',
      'payment_method.amount'                   => 10.0,
      'payment_method.campaign_id'              => $campaign['id'],
      'payment_method.contact_id'               => $contact['id'],
      'payment_method.currency'                 => 'EUR',
      'payment_method.cycle_day'                => 13,
      'payment_method.financial_type_id'        => $financial_type_id,
      'payment_method.frequency_interval'       => 1,
      'payment_method.frequency_unit'           => 'month',
      'payment_method.payment_instrument_id'    => $payment_instrument_id,
      'payment_method.payment_processor_id'     => $payment_processor['id'],
      'payment_method.shopper_reference'        => 'OSF-TOKEN-PRODUCTION-00001-EPS',
      'payment_method.stored_payment_method_id' => '2916382255634620',
      'source'                                  => 'OSF',
      'start_date'                              => $join_date,
    ];

    $contract_result = civicrm_api3('Contract', 'create', $create_contract_params);

    $membership_id = $contract_result['id'];

    // Cancel contract via `Contract.modify`

    $cancel_medium_id = self::getOptionValue('encounter_medium', 'in_person');

    civicrm_api3('Contract', 'modify', [
      'action'                                           => 'cancel',
      'id'                                               => $membership_id,
      'medium_id'                                        => $cancel_medium_id,
      'membership_cancellation.membership_cancel_reason' => 'Unknown',
    ]);

    civicrm_api3('Contract', 'process_scheduled_modifications');

    $membership = Api4\Membership::get(FALSE)
      ->addWhere('id', '=', $membership_id)
      ->addSelect('status_id:name')
      ->execute()
      ->first();

    $this->assertEquals('Cancelled', $membership['status_id:name']);

    // Update via `OSF.updatecontract`

    $payment_details = [
      'merchant_account'  => 'Greenpeace',
      'shopper_reference' => 'OSF-TOKEN-PRODUCTION-00001-EPS',
      'stored_payment_method_id' => '2916382255634620',
    ];

    $update_result = $this->callAPISuccess('OSF', 'updatecontract', [
      'amount'                   => 20,
      'contract_id'              => $membership_id,
      'debug'                    => FALSE,
      'frequency'                => 4,
      'hash'                     => $contact['hash'],
      'membership_type'          => 'Foerderer',
      'payment_details'          => $payment_details,
      'payment_instrument'       => 'Credit Card',
      'payment_service_provider' => 'adyen',
    ]);

    $this->assertNotEmpty($update_result['id']);

    $membership = Api4\Membership::get(FALSE)
      ->addWhere('id', '=', $update_result['id'])
      ->addSelect('*', 'membership_type_id:name', 'status_id:name')
      ->execute()
      ->first();

    $this->assertEquals('Foerderer', $membership['membership_type_id:name']);
    $this->assertEquals('Current', $membership['status_id:name']);

    $recur_contrib_id = self::getRecurContribIdForContract($membership['id']);

    $recurring_contribution = Api4\ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur_contrib_id)
      ->addSelect('*')
      ->execute()
      ->first();

    $this->assertEquals(20.0, $recurring_contribution['amount']);
    $this->assertEquals(3, $recurring_contribution['frequency_interval']);
    $this->assertEquals('month', $recurring_contribution['frequency_unit']);

  }

  public function testUpdateSepaContract() {

    $campaign = $this->defaultCampaign;
    $contact = $this->defaultContact;
    $now = new DateTimeImmutable();

    // Create a contract via `Contract.create`

    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $join_date = $now->format('Ymd');
    $membership_type_id = self::getMembershipTypeID('General');
    $payment_instrument_id = self::getOptionValue('payment_instrument', 'RCUR');

    $create_contract_params = [
      'campaign_id'                          => $campaign['id'],
      'contact_id'                           => $contact['id'],
      'debug'                                => FALSE,
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

    // Update contract via `OSF.updatecontract`

    $payment_details = [
      'bic'  => 'GENODEM2GLS',
      'iban' => 'IT60X0542811101000000123456',
    ];

    $update_result = reset($this->callAPISuccess('OSF', 'updatecontract', [
      'amount'                   => 20.0,
      'contract_id'              => $membership_id,
      'currency'                 => 'EUR',
      'cycle_day'                => 17,
      'frequency'                => 4,
      'hash'                     => $contact['hash'],
      'membership_type'          => 'Foerderer',
      'payment_details'          => $payment_details,
      'payment_instrument'       => 'RCUR',
      'payment_service_provider' => 'civicrm',
    ])['values']);

    $this->assertNotEmpty($update_result['id']);

    $membership = Api4\Membership::get(FALSE)
      ->addWhere('id', '=', $update_result['id'])
      ->addSelect('*', 'membership_type_id:name')
      ->execute()
      ->first();

    $this->assertEquals('Foerderer', $membership['membership_type_id:name']);

    $recur_contrib_id = self::getRecurContribIdForContract($membership['id']);

    $recurring_contribution = Api4\ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur_contrib_id)
      ->addSelect('*', 'payment_instrument_id:name')
      ->execute()
      ->first();

    $rc_start_date = new DateTimeImmutable($recurring_contribution['start_date']);

    $this->assertEquals(20.0, $recurring_contribution['amount']);
    $this->assertEquals(17, $recurring_contribution['cycle_day']);
    $this->assertEquals(3, $recurring_contribution['frequency_interval']);
    $this->assertEquals('month', $recurring_contribution['frequency_unit']);
    $this->assertEquals('EUR', $recurring_contribution['currency']);
    $this->assertEquals('RCUR', $recurring_contribution['payment_instrument_id:name']);
    $this->assertEquals('17', $rc_start_date->format('d'));

    $sepa_mandate = Api4\SepaMandate::get(FALSE)
      ->addWhere('entity_id', '=', $recur_contrib_id)
      ->addSelect('*')
      ->execute()
      ->first();

    $this->assertEquals('GENODEM2GLS', $sepa_mandate['bic']);
    $this->assertEquals('IT60X0542811101000000123456', $sepa_mandate['iban']);
  }

  public function testReviveSepaContract() {

    $campaign = $this->defaultCampaign;
    $contact = $this->defaultContact;
    $now = new DateTimeImmutable();

    // Create a contract via `Contract.create`

    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $join_date = $now->format('Ymd');
    $membership_type_id = self::getMembershipTypeID('General');
    $payment_instrument_id = self::getOptionValue('payment_instrument', 'RCUR');

    $create_contract_params = [
      'campaign_id'                          => $campaign['id'],
      'contact_id'                           => $contact['id'],
      'debug'                                => FALSE,
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

    // Cancel contract via `Contract.modify`

    $cancel_medium_id = self::getOptionValue('encounter_medium', 'in_person');

    civicrm_api3('Contract', 'modify', [
      'action'                                           => 'cancel',
      'id'                                               => $membership_id,
      'medium_id'                                        => $cancel_medium_id,
      'membership_cancellation.membership_cancel_reason' => 'Unknown',
    ]);

    civicrm_api3('Contract', 'process_scheduled_modifications');

    $membership = Api4\Membership::get(FALSE)
      ->addWhere('id', '=', $membership_id)
      ->addSelect('status_id:name')
      ->execute()
      ->first();

    $this->assertEquals('Cancelled', $membership['status_id:name']);

    // Update via `OSF.updatecontract`

    $payment_details = [
      'bic'  => 'GENODEM2GLS',
      'iban' => 'IT60X0542811101000000123456',
    ];

    $update_result = reset($this->callAPISuccess('OSF', 'updatecontract', [
      'amount'                   => 20.0,
      'contract_id'              => $membership_id,
      'currency'                 => 'EUR',
      'frequency'                => 4,
      'hash'                     => $contact['hash'],
      'membership_type'          => 'Foerderer',
      'payment_details'          => $payment_details,
      'payment_instrument'       => 'RCUR',
      'payment_service_provider' => 'civicrm',
    ])['values']);

    $this->assertNotEmpty($update_result['id']);

    $membership = Api4\Membership::get(FALSE)
      ->addWhere('id', '=', $update_result['id'])
      ->addSelect('*', 'membership_type_id:name', 'status_id:name')
      ->execute()
      ->first();

    $this->assertEquals('Foerderer', $membership['membership_type_id:name']);
    $this->assertEquals('Current', $membership['status_id:name']);

    $recur_contrib_id = self::getRecurContribIdForContract($membership['id']);

    $recurring_contribution = Api4\ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur_contrib_id)
      ->addSelect('*', 'payment_instrument_id:name')
      ->execute()
      ->first();

    $this->assertEquals(20.0, $recurring_contribution['amount']);
    $this->assertEquals(3, $recurring_contribution['frequency_interval']);
    $this->assertEquals('month', $recurring_contribution['frequency_unit']);
    $this->assertEquals('EUR', $recurring_contribution['currency']);
    $this->assertEquals('RCUR', $recurring_contribution['payment_instrument_id:name']);

    $sepa_mandate = Api4\SepaMandate::get(FALSE)
      ->addWhere('entity_id', '=', $recur_contrib_id)
      ->addSelect('*')
      ->execute()
      ->first();

    $this->assertEquals('GENODEM2GLS', $sepa_mandate['bic']);
    $this->assertEquals('IT60X0542811101000000123456', $sepa_mandate['iban']);

  }

}
