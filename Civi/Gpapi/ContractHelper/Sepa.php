<?php

namespace Civi\Gpapi\ContractHelper;

class Sepa extends AbstractHelper {
  protected $mandate = [];

  public function __construct($membershipId) {
    parent::__construct($membershipId);
    $this->mandate = civicrm_api3('SepaMandate', 'getsingle', [
      'entity_table'      => 'civicrm_contribution_recur',
      'entity_id'         => $this->recurringContribution['id'],
      'check_permissions' => 0,
    ]);
  }

  public function getPaymentLabel() {
    return $this->getObfuscatedIban();
  }

  public function getPaymentDetails() {
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

}
