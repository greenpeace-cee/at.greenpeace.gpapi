<?php

namespace Civi\Gpapi\ContractHelper;

use \Civi\Api4;
use \Civi\Core\Event\GenericHookEvent;
use \CRM_Utils_Array;
use \DateInterval;
use \DateTime;
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

    $amount = number_format($params['amount'], 2, '.', '');
    $currency = CRM_Utils_Array::value('currency', $params);
    $cycle_day = CRM_Utils_Array::value('cycle_day', $params);
    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $frequency_interval = (int) (12.0 / $params['frequency']);
    $payment_instrument_id = CRM_Utils_Array::value('payment_instrument', $params);

    // --- PSP result data --- //

    $psp_result_data = CRM_Utils_Array::value('psp_result_data', $params, []);
    $additional_psp_data = CRM_Utils_Array::value('additionalData', $psp_result_data, []);
    $card_holder_name = self::getCardHolderName($additional_psp_data);
    $event_date = CRM_Utils_Array::value('eventDate', $psp_result_data, date('Y-m-d'));

    $account_number = self::getAccountNumber($psp_result_data);
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
    ) ?? CRM_Utils_Array::value(
      'shopperReference',
      $additional_psp_data
    );

    $stored_pm_id = CRM_Utils_Array::value(
      'recurring.recurringDetailReference',
      $additional_psp_data
    );

    $start_date= date('Ymd', strtotime($event_date));

    $default_cycle_day = min(
      (int) (new DateTimeImmutable($event_date))->format('d'),
      max(\CRM_Contract_PaymentAdapter_Adyen::cycleDays())
    );

    $cycle_day = isset($cycle_day) ? $cycle_day : $default_cycle_day;

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
    $modify_date = $this->getModifyDate($params)->format('Y-m-d');

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
      'date'                                    => $modify_date,
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
    $psp_result_data = CRM_Utils_Array::value('psp_result_data', $params, []);
    $merchant_reference = CRM_Utils_Array::value('merchantReference', $psp_result_data);
    $psp_reference = CRM_Utils_Array::value('pspReference', $psp_result_data);

    $create_order_params = [
      'campaign_id'            => $this->recurringContribution['campaign_id'],
      'contact_id'             => $params['contact_id'],
      'contribution_recur_id'  => $this->recurringContribution['id'],
      'contribution_status_id' => 'Pending',
      'financial_type_id'      => $this->recurringContribution['financial_type_id'],
      'invoice_id'             => $merchant_reference,
      'payment_instrument_id'  => $this->recurringContribution['payment_instrument_id'],
      'receive_date'           => $this->membership['join_date'],
      'sequential'             => TRUE,
      'source'                 => 'OSF',
      'total_amount'           => $this->recurringContribution['amount'],
      'currency'               => $this->recurringContribution['currency'],
    ];

    $order_result = civicrm_api3('Order', 'create', $create_order_params);
    $contribution_id = $order_result['id'];

    civicrm_api3('MembershipPayment', 'create', [
      'contribution_id' => $contribution_id,
      'membership_id'   => $this->membership['id'],
    ]);

    $create_payment_params = [
      'contribution_id'                   => $contribution_id,
      'fee_amount'                        => 0.0,
      'is_send_contribution_notification' => FALSE,
      'payment_instrument_id'             => $this->recurringContribution['payment_instrument_id'],
      'payment_processor_id'              => $this->recurringContribution['payment_processor_id'],
      'sequential'                        => TRUE,
      'total_amount'                      => $this->recurringContribution['amount'],
      'trxn_date'                         => $this->membership['join_date'],
      'trxn_id'                           => $psp_reference,
      'currency'                          => $this->recurringContribution['currency'],
    ];

    civicrm_api3('Payment', 'create', $create_payment_params);

    Api4\ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $this->recurringContribution['id'])
      ->addValue('start_date', $psp_result_data['eventDate'] ?? date('Y-m-d'))
      ->execute();

    $hookEvent = GenericHookEvent::create([
      'contribution_recur_id' => $this->recurringContribution['id'],
      'cycle_day'             => $this->recurringContribution['cycle_day'],
      'frequency_interval'    => $this->recurringContribution['frequency_interval'],
      'frequency_unit'        => $this->recurringContribution['frequency_unit'],
      'newDate'               => $this->recurringContribution['next_sched_contribution_date'],
      'originalDate'          => $this->recurringContribution['next_sched_contribution_date'],
    ]);

    \Civi::dispatcher()->dispatch('civi.recur.nextschedcontributiondatealter', $hookEvent);
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
    $this->paymentProcessor = Api4\PaymentProcessor::get(FALSE)
      ->addWhere('id', '=', $this->recurringContribution['payment_processor_id'])
      ->addSelect('*')
      ->execute()
      ->first();

    $this->paymentToken = Api4\PaymentToken::get(FALSE)
      ->addWhere('id', '=', $this->recurringContribution['payment_token_id'])
      ->addSelect('*')
      ->execute()
      ->first();
  }

  private static function getAccountNumber(array $psp_data) {
    $card_summary = $psp_data['additionalData']['cardSummary'] ?? NULL;
    $pm = $psp_data['paymentMethod'] ?? $psp_data['additionalData']['paymentMethod'] ?? NULL;

    if (!empty($card_summary) && !empty($pm)) {
      return ucfirst($pm) . ": $card_summary";
    }

    if (!empty($psp_data['additionalData']['iban'])) {
      return $psp_data['additionalData']['iban'];
    }

    return NULL;
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
    $expiry_date = CRM_Utils_Array::value('expiryDate', $additional_psp_data);

    if (empty($expiry_date)) return NULL;

    list($month, $year) = explode('/', $expiry_date);
    $expiry_date = new DateTime("$year-$month-01");
    $expiry_date->add(new DateInterval('P1M'));
    $expiry_date = new DateTime($expiry_date->format('Y-m-01'));
    $expiry_date->sub(new DateInterval('P1D'));

    return $expiry_date->format('Ymd');
  }

  private static function getPaymentProcessorID(array $psp_result_data) {
    $processor_type = 'Adyen';
    $name = CRM_Utils_Array::value('merchantAccountCode', $psp_result_data);

    if (empty($name)) {
      throw new \Exception("Missing PSP parameter 'merchantAccountCode'");
    }

    $payment_processor = Api4\PaymentProcessor::get(FALSE)
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
