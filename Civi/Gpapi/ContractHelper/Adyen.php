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
