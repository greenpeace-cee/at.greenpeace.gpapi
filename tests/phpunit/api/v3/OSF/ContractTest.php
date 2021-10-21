<?php

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
  private $contact_id;
  private $credit_card_id;
  private $membership_type_id;
  private $sepa_rcur_id;

  public function setUp() {
    parent::setUp();

    $this->campaign_id = $this->callAPISuccess('Campaign', 'create', [
      'is_active' => '1',
      'name'      => 'direct_dialog',
      'title'     => 'DD',
    ])['id'];

    $this->contact_id = $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'email'        => 'doe@example.com',
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

  public function testCreatePSPContract() {

    // Create a contract via `OSF.contract`

    $random_id = strtoupper(bin2hex(random_bytes(3)));

    $osf_contract_params = [
      'amount'                   => '30',
      'bic'                      => 'Greenpeace',
      'campaign_id'              => $this->campaign_id,
      'contact_id'               => $this->contact_id,
      'currency'                 => 'EUR',
      'cycle_day'                => '13',
      'frequency'                => '4',
      'iban'                     => "OSF-PSP-ADYEN-$random_id",
      'membership_type_id'       => $this->membership_type_id,
      'payment_instrument'       => 'Credit Card',
      'payment_service_provider' => 'adyen',
    ];

    $result = $this->callAPISuccess('OSF', 'contract', $osf_contract_params);

    // Assert the the API call succeeded

    $this->assertEquals(0, $result['is_error'], 'Call to OSF.contract should not result in an error');

    // Assert that the membership was created correctly

    $membership = $this->callAPISuccess('Membership', 'getsingle', [
      'id' => $result['id'],
    ]);

    $this->assertEquals($this->contact_id, $membership['contact_id'], "Contact ID should be {$this->contact_id}");
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

    // Assert that the SEPA mandate was created correctly

    $mandate = $this->callAPISuccess('SepaMandate', 'getsingle', [
      'entity_id' => $rcur_id,
    ]);

    $this->assertEquals($osf_contract_params['bic'], $mandate['bic'], "BIC shoud be {$osf_contract_params['bic']}");
    $this->assertEquals($this->pspCreditorId, $mandate['creditor_id'], "Creditor ID shoud be {$this->pspCreditorId}");
    $this->assertEquals($osf_contract_params['iban'], $mandate['iban'], "IBAN shoud be {$osf_contract_params['iban']}");

  }

  public function testCreateSEPAContract() {

    // Create a contract via `OSF.contract`

    $osf_contract_params = [
      'amount'                   => '30',
      'bic'                      => 'GENODEM1GLS',
      'campaign_id'              => $this->campaign_id,
      'contact_id'               => $this->contact_id,
      'currency'                 => 'EUR',
      'cycle_day'                => '13',
      'frequency'                => '4',
      'iban'                     => "AT695400056324339424",
      'membership_type_id'       => $this->membership_type_id,
      'payment_instrument'       => 'RCUR',
      'payment_service_provider' => 'civicrm',
    ];

    $result = $this->callAPISuccess('OSF', 'contract', $osf_contract_params);

    // Assert the the API call succeeded

    $this->assertEquals(0, $result['is_error'], 'Call to OSF.contract should not result in an error');

    // Assert that the membership was created correctly

    $membership = $this->callAPISuccess('Membership', 'getsingle', [
      'id' => $result['id'],
    ]);

    $this->assertEquals($this->contact_id, $membership['contact_id'], "Contact ID should be {$this->contact_id}");
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
