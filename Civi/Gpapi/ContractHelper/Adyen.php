<?php

namespace Civi\Gpapi\ContractHelper;

class Adyen extends AbstractHelper {
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
    return NULL;
  }

  public function getPaymentDetails() {
    return [
      'shopper_reference' => $this->mandate['iban'],
      'merchant_account' => $this->mandate['bic'],
    ];
  }

  public function getPspName() {
    return 'adyen';
  }

}
