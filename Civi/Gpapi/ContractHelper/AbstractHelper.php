<?php

namespace Civi\Gpapi\ContractHelper;

abstract class AbstractHelper {
  protected $membershipId;
  protected $contract = [];
  protected $recurringContribution = [];
  protected $membershipType;
  protected $membershipStatus;
  protected $contractDetails = [];

  public function __construct($membershipId) {
    $this->membershipId = $membershipId;
    $recurring_contribution_field = \CRM_Contract_CustomData::getCustomFieldKey(
      'membership_payment',
      'membership_recurring_contribution'
    );
    $this->contract = civicrm_api3('Contract', 'getsingle', [
      'id' => $membershipId,
      'api.MembershipType.getsingle' => [],
      'api.MembershipStatus.getsingle' => ['id' => '$value.status_id'],
      'api.ContributionRecur.getsingle' => [
        'id' => '$value.' . $recurring_contribution_field
      ],
    ]);
    $this->membershipType = $this->contract['api.MembershipType.getsingle']['name'];
    $this->membershipStatus = $this->contract['api.MembershipStatus.getsingle']['name'];
    $this->recurringContribution = $this->contract['api.ContributionRecur.getsingle'];
  }

  abstract public function getPaymentLabel();

  abstract public function getPaymentDetails();

  abstract public function getPspName();

  public function getContractDetails() {
    $this->contractDetails = [
      'frequency' => $this->recurringContribution['frequency_interval'],
      'amount' => $this->recurringContribution['amount'],
      'annual_amount' => $this->calculateAnnualAmount(
        $this->recurringContribution['amount'],
        $this->recurringContribution['frequency_unit'],
        $this->recurringContribution['frequency_interval']
      ),
      'cycle_day' => $this->recurringContribution['cycle_day'],
      'currency' => $this->recurringContribution['currency'],
      'membership_type' => $this->membershipType,
      'status' => $this->membershipStatus,
      'payment_instrument' => \CRM_Core_PseudoConstant::getName(
        'CRM_Contribute_BAO_ContributionRecur',
        'payment_instrument_id',
        $this->recurringContribution['payment_instrument_id']
      ),
      'payment_service_provider' => $this->getPspName(),
      'payment_label' => $this->getPaymentLabel(),
      'payment_details' => $this->getPaymentDetails(),
    ];
    return $this->contractDetails;
  }

  protected function calculateAnnualAmount($amount, $unit, $interval) {
    $unitMap = [
      'year' => 1,
      'month' => 12,
    ];
    return $amount * $unitMap[$unit] / $interval;
  }

}
