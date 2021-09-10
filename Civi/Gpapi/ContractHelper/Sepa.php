<?php

namespace Civi\Gpapi\ContractHelper;

class Sepa extends AbstractHelper {
  protected $mandate;

  public function __construct($membershipId = NULL) {
    parent::__construct($membershipId);
  }

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
    // 1. Get the payment instrument ID

    $payment_instrument_id = (int) \CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution',
      'payment_instrument_id',
      $params['payment_instrument']
    );

    // 2. Check/resolve the referrer contact ID

    $referrer_id = self::getReferrerContactID($params);

    if (!empty($referrer_id) && (int) $referrer_id === (int) $params['contact_id']) {
      return CRM_Gpapi_Error::create(
        'OSF.contract',
        "Parameter 'referrer_contact_id' must not match 'contact_id'",
        $params
      );
    }

    // 3. Use the default creditor for SEPA payments

    $creditor = (array) \CRM_Sepa_Logic_Settings::defaultCreditor();

    // 4. Set the currency

    $currency = empty($params['currency']) ? $creditor['currency'] : $params['currency'];

    // 5. Resolve the campaign ID

    \CRM_Gpapi_Processor::resolveCampaign($params);

    // 6. Set the join date

    $member_since = date('YmdHis');

    // 7. Calculate the cycle day

    $next_debit_date = self::calculateNextDebitDate($params, $creditor);
    $cycle_day = (int) date('d', $next_debit_date);

    // 8. Calculate the start date

    $start_date = date('YmdHis', empty($params['payment_received']) ? time() : $next_debit_date);

    // 9. Format the payment amount

    $amount = number_format($params['amount'], 2, '.', '');

    // 10. Make call to Contract API

    $create_contract_params = [
      'campaign_id'                          => $params['campaign_id'],
      'check_permissions'                    => 0,
      'contact_id'                           => $params['contact_id'],
      'join_date'                            => $member_since,
      'membership_type_id'                   => $params['membership_type_id'],
      'payment_method.adapter'               => 'sepa_mandate',
      'payment_method.amount'                => $amount,
      'payment_method.bic'                   => $params['bic'],
      'payment_method.campaign_id'           => $params['campaign_id'],
      'payment_method.contact_id'            => $params['contact_id'],
      'payment_method.creditor_id'           => $creditor['id'],
      'payment_method.currency'              => $currency,
      'payment_method.cycle_day'             => $cycle_day,
      'payment_method.financial_type_id'     => "2", // Membership dues
      'payment_method.frequency_interval'    => (int) (12.0 / $params['frequency']),
      'payment_method.frequency_unit'        => 'month',
      'payment_method.iban'                  => $params['iban'],
      'payment_method.payment_instrument_id' => $payment_instrument_id,
      'payment_method.type'                  => 'RCUR',
      'sequential'                           => empty($params['sequential']) ? 0 : 1,
      'source'                               => 'OSF',
      'start_date'                           => $start_date,
    ];

    $contract_result = civicrm_api3('Contract', 'create', $create_contract_params);

    $recurring_contribution_id = civicrm_api3('ContractPaymentLink', 'getvalue', [
      'contract_id' => $contract_result['id'],
      'return'      => 'contribution_recur_id',
    ]);

    $recurring_contribution = civicrm_api3('ContributionRecur', 'getsingle', [
      'check_permissions' => 0,
      'id'                => $recurring_contribution_id
    ]);

    $sepa_mandate = civicrm_api3('SepaMandate', 'getsingle', [
      'check_permissions' => 0,
      'entity_id'         => $recurring_contribution_id,
    ]);

    // 11. Update activity with UTM

    $activity_id = civicrm_api3('Activity', 'getvalue', [
      'return' => 'id',
      'activity_type_id' => 'Contract_Signed',
      'source_record_id' => $contract_result['id'],
    ]);

    \CRM_Gpapi_Processor::updateActivityWithUTM($params, $activity_id);

    // 12. Create an initial contribution

    if (!empty($params['payment_received'])) {
      $init_contribution = self::createInitialContribution([
        'activity_id'           => $activity_id,
        'campaign_id'           => $recurring_contribution['campaign_id'],
        'contact_id'            => $sepa_mandate['contact_id'],
        'creditor_iban'         => $creditor['iban'],
        'financial_type_id'     => $recurring_contribution['financial_type_id'],
        'is_test'               => $recurring_contribution['is_test'],
        'member_since'          => $member_since,
        'payment_instrument_id' => $payment_instrument_id,
        'rcur_amount'           => $recurring_contribution['amount'],
        'rcur_currency'         => $recurring_contribution['currency'],
        'rcur_id'               => $recurring_contribution_id,
        'sepa_mandate_id'       => $sepa_mandate['id'],
        'trxn_id'               => $params['trxn_id'],
      ]);
    }

    // 13. Create a "Referrer of" relationship

    if (!empty($referrer_id)) {
      $updated_membership = self::createReferrerOfRelationship([
        'contact_id'    => $params['contact_id'],
        'membership_id' => $contract_result['id'],
        'referrer_id'   => $referrer_id,
      ]);
    }

    // 14. Createa a bank account from 'psp_result_data' params

    if (empty($params['psp_result_data']['bic'])) return $contract_result;
    if (empty($params['psp_result_data']['iban'])) return $contract_result;

    $bank_account_id = self::getBankAccount([
      'contact_id' => $params['contact_id'],
      'iban'       => $params['psp_result_data']['iban'],
    ]);

    if ($bank_account_id === NULL) {
      $bank_account = self::createBankAccount([
        'bic'            => $params['psp_result_data']['bic'],
        'contact_id'     => $params['contact_id'],
        'iban'           => $params['psp_result_data']['iban'],
        'reference_type' => 'IBAN',
      ]);
    }

    return $contract_result;
  }

  public function getPaymentLabel() {
    if (is_null($this->mandate)) {
      throw new Exception('No payment details found');
    }
    return $this->getObfuscatedIban();
  }

  public function getPaymentDetails() {
    if (is_null($this->mandate)) {
      throw new Exception('No payment details found');
    }
    return [
      'iban' => $this->mandate['iban'],
    ];
  }

  public function getPspName() {
    return 'civicrm';
  }

  protected function getObfuscatedIban() {
    // use first four characters
    $obfuscated = substr($this->mandate['iban'],0,4);
    // one asterisk for each character between the first and last 4
    $obfuscated .= str_repeat('*', strlen($this->mandate['iban']) - 8);
    // use last four characters
    $obfuscated .= substr($this->mandate['iban'],strlen($this->mandate['iban']) - 4);
    return wordwrap($obfuscated, 4, ' ', TRUE);
  }

  public function update(array $params) {
    if (empty($params['payment_details']['iban'])) {
      throw new Exception('Missing IBAN');
    }
    // TODO: support multiple creditors
    $creditor = (array) \CRM_Sepa_Logic_Settings::defaultCreditor();
    $cycle_days = \CRM_Sepa_Logic_Settings::getListSetting('cycledays', range(1, 28), $creditor['id']);

    $params['start_date'] = $this->getStartDate($params);
    if (!empty($params['currency'])) {
      if ($params['currency'] != $creditor['currency']) {
        throw new Exception("Invalid currency '{$params['currency']}' requested, SEPA creditor only supports '{$creditor['currency']}'");
      }
    }
    // always use creditor currency for SEPA
    $params['currency'] = $creditor['currency'];

    $contract_modification = array(
      'action'                                  => $this->isCurrentMember ? 'update' : 'revive',
      'date'                                    => $params['start_date']->format('Y-m-d'),
      'id'                                      => $this->membershipId,
      'medium_id'                               => \CRM_Core_PseudoConstant::getKey(
        'CRM_Activity_BAO_Activity',
        'medium_id',
        'web'
      ),
      'campaign_id'                             => $params['campaign_id'] ?? NULL,
      'membership_payment.defer_payment_start'  => 1,
      'membership_payment.from_ba'              => \CRM_Contract_BankingLogic::getOrCreateBankAccount(
        $this->contract['contact_id'],
        $params['payment_details']['iban']
      ),
      'membership_payment.to_ba'                => \CRM_Contract_BankingLogic::getCreditorBankAccount(),
      'membership_payment.membership_annual'    => number_format($params['amount'] * $params['frequency'], 2),
      'membership_payment.membership_frequency' => $params['frequency'],
      'membership_payment.cycle_day'            => $this->getCycleDay($cycle_days, $params),
      'check_permissions'                       => 0,
    );
    $response = civicrm_api3('Contract', 'modify', $contract_modification);
    civicrm_api3('Contract', 'process_scheduled_modifications', [
      'id'                => $this->membershipId,
      'check_permissions' => 0,
    ]);
    return reset($response['values'])['change_activity_id'];
  }

}
