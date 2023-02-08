<?php

namespace Civi\Gpapi\ContractHelper;

use \Civi\Api4;
use \CRM_Utils_Array;
use \DateTimeImmutable;

class Adyen extends AbstractHelper {
  const PSP_NAME = 'adyen';

  private $paymentProcessor;
  private $paymentToken;

  public function create (array $params) {

    // --- General membership details --- //

    $campaign_id = (int) CRM_Utils_Array::value('campaign_id', $params);
    $contact_id = $params['contact_id'];
    $membership_type_id = CRM_Utils_Array::value('membership_type_id', $params);

    // --- Recurring contribution details --- //

    $next_debit_date = self::calculateNextDebitDate($params);

    $amount = number_format($params['amount'], 2, '.', '');
    $currency = CRM_Utils_Array::value('currency', $params);
    $cycle_day = (int) date('d', $next_debit_date);
    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $frequency_interval = (int) (12.0 / $params['frequency']);
    $payment_instrument_id = CRM_Utils_Array::value('payment_instrument', $params);

    // --- PSP result data --- //

    $psp_result_data = CRM_Utils_Array::value('psp_result_data', $params, []);
    $additional_psp_data = CRM_Utils_Array::value('additionalData', $psp_result_data, []);
    $card_holder_name = self::getCardHolderName($additional_psp_data);
    $event_date = CRM_Utils_Array::value('eventDate', $psp_result_data, date('Y-m-d'));

    $account_number = self::getAccountNumber($additional_psp_data);
    $billing_first_name = $card_holder_name[0];
    $billing_last_name = $card_holder_name[1];
    $expiry_date = self::getExpiryDate($additional_psp_data);
    $ip_address = CRM_Utils_Array::value('shopperIP', $additional_psp_data);
    $join_date = date('Ymd', strtotime($event_date));
    $payment_processor_id = self::getPaymentProcessorID($psp_result_data);
    $shopper_email = CRM_Utils_Array::value('shopperEmail', $additional_psp_data);

    $shopper_reference = CRM_Utils_Array::value(
      'recurring.shopperReference',
      $additional_psp_data
    );

    $stored_pm_id = CRM_Utils_Array::value(
      'recurring.recurringDetailReference',
      $additional_psp_data
    );

    $start_date= date('Ymd', strtotime($event_date));

    // --- API options --- //

    $sequential = (int) !empty($params['sequential']);

    $create_contract_params = [
      'campaign_id'                             => $campaign_id,
      'contact_id'                              => $contact_id,
      'join_date'                               => $join_date,
      'membership_type_id'                      => $membership_type_id,
      'payment_method.account_number'           => $account_number,
      'payment_method.adapter'                  => 'adyen',
      'payment_method.amount'                   => $amount,
      'payment_method.billing_first_name'       => $billing_first_name,
      'payment_method.billing_last_name'        => $billing_last_name,
      'payment_method.campaign_id'              => $campaign_id,
      'payment_method.contact_id'               => $contact_id,
      'payment_method.currency'                 => $currency,
      'payment_method.cycle_day'                => $cycle_day,
      'payment_method.email'                    => $shopper_email,
      'payment_method.expiry_date'              => $expiry_date,
      'payment_method.financial_type_id'        => $financial_type_id,
      'payment_method.frequency_interval'       => $frequency_interval,
      'payment_method.frequency_unit'           => 'month',
      'payment_method.ip_address'               => $ip_address,
      'payment_method.payment_instrument_id'    => $payment_instrument_id,
      'payment_method.payment_processor_id'     => $payment_processor_id,
      'payment_method.shopper_reference'        => $shopper_reference,
      'payment_method.stored_payment_method_id' => $stored_pm_id,
      'sequential'                              => $sequential,
      'source'                                  => 'OSF',
      'start_date'                              => $start_date,
    ];

    $contract_result = civicrm_api3('Contract', 'create', $create_contract_params);

    $this->loadContract($contract_result['id']);
  }

  public function update(array $params) {
    $payment_details = CRM_Utils_Array::value('payment_details', $params);

    if (empty($payment_details['shopper_reference'])) {
      throw new Exception('Missing Shopper Reference');
    }

    if (empty($payment_details['merchant_account'])) {
      throw new Exception('Missing Merchant Account');
    }

    // General membership details

    $campaign_id = CRM_Utils_Array::value('campaign_id', $params);
    $medium_id = self::getOptionValue('encounter_medium', 'web');
    $membership_id = $params['contract_id'];
    $membership_type_id = CRM_Utils_Array::value('membership_type', $params);
    $start_date = ($this->getStartDate($params))->format('Y-m-d');

    // Recurring contribution details

    $annual_amount = number_format($params['amount'] * $params['frequency'], 2);
    $cycle_day = CRM_Utils_Array::value('cycle_day', $params);
    $frequency = $params['frequency'];
    $payment_instrument_id = CRM_Utils_Array::value('payment_instrument', $params);

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
      'membership_payment.membership_annual'    => $annual_amount,
      'membership_payment.membership_frequency' => $frequency,
      'membership_type_id'                      => $membership_type_id,
      'payment_method.adapter'                  => 'adyen',
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

  public function createInitialContribution(array $params) {
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
  }

  protected function getPaymentDetails() {
    return [
      'merchant_account'         => $this->paymentProcessor['name'],
      'shopper_reference'        => $this->recurringContribution['processor_id'],
      'stored_payment_method_id' => $this->paymentToken['token'],
    ];
  }

  protected function getPaymentLabel() {
    $account_number = $this->paymentToken['masked_account_number'];
    $masked_digits = preg_replace('/\d/', '*', substr($account_number, 0, -4));
    $last_digits = substr($account_number, -4);
    $masked_account_number = $masked_digits . $last_digits;

    $expiry_date = new DateTimeImmutable($this->paymentToken['expiry_date']);
    $fmt_exp_date = $expiry_date->format('m/Y');

    return "$masked_account_number ($fmt_exp_date)";
  }

  protected function getPspName() {
    return self::PSP_NAME;
  }

  protected function loadAdditionalPaymentData() {
    $this->paymentProcessor = Api4\PaymentProcessor::get()
      ->addWhere('id', '=', $this->recurringContribution['payment_processor_id'])
      ->addSelect('*')
      ->execute()
      ->first();

    $this->paymentToken = Api4\PaymentToken::get()
      ->addWhere('id', '=', $this->recurringContribution['payment_token_id'])
      ->addSelect('*')
      ->execute()
      ->first();
  }

  private static function calculateNextDebitDate(array $params) {
    $next_debit_date = strtotime('+1 month');
    $cycle_day = (int) CRM_Utils_Array::value('cycle_day', $params, 0);

    if (empty($cycle_day)) {
      $possible_cycle_days = \CRM_Contract_PaymentAdapter_Adyen::cycleDays();
      $cycle_day = (int) date('d', $next_debit_date);

      while (!in_array($cycle_day, $possible_cycle_days)) {
        $next_debit_date = strtotime("+ 1 day", $next_debit_date);
        $cycle_day = (int) date('d', $next_debit_date);
      }
    } else {
      while ((int) date('d', $next_debit_date) !== $cycle_day) {
        $next_debit_date = strtotime("+ 1 day", $next_debit_date);
      }
    }

    return $next_debit_date;
  }

  private static function getAccountNumber(array $additional_psp_data) {
    $card_summary = CRM_Utils_Array::value('cardSummary', $additional_psp_data);
    $pm_variant = CRM_Utils_Array::value('paymentMethodVariant', $additional_psp_data);

    if (empty($card_summary) || empty($pm_variant)) return NULL;

    return ucfirst($pm_variant) . ": $card_summary";
  }

  private static function getCardHolderName(array $additional_psp_data) {
    if (empty($additional_psp_data['cardHolderName'])) return [NULL, NULL];

    $cardHolderName = $additional_psp_data['cardHolderName'];

    if ($cardHolderName === 'Checkout Shopper PlaceHolder') return [NULL, NULL];

    $cardHolderName = explode(' ', $cardHolderName);

    if (count($cardHolderName) < 2) return [$cardHolderName[0], NULL];

    $firstName = reset($cardHolderName);
    $lastName = end($cardHolderName);

    return [
      empty($firstName) ? NULL : $firstName,
      empty($lastName) ? NULL : $lastName,
    ];
  }

  private static function getExpiryDate(array $additional_psp_data) {
    $expiryDate = CRM_Utils_Array::value('expiryDate', $additional_psp_data);

    if (empty($expiryDate)) return NULL;

    list($month, $year) = explode('/', $expiryDate);
    
    return "{$year}{$month}01";
  }

  private static function getPaymentProcessorID(array $psp_result_data) {
    $processor_type = 'Adyen';
    $name = CRM_Utils_Array::value('merchantAccountCode', $psp_result_data);

    if (empty($name)) {
      throw new \Exception("Missing PSP parameter 'merchantAccountCode'");
    }

    $payment_processor = Api4\PaymentProcessor::get()
      ->addWhere('payment_processor_type_id:name', '=', $processor_type)
      ->addWhere('name', '=', $name)
      ->addSelect('id')
      ->execute()
      ->first();

    if (empty($payment_processor)) {
      throw new \Exception("Payment processor of type '$processor_type' and name '$name' not found");
    }

    return $payment_processor['id'];
  }

}
