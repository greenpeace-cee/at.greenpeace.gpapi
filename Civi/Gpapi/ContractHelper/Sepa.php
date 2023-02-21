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
    $next_debit_date = self::calculateNextDebitDate($params, $this->creditor);
    $rcur_opt_val = self::getOptionValue('payment_instrument', 'RCUR');
    $start_time = empty($params['payment_received']) ? time() : $next_debit_date;

    $amount = number_format($params['amount'], 2, '.', '');
    $creditor_id = (int) $this->creditor['id'];
    $currency = CRM_Utils_Array::value('currency', $params, $this->creditor['currency']);
    $cycle_day = (int) date('d', $next_debit_date);
    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $frequency_interval = (int) (12.0 / $params['frequency']);
    $payment_instrument_id = CRM_Utils_Array::value('payment_instrument', $params, $rcur_opt_val);
    $start_date = date('YmdHis', $start_time);

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
    $start_date = ($this->getStartDate($params))->format('Y-m-d');

    // Recurring contribution details

    // TODO: support multiple creditors
    $this->creditor = (array) \CRM_Sepa_Logic_Settings::defaultCreditor();

    $cycle_days = \CRM_Sepa_Logic_Settings::getListSetting(
      'cycledays',
      range(1, 28),
      $this->creditor['id']
    );

    $annual_amount = number_format($params['amount'] * $params['frequency'], 2);
    $currency = CRM_Utils_Array::value('currency', $params, $this->creditor['currency']);

    if ($currency != $this->creditor['currency']) {
      throw new Exception(
        "Invalid currency '$currency' requested, " .
        "SEPA creditor only supports '{$this->creditor['currency']}'"
      );
    }

    $bic = CRM_Utils_Array::value('bic', $payment_details);
    $contact_id = $params['contac_id'];
    $iban = $payment_details['iban'];
    $rcur_opt_val = self::getOptionValue('payment_instrument', 'RCUR');

    $cycle_day = $this->getCycleDay($cycle_days, $params);
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
      'date'                                    => $start_date,
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
    $trxn_id = CRM_Utils_Array::value('trxn_id', $params);

    $create_order_params = [
      'campaign_id'            => $this->recurringContribution['campaign_id'],
      'contact_id'             => $params['contact_id'],
      'contribution_recur_id'  => $this->recurringContribution['id'],
      'contribution_status_id' => 'Pending',
      'financial_type_id'      => $this->recurringContribution['financial_type_id'],
      'invoice_id'             => $this->recurringContribution['processor_id'],
      'payment_instrument_id'  => $this->recurringContribution['payment_instrument_id'],
      'receive_date'           => $this->membership['join_date'],
      'sequential'             => TRUE,
      'source'                 => 'OSF',
      'total_amount'           => $this->recurringContribution['amount'],
    ];

    $order_result = civicrm_api3('Order', 'create', $create_order_params);
    $contribution_id = $order_result['id'];

    $create_payment_params = [
      'contribution_id'       => $contribution_id,
      'fee_amount'            => 0.0,
      'payment_instrument_id' => $this->recurringContribution['payment_instrument_id'],
      'payment_processor_id'  => $this->recurringContribution['payment_processor_id'],
      'sequential'            => TRUE,
      'total_amount'          => $this->recurringContribution['amount'],
      'trxn_date'             => $this->membership['join_date'],
      'trxn_id'               => $trxn_id,
    ];

    civicrm_api3('Payment', 'create', $create_payment_params);

    \CRM_Utils_SepaCustomisationHooks::installment_created(
      $this->mandate['id'],
      $this->recurringContribution['id'],
      $contribution_id
    );
  }

  protected function loadAdditionalPaymentData() {
    $this->mandate = Api4\SepaMandate::get(FALSE)
      ->addWhere('entity_id', '=', $this->recurringContribution['id'])
      ->addSelect('*')
      ->execute()
      ->first();
  }

  private static function calculateNextDebitDate(array $params, array $creditor) {
    // If the first payment was completed within the ODF,
    // the next debit date should be at least one month from now
    $next_debit_date = strtotime('+1 month');

    if (empty($params['payment_received'])) {
      $buffer_days = (int) \CRM_Sepa_Logic_Settings::getSetting("pp_buffer_days");
      $frst_notice_days = (int) \CRM_Sepa_Logic_Settings::getSetting("batching.FRST.notice", $creditor['id']);
      $next_debit_date = strtotime("+ $frst_notice_days days + $buffer_days days");
    }

    if (empty($params['cycle_day'])) {
      $possible_cycle_days = \CRM_Sepa_Logic_Settings::getListSetting(
        "cycledays",
        range(1, 28),
        $creditor['id']
      );

      $cycle_day = date('d', $next_debit_date);

      while (!in_array($cycle_day, $possible_cycle_days)) {
        $next_debit_date = strtotime("+ 1 day", $next_debit_date);
        $cycle_day = date('d', $next_debit_date);
      }
    } else {
      $cycle_day = (int) $params['cycle_day'];

      while ((int) date('d', $next_debit_date) !== $cycle_day) {
        $next_debit_date = strtotime("+ 1 day", $next_debit_date);
      }
    }

    return $next_debit_date;
  }

  private function getCycleDay(array $cycleDays, array $params) {
    if (count($cycleDays) == 0) {
      throw new Exception('Must provide at least one cycle day');
    }
    // if the membership is active and current cycle_day is valid, use it
    if ($this->isActiveContract && in_array($this->recurringContribution['cycle_day'], $cycleDays)) {
      return $this->recurringContribution['cycle_day'];
    }

    $buffer_days = (int) \CRM_Sepa_Logic_Settings::getSetting("pp_buffer_days");
    $start_date = strtotime("+{$buffer_days} day", strtotime("now"));

    // consider an example membership with the following data:
    // - last successful contribution: 2020-01-03
    // - frequency: monthly
    // - status: Cancelled
    // - possible cycle days: 3, 10, 17, 25
    // - current date: 2020-01-20
    // given this example, the desired cycle day would be 3. to achieve this,
    // we need to adjust the start date used to find cycle days based on the
    // last successful contribution plus one frequency interval, otherwise we
    // would get cycle day 25 based on the current date.
    $lastContributionDate = NULL;
    if (!empty($params['transaction_details']['date'])) {
      // a donation was made in ODF
      $lastContributionDate = new \DateTime($params['transaction_details']['date']);
    }
    if (is_null($lastContributionDate) && !empty($this->membershipId)) {
      // no donation made in ODF, use the last successful contribution date
      $lastContributionDate = $this->getLatestSuccessfulMembershipPaymentDate();
    }

    if (!is_null($lastContributionDate)) {
      $earliestPossibleDebitDate = clone $lastContributionDate;
      $monthsToAdd = 12 / $params['frequency'];
      // we consider the period between $lastContributionDate and one frequency
      // interval after that date (e.g. one month for a monthly membership) to
      // be already paid, so adjust the date.
      $earliestPossibleDebitDate->add(new \DateInterval("P{$monthsToAdd}M"));
      if ($earliestPossibleDebitDate > new \DateTime("today + {$buffer_days} day")) {
        $start_date = $earliestPossibleDebitDate->getTimestamp();
      }
    }

    $safety_counter = 32;
    while (!in_array(date("d", $start_date), $cycleDays)) {
      $start_date = strtotime("+ 1 day", $start_date);
      $safety_counter -= 1;

      if ($safety_counter == 0) {
        throw new Exception("There's something wrong with the nextCycleDay method.");
      }
    }
    return date("d", $start_date);
  }

}
