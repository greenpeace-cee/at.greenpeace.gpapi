<?php

use Civi\Api4;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class api_v3_OSF_ContractTest extends api_v3_OSF_ContractTestBase {

  private $referrer;

  public function setUp() {
    parent::setUp();

    $this->createReferrer();
  }

  public function testCreateAdyenContract() {

    // Create a contract via `OSF.contract`

    $campaign = $this->defaultCampaign;
    $contact = $this->defaultContact;
    $payment_processor = $this->adyenPaymentProcessor;
    $date = new DateTimeImmutable();
    $one_year = DateInterval::createFromDateString('1 year');

    $cardholder_name = "{$contact['first_name']} {$contact['last_name']}";
    $event_date = $date->format('Y-m-d');
    $expiry_date = $date->add($one_year)->format('m/Y');
    $membership_type_id = self::getMembershipTypeID('General');
    $trxn_id = random_int(0, 10000);

    $psp_result_data = [
      'additionalData' => [
        'cardHolderName'                     => $cardholder_name,
        'cardSummary'                        => '1234 5678',
        'expiryDate'                         => $expiry_date,
        'paymentMethodVariant'               => 'visa',
        'recurring.recurringDetailReference' => '2916382255634620',
        'recurring.shopperReference'         => 'OSF-TOKEN-PRODUCTION-00001-EPS',
        'shopperEmail'                       => $contact['email'],
        'shopperIP'                          => '127.0.0.1',
      ],
      'eventDate' => $event_date,
      'merchantAccountCode' => 'Greenpeace',
    ];

    $osf_contract_params = [
      'amount'                   => 30.0,
      'campaign'                 => 'Direct Dialog',
      'contact_id'               => $contact['id'],
      'currency'                 => 'EUR',
      'cycle_day'                => 13,
      'frequency'                => 4,
      'membership_type_id'       => $membership_type_id,
      'payment_instrument'       => 'Credit Card',
      'payment_received'         => TRUE,
      'payment_service_provider' => 'adyen',
      'psp_result_data'          => $psp_result_data,
      'referrer_contact_id'      => $this->referrer['id'],
      'sequential'               => TRUE,
      'trxn_id'                  => $trxn_id,
      'utm_campaign'             => 'Direct Dialog',
      'utm_content'              => 'UTM content',
      'utm_medium'               => 'Phone',
      'utm_source'               => 'Unknown',
    ];

    $result = $this->callAPISuccess('OSF', 'contract', $osf_contract_params);

    // Assert that the membership was created correctly

    $membership = Api4\Membership::get(FALSE)
      ->addWhere('id', '=', $result['id'])
      ->addSelect('*', 'membership_type_id:name')
      ->execute()
      ->first();

    $this->assertEquals($campaign['id'], $membership['campaign_id']);
    $this->assertEquals($contact['id'], $membership['contact_id']);
    $this->assertEquals('General', $membership['membership_type_id:name']);
    $this->assertEquals('OSF', $membership['source']);

    // Assert that the recurring contribution was created correctly

    $recur_contrib_id = self::getRecurContribIdForContract($membership['id']);

    $recurring_contribution = Api4\ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur_contrib_id)
      ->addSelect('*', 'payment_instrument_id:name')
      ->execute()
      ->first();

    $this->assertEquals(30.0, $recurring_contribution['amount']);
    $this->assertEquals($campaign['id'], $recurring_contribution['campaign_id']);
    $this->assertEquals('EUR', $recurring_contribution['currency']);
    $this->assertEquals(13, $recurring_contribution['cycle_day']);
    $this->assertEquals(3, $recurring_contribution['frequency_interval']);
    $this->assertEquals('month', $recurring_contribution['frequency_unit']);
    $this->assertEquals('Credit Card', $recurring_contribution['payment_instrument_id:name']);
    $this->assertEquals('OSF-TOKEN-PRODUCTION-00001-EPS', $recurring_contribution['processor_id']);

    // Assert that a payment token has been created

    $payment_token = Api4\PaymentToken::get(FALSE)
      ->addWhere('id', '=', $recurring_contribution['payment_token_id'])
      ->execute()
      ->first();

    $this->assertEquals($contact['id'], $payment_token['contact_id']);
    $this->assertEquals($contact['first_name'], $payment_token['billing_first_name']);
    $this->assertEquals($contact['last_name'], $payment_token['billing_last_name']);
    $this->assertEquals($contact['email'], $payment_token['email']);

    $actual_expiry_date = new DateTimeImmutable($payment_token['expiry_date']);
    $this->assertEquals($expiry_date, $actual_expiry_date->format('m/Y'));

    $this->assertEquals('127.0.0.1', $payment_token['ip_address']);
    $this->assertEquals('Visa: 1234 5678', $payment_token['masked_account_number']);
    $this->assertEquals('2916382255634620', $payment_token['token']);
    $this->assertEquals($payment_processor['id'], $payment_token['payment_processor_id']);

    // Assert that an initial contribution has been created

    $contribution = Api4\Contribution::get(FALSE)
      ->addWhere('contribution_recur_id', '=', $recur_contrib_id)
      ->addSelect(
        '*',
        'contribution_status_id:name',
        'financial_type_id:name',
        'payment_instrument_id:name'
      )
      ->execute()
      ->first();

    $contrib_receive_date = new DateTime($contribution['receive_date']);

    $this->assertEquals($campaign['id'], $contribution['campaign_id']);
    $this->assertEquals($contact['id'], $contribution['contact_id']);
    $this->assertEquals('Completed', $contribution['contribution_status_id:name']);
    $this->assertEquals('Member Dues', $contribution['financial_type_id:name']);
    $this->assertEquals('Credit Card', $contribution['payment_instrument_id:name']);
    $this->assertEquals('OSF-TOKEN-PRODUCTION-00001-EPS', $contribution['invoice_id']);
    $this->assertEquals($event_date, $contrib_receive_date->format('Y-m-d'));
    $this->assertEquals('OSF', $contribution['source']);
    $this->assertEquals(30.00, $contribution['total_amount']);
    $this->assertEquals($trxn_id, $contribution['trxn_id']);

    // Assert UTM data has been added

    $sign_activity = Api4\Activity::get(FALSE)
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

    $referrer_count = Api4\Relationship::get(FALSE)
      ->selectRowCount()
      ->addWhere('relationship_type_id:name', '=', 'Referrer of')
      ->addWhere('contact_id_a', '=', $this->referrer['id'])
      ->addWhere('contact_id_b', '=', $contact['id'])
      ->execute()
      ->rowCount;

    $this->assertEquals(1, $referrer_count);
  }

  public function testCreateSEPAContract() {

    // Create a contract via `OSF.contract`

    $campaign = $this->defaultCampaign;
    $contact = $this->defaultContact;
    $membership_type_id = self::getMembershipTypeID('General');

    $osf_contract_params = [
      'amount'                   => 30.0,
      'bic'                      => 'GENODEM1GLS',
      'campaign_id'              => $campaign['id'],
      'contact_id'               => $contact['id'],
      'currency'                 => 'EUR',
      'cycle_day'                => 13,
      'frequency'                => 4,
      'iban'                     => "AT695400056324339424",
      'membership_type_id'       => $membership_type_id,
      'payment_instrument'       => 'RCUR',
      'payment_received'         => TRUE,
      'payment_service_provider' => 'civicrm',
    ];

    $result = $this->callAPISuccess('OSF', 'contract', $osf_contract_params);

    // Assert that the membership was created correctly

    $membership = Api4\Membership::get(FALSE)
      ->addWhere('id', '=', $result['id'])
      ->addSelect('*', 'membership_type_id:name')
      ->execute()
      ->first();

    $this->assertEquals($contact['id'], $membership['contact_id']);
    $this->assertEquals('General', $membership['membership_type_id:name']);
    $this->assertEquals('OSF', $membership['source']);

    // Assert that the recurring contribution was created correctly

    $recur_contrib_id = self::getRecurContribIdForContract($membership['id']);

    $recurring_contribution = Api4\ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recur_contrib_id)
      ->addSelect('*', 'payment_instrument_id:name')
      ->execute()
      ->first();

    $this->assertEquals(30.0, $recurring_contribution['amount']);
    $this->assertEquals($campaign['id'], $recurring_contribution['campaign_id']);
    $this->assertEquals('EUR', $recurring_contribution['currency']);
    $this->assertEquals(13, $recurring_contribution['cycle_day']);
    $this->assertEquals(3, $recurring_contribution['frequency_interval']);
    $this->assertEquals('month', $recurring_contribution['frequency_unit']);
    $this->assertEquals('RCUR', $recurring_contribution['payment_instrument_id:name']);

    // Assert that the SEPA mandate was created correctly

    $sepa_mandate = Api4\SepaMandate::get(FALSE)
      ->addWhere('entity_id', '=', $recurring_contribution['id'])
      ->execute()
      ->first();

    $sepa_default_creditor_id = CRM_Sepa_Logic_Settings::getSetting('batching_default_creditor');

    $this->assertEquals($osf_contract_params['bic'], $sepa_mandate['bic']);
    $this->assertEquals($sepa_default_creditor_id, $sepa_mandate['creditor_id']);
    $this->assertEquals($osf_contract_params['iban'], $sepa_mandate['iban']);

    // Assert that an initial contribution has been created

    $contribution = Api4\Contribution::get(FALSE)
      ->addWhere('contribution_recur_id', '=', $recur_contrib_id)
      ->addSelect(
        '*',
        'contribution_status_id:name',
        'financial_type_id:name',
        'payment_instrument_id:name'
      )
      ->execute()
      ->first();

    $contrib_receive_date = new DateTime($contribution['receive_date']);

    $this->assertEquals($campaign['id'], $contribution['campaign_id']);
    $this->assertEquals($contact['id'], $contribution['contact_id']);
    $this->assertEquals('Completed', $contribution['contribution_status_id:name']);
    $this->assertEquals('Member Dues', $contribution['financial_type_id:name']);
    $this->assertEquals('RCUR', $contribution['payment_instrument_id:name']);
    $this->assertEquals($membership['join_date'], $contrib_receive_date->format('Y-m-d'));
    $this->assertEquals('OSF', $contribution['source']);
    $this->assertEquals(30.00, $contribution['total_amount']);
    $this->assertEquals($trxn_id, $contribution['trxn_id']);
  }

  private function createReferrer() {
    $this->referrer = Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name'  , 'Referrer')
      ->addValue('last_name'   , 'Contact')
      ->execute()
      ->first();
  }

}

?>
