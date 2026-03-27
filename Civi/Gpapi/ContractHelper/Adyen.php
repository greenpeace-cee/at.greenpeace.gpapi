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

    $campaign_id = $params['campaign_id'] ?? NULL;
    $contact_id = $params['contact_id'];
    $membership_type_id = $params['membership_type_id'] ?? NULL;

    // --- Recurring contribution details --- //

    $amount = number_format($params['amount'], 2, '.', '');
    $currency = $params['currency'] ?? NULL;
    $cycle_day = $params['cycle_day'] ?? NULL;
    $financial_type_id = self::getFinancialTypeID('Member Dues');
    $frequency_interval = (int) (12.0 / $params['frequency']);
    $payment_instrument_id = $params['payment_instrument'] ?? NULL;

    // --- PSP result data --- //

    $psp_result_data = $params['psp_result_data'] ?? [];
    $event_date = $psp_result_data['eventDate'] ?? date('Y-m-d');
    $join_date = date('Ymd', strtotime($event_date));
    $start_date = date('Ymd', strtotime($event_date));

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
      'payment_method.adapter'                  => 'adyen',
      'payment_method.amount'                   => $amount,
      'payment_method.campaign_id'              => $campaign_id,
      'payment_method.contact_id'               => $contact_id,
      'payment_method.currency'                 => $currency,
      'payment_method.cycle_day'                => $cycle_day,
      'payment_method.financial_type_id'        => $financial_type_id,
      'payment_method.frequency_interval'       => $frequency_interval,
      'payment_method.frequency_unit'           => 'month',
      'payment_method.payment_instrument_id'    => $payment_instrument_id,
      'sequential'                              => $sequential,
      'source'                                  => 'OSF',
      'start_date'                              => $start_date,
    ];
    $create_contract_params = array_merge($create_contract_params, $this->getPaymentMethodDataFromPayload($params));

    $contract_result = civicrm_api3('Contract', 'create', $create_contract_params);

    $this->loadContract($contract_result['id']);
  }

  public function update(array $params) {
    $payment_details = $params['payment_details'] ?? [];

    if (empty($payment_details['shopper_reference'])) {
      throw new Exception('Missing Shopper Reference');
    }

    if (empty($payment_details['stored_payment_method_id'])) {
      throw new Exception('Missing Stored Payment Method ID');
    }

    if (empty($payment_details['merchant_account'])) {
      throw new Exception('Missing Merchant Account');
    }

    $payment_processor_id = Api4\PaymentProcessor::get(FALSE)
      ->addWhere('payment_processor_type_id:name', '=', 'Adyen')
      ->addWhere('name', '=', $payment_details['merchant_account'])
      ->addSelect('id')
      ->execute()
      ->first()['id'] ?? NULL;

    if (empty($payment_processor_id)) {
      throw new Exception('Missing or invalid merchant account');
    }

    // General membership details

    $campaign_id = $params['campaign_id'] ?? NULL;
    $medium_id = self::getOptionValue('encounter_medium', 'web');
    $membership_id = $params['contract_id'];
    $membership_type_id = $params['membership_type'] ?? NULL;
    $modify_date = $this->getModifyDate($params)->format('Y-m-d');

    // Recurring contribution details

    $annual_amount = number_format($params['amount'] * $params['frequency'], 2);
    $cycle_day = $params['cycle_day'] ?? NULL;
    $frequency = $params['frequency'];
    $payment_instrument_id = $params['payment_instrument'] ?? NULL;

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
      'payment_method.payment_processor_id'     => $payment_processor_id,
      'payment_method.shopper_reference'        => $payment_details['shopper_reference'],
      'payment_method.stored_payment_method_id' => $payment_details['stored_payment_method_id'],
    ];

    $response = civicrm_api3('Contract', 'modify', $modify_contract_params);

    civicrm_api3('Contract', 'process_scheduled_modifications', [
      'id'                => $membership_id,
      'check_permissions' => 0,
    ]);

    $this->loadContract($membership_id);
  }

  /**
   * @throws \Exception
   */
  private function getPaymentMethodDataFromPayload(array $params): array {
    $psp_result_data = $params['psp_result_data'] ?? $params['payment_details']['psp_result_data'] ?? [];
    $additional_psp_data = $psp_result_data['additionalData'] ?? [];
    $card_holder_name = self::getCardHolderName($additional_psp_data);
    $account_number = self::getAccountNumber($psp_result_data);
    $billing_first_name = $card_holder_name[0];
    $billing_last_name = $card_holder_name[1];
    $expiry_date = self::getExpiryDate($additional_psp_data);
    $ip_address = $additional_psp_data['shopperIP'] ?? NULL;
    $payment_processor_id = self::getPaymentProcessorID($params);
    $shopper_email = $additional_psp_data['shopperEmail'] ?? NULL;

    $shopper_reference = $additional_psp_data['recurring.shopperReference']
      ?? $additional_psp_data['shopperReference']
      ?? $params['payment_details']['shopper_reference']
      ?? NULL;

    $stored_pm_id = $additional_psp_data['recurring.recurringDetailReference']
      ?? $params['payment_details']['stored_payment_method_id']
      ?? NULL;

    return [
      'payment_method.account_number'           => $account_number,
      'payment_method.billing_first_name'       => $billing_first_name,
      'payment_method.billing_last_name'        => $billing_last_name,
      'payment_method.email'                    => $shopper_email,
      'payment_method.expiry_date'              => $expiry_date,
      'payment_method.ip_address'               => $ip_address,
      'payment_method.payment_processor_id'     => $payment_processor_id,
      'payment_method.shopper_reference'        => $shopper_reference,
      'payment_method.stored_payment_method_id' => $stored_pm_id,
    ];
  }

  public function createInitialContribution(array $params) {
    $psp_result_data = $params['psp_result_data'] ?? [];
    $merchant_reference = $psp_result_data['merchantReference'] ?? NULL;
    $psp_reference = $psp_result_data['pspReference'] ?? NULL;

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
    $expiry_date = $additional_psp_data['expiryDate'] ?? NULL;

    if (empty($expiry_date)) return NULL;

    [$month, $year] = explode('/', $expiry_date);
    $expiry_date = new DateTime("$year-$month-01");
    $expiry_date->add(new DateInterval('P1M'));
    $expiry_date = new DateTime($expiry_date->format('Y-m-01'));
    $expiry_date->sub(new DateInterval('P1D'));

    return $expiry_date->format('Ymd');
  }

  private static function getPaymentProcessorID(array $params) {
    $processor_type = 'Adyen';
    $name = $params['psp_result_data']['merchantAccountCode'] ?? $params['payment_details']['merchant_account'] ?? NULL;

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
