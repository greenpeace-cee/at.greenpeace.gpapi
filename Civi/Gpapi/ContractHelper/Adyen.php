<?php

namespace Civi\Gpapi\ContractHelper;

class Adyen extends AbstractHelper {
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
    // only set mandate if one exists and it's adyen
    if (!empty($mandate) && $mandate['api.SepaCreditor.getsingle']['creditor_type'] == 'PSP') {
      $psp_name = civicrm_api3('OptionValue', 'getvalue', [
        'return'            => 'name',
        'option_group_id'   => 'sepa_file_format',
        'value'             => $mandate['api.SepaCreditor.getsingle']['sepa_file_format_id'],
        'check_permissions' => 0,
      ]);
      if ($psp_name == 'adyen') {
        $this->mandate = $mandate;
      }
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

    // 3. Derive the PSP creditor

    $creditor = $this->getCreditor($params);

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
      'payment_method.account_name'          => $params['bic'],
      'payment_method.account_reference'     => $params['iban'],
      'payment_method.adapter'               => 'psp_sepa',
      'payment_method.amount'                => $amount,
      'payment_method.campaign_id'           => $params['campaign_id'],
      'payment_method.contact_id'            => $params['contact_id'],
      'payment_method.creditor_id'           => $creditor['id'],
      'payment_method.currency'              => $currency,
      'payment_method.cycle_day'             => $cycle_day,
      'payment_method.financial_type_id'     => "2", // Membership dues
      'payment_method.frequency_interval'    => (int) (12.0 / $params['frequency']),
      'payment_method.frequency_unit'        => 'month',
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
        'reference_type' => 'NBAN_ADYEN',
      ]);
    }

    return $contract_result;
  }

  public function getPaymentLabel() {
    if (is_null($this->mandate)) {
      throw new Exception('No payment details found');
    }
    return NULL;
  }

  public function getPaymentDetails() {
    if (is_null($this->mandate)) {
      throw new Exception('No payment details found');
    }
    return [
      'shopper_reference' => $this->mandate['iban'],
      'merchant_account' => $this->mandate['bic'],
    ];
  }

  public function getPspName() {
    return 'adyen';
  }

  public function update(array $params) {
    if (empty($params['payment_details']['shopper_reference'])) {
      throw new Exception('Missing Shopper Reference');
    }
    if (empty($params['payment_details']['merchant_account'])) {
      throw new Exception('Missing Merchant Account');
    }
    $creditor = $this->getCreditor($params);
    $cycle_days = \CRM_Sepa_Logic_Settings::getListSetting('cycledays', range(1, 28), $creditor['id']);

    $params['start_date'] = $this->getStartDate($params);
    $mandateStartDate = $params['start_date'];
    if (!empty($params['transaction_details']['date'])) {
      // add already-debitted period to the start date
      // Example: a monthly contract is added and the first transaction was
      // processed online. Set the earliest possible start date to the
      // date of the transaction plus one month.
      $monthsToAdd = 12 / $params['frequency'];
      $minimumStartDate = new \DateTime($params['transaction_details']['date']);
      $minimumStartDate->add(new \Dateinterval("P{$monthsToAdd}M"));
      if ($minimumStartDate > $mandateStartDate) {
        $mandateStartDate = $minimumStartDate;
      }
      // TODO: backdate from next upcoming cycle day
    }
    $mandate = civicrm_api3('SepaMandate', 'createfull', [
      'contact_id' => $this->contract['contact_id'],
      'type' => 'RCUR',
      'iban' => $params['payment_details']['shopper_reference'],
      'bic' => $params['payment_details']['merchant_account'],
      'amount' => $params['amount'],
      'frequency_interval' => (int) (12 / $params['frequency']),
      'frequency_unit' => 'month',
      'financial_type_id' => \CRM_Core_Pseudoconstant::getKey(
        'CRM_Contribute_BAO_Contribution',
        'financial_type_id',
        'Member Dues'
      ),
      'start_date' => $mandateStartDate->format('Y-m-d'),
      'cycle_day' => $this->getCycleDay($cycle_days, $params),
      'creditor_id' => $creditor['id'],
      // 'payment_instrument_id' => \CRM_Core_Pseudoconstant::getKey(
      //   'CRM_Contribute_BAO_Contribution',
      //   'payment_instrument_id',
      //   $params['payment_instrument']
      // ),
      'campaign_id' => $params['campaign_id'] ?? NULL,
      'check_permissions' => 0,
    ]);
    $mandate = reset($mandate['values']);

    civicrm_api3('ContributionRecur', 'create', [
      'id' => $mandate['entity_id'],
      'payment_instrument_id' => \CRM_Core_Pseudoconstant::getKey(
        'CRM_Contribute_BAO_Contribution',
        'payment_instrument_id',
        $params['payment_instrument']
      )
    ]);

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
      'membership_payment.defer_payment_start'  => 0,
      'check_permissions'                       => 0,
      'membership_payment.membership_recurring_contribution' => $mandate['entity_id'],
    );
    $response = civicrm_api3('Contract', 'modify', $contract_modification);
    civicrm_api3('Contract', 'process_scheduled_modifications', [
      'id'                => $this->membershipId,
      'check_permissions' => 0,
    ]);
    return reset($response['values'])['change_activity_id'];
  }

  private function getCreditor($params) {
    $fileFormat = civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'value',
      'option_group_id' => 'sepa_file_format',
      'name' => 'adyen',
    ]);
    $creditorLookup = [
      'creditor_type' => 'PSP',
      'sepa_file_format_id' => $fileFormat,
    ];
    if (empty($params['currency'])) {
      $config = \CRM_Core_Config::singleton();
      $creditorLookup['currency'] = $config->defaultCurrency;
    }
    else {
      $creditorLookup['currency'] = $params['currency'];
    }
    return civicrm_api3('SepaCreditor', 'getsingle', $creditorLookup);
  }

}
