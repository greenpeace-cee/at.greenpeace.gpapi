<?php

namespace Civi\Gpapi\ContractHelper;

use \Civi\Api4;
use \CRM_Contract_BankingLogic;
use \CRM_Utils_Array;

class Sepa extends AbstractHelper {
  const PSP_NAME = 'civicrm';

  public $creditor;
  public $mandate;

  protected function loadPaymentDetails() {
    $mandate = reset(civicrm_api3('SepaMandate', 'get', [
      'entity_table'      => 'civicrm_contribution_recur',
      'entity_id'         => $this->recurringContribution['id'],
      'api.SepaCreditor.getsingle' => [
        'id' => '$value.creditor_id',
      ],
      'check_permissions' => 0,
    ])['values']);
    // only set mandate if one exists and it's SEPA
    if (!empty($mandate) && $mandate['api.SepaCreditor.getsingle']['creditor_type'] == 'SEPA') {
      $this->mandate = $mandate;
    }
  }

  public function create (array $params) {
    if (empty($params['iban'])) {
      return \CRM_Gpapi_Error::create('OSF.contract', "No 'iban' provided.", $params);
    }

    // General membership details

    $campaign_id = (int) CRM_Utils_Array::value('campaign_id', $params);
    $contact_id = $params['contact_id'];
    $join_date = date('YmdHis');
    $membership_type_id = CRM_Utils_Array::value('membership_type_id', $params);

    // Recurring contribution details

    $this->creditor = (array) \CRM_Sepa_Logic_Settings::defaultCreditor();
    $rcur_opt_val = self::getOptionValue('payment_instrument', 'RCUR');

    $amount = number_format($params['amount'], 2, '.', '');
    $creditor_id = (int) $this->creditor['id'];
    $currency = CRM_Utils_Array::value('currency', $params, $this->creditor['currency']);
    $cycle_day = CRM_Utils_Array::value('cycle_day', $params);
    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $frequency_interval = (int) (12.0 / $params['frequency']);
    $payment_instrument_id = CRM_Utils_Array::value('payment_instrument', $params, $rcur_opt_val);
    $start_date = date('YmdHis');

    // SEPA mandate details

    $bic = CRM_Utils_Array::value('bic', $params);
    $iban = $params['iban'];

    // API options

    $sequential = (int) !empty($params['sequential']);

    $create_contract_params = [
      'campaign_id'                          => $campaign_id,
      'check_permissions'                    => 0,
      'contact_id'                           => $contact_id,
      'join_date'                            => $join_date,
      'membership_type_id'                   => $membership_type_id,
      'payment_method.adapter'               => 'sepa_mandate',
      'payment_method.amount'                => $amount,
      'payment_method.bic'                   => $bic,
      'payment_method.campaign_id'           => $campaign_id,
      'payment_method.contact_id'            => $contact_id,
      'payment_method.creditor_id'           => $creditor_id,
      'payment_method.currency'              => $currency,
      'payment_method.cycle_day'             => $cycle_day,
      'payment_method.financial_type_id'     => $financial_type_id,
      'payment_method.frequency_interval'    => $frequency_interval,
      'payment_method.frequency_unit'        => 'month',
      'payment_method.iban'                  => $iban,
      'payment_method.payment_instrument_id' => $payment_instrument_id,
      'payment_method.type'                  => 'RCUR',
      'sequential'                           => $sequential,
      'source'                               => 'OSF',
      'start_date'                           => $start_date,
    ];

    $contract_result = civicrm_api3('Contract', 'create', $create_contract_params);

    $this->loadContract($contract_result['id']);
  }

  protected function getPaymentLabel() {
    if (is_null($this->mandate)) {
      throw new Exception('No payment details found');
    }
    return $this->getObfuscatedIban();
  }

  protected function getPaymentDetails() {
    if (is_null($this->mandate)) {
      throw new Exception('No payment details found');
    }
    return [
      'iban' => $this->mandate['iban'],
    ];
  }

  protected function getPspName() {
    return self::PSP_NAME;
  }

  private function getObfuscatedIban() {
    // use first four characters
    $obfuscated = substr($this->mandate['iban'],0,4);
    // one asterisk for each character between the first and last 4
    $obfuscated .= str_repeat('*', strlen($this->mandate['iban']) - 8);
    // use last four characters
    $obfuscated .= substr($this->mandate['iban'],strlen($this->mandate['iban']) - 4);
    return wordwrap($obfuscated, 4, ' ', TRUE);
  }

  public function update(array $params) {
    $payment_details = CRM_Utils_Array::value('payment_details', $params, []);

    if (empty($payment_details['iban'])) {
      throw new Exception('Missing IBAN');
    }

    // General membership details

    $campaign_id = CRM_Utils_Array::value('campaign_id', $params);
    $medium_id = self::getOptionValue('encounter_medium', 'web');
    $membership_id = $params['contract_id'];
    $membership_type_id = CRM_Utils_Array::value('membership_type', $params);
    $modify_date = $params['start_date'] ?? date('Y-m-d');

    // Recurring contribution details

    // TODO: support multiple creditors
    $this->creditor = (array) \CRM_Sepa_Logic_Settings::defaultCreditor();

    $annual_amount = number_format($params['amount'] * $params['frequency'], 2);
    $currency = CRM_Utils_Array::value('currency', $params, $this->creditor['currency']);

    if ($currency != $this->creditor['currency']) {
      throw new Exception(
        "Invalid currency '$currency' requested, " .
        "SEPA creditor only supports '{$this->creditor['currency']}'"
      );
    }

    $bic = CRM_Utils_Array::value('bic', $payment_details);
    $contact_id = $params['contact_id'];
    $iban = $payment_details['iban'];
    $rcur_opt_val = self::getOptionValue('payment_instrument', 'RCUR');

    $cycle_day = CRM_Utils_Array::value('cycle_day', $params);
    $frequency = $params['frequency'];
    $from_ba = CRM_Contract_BankingLogic::getOrCreateBankAccount($contact_id, $iban, $bic);
    $payment_instrument_id = CRM_Utils_Array::value('payment_instrument', $params, $rcur_opt_val);
    $to_ba = CRM_Contract_BankingLogic::getCreditorBankAccount();

    // API options

    $action = $this->isActiveContract ? 'update' : 'revive';

    $modify_contract_params = [
      'action'                                  => $action,
      'campaign_id'                             => $campaign_id,
      'check_permissions'                       => 0,
      'date'                                    => $modify_date,
      'id'                                      => $membership_id,
      'medium_id'                               => $medium_id,
      'membership_payment.cycle_day'            => $cycle_day,
      'membership_payment.defer_payment_start'  => 1,
      'membership_payment.from_ba'              => $from_ba,
      'membership_payment.membership_annual'    => $annual_amount,
      'membership_payment.membership_frequency' => $frequency,
      'membership_payment.to_ba'                => $to_ba,
      'membership_type_id'                      => $membership_type_id,
      'payment_method.adapter'                  => 'sepa_mandate',
      'payment_method.cycle_day'                => $cycle_day,
      'payment_method.payment_instrument_id'    => $payment_instrument_id,
    ];

    $response = civicrm_api3('Contract', 'modify', $modify_contract_params);

    civicrm_api3('Contract', 'process_scheduled_modifications', [
      'id'                => $membership_id,
      'check_permissions' => 0,
    ]);

    $this->loadContract($membership_id);
  }

  public function createInitialContribution (array $params) {
    // This function should never be called since inital contributions for SEPA
    // contracts are processed by Civi.
    throw new Exception('Cannot create initial contribution for SEPA contracts');
  }

  protected function loadAdditionalPaymentData() {
    $this->mandate = Api4\SepaMandate::get(FALSE)
      ->addWhere('entity_id', '=', $this->recurringContribution['id'])
      ->addSelect('*')
      ->execute()
      ->first();
  }

}
