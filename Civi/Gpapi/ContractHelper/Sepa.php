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
