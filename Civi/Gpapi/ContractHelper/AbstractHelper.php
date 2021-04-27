<?php

namespace Civi\Gpapi\ContractHelper;

abstract class AbstractHelper {
  protected $membershipId;
  protected $contract;
  protected $recurringContribution;
  protected $membershipType;
  protected $membershipStatus;
  protected $contractDetails;
  protected $isCurrentMember;

  public function __construct($membershipId = NULL) {
    $this->membershipId = $membershipId;
    if (!is_null($membershipId)) {
      $this->loadContract();
      $this->loadPaymentDetails();
    }
  }

  protected function loadContract() {
    $recurring_contribution_field = \CRM_Contract_CustomData::getCustomFieldKey(
      'membership_payment',
      'membership_recurring_contribution'
    );
    $this->contract = civicrm_api3('Contract', 'getsingle', [
      'id' => $this->membershipId,
      'api.MembershipType.getsingle' => [],
      'api.MembershipStatus.getsingle' => ['id' => '$value.status_id'],
      'api.ContributionRecur.get' => [
        'id' => '$value.' . $recurring_contribution_field
      ],
    ]);
    if (empty($this->contract[$recurring_contribution_field]) || empty($this->contract['api.ContributionRecur.get']['values'][0]['id'])) {
      throw new Exception('No payment method associated with contract', Exception::PAYMENT_METHOD_INVALID);
    }
    $this->membershipType = $this->contract['api.MembershipType.getsingle']['name'];
    $this->membershipStatus = $this->contract['api.MembershipStatus.getsingle']['name'];
    $this->isCurrentMember = (bool) $this->contract['api.MembershipStatus.getsingle']['is_current_member'];
    $this->recurringContribution = $this->contract['api.ContributionRecur.get']['values'][0];
  }

  abstract protected function loadPaymentDetails();

  abstract public function getPaymentLabel();

  abstract public function getPaymentDetails();

  abstract public function getPspName();

  abstract public function update(array $params);

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

  protected function getCycleDay(array $cycleDays, array $params, \DateTime $lastContributionDate = NULL) {
    if (count($cycleDays) == 0) {
      throw new Exception('Must provide at least one cycle day');
    }
    // if the membership is active and current cycle_day is valid, use it
    if ($this->isCurrentMember && in_array($this->recurringContribution['cycle_day'], $cycleDays)) {
      return $this->recurringContribution['cycle_day'];
    }
    if (!empty($params['transaction_details']['date'])) {
      $lastContributionDate = new \DateTime($params['transaction_details']['date']);
    }
    if (is_null($lastContributionDate) && !empty($this->membershipId)) {
      $lastContributionDate = $this->getLatestSuccessfulMembershipPaymentDate();
    }
    $safety_counter = 32;
    $start_date = strtotime("+{$buffer_days} day", strtotime("now"));

    while (!in_array(date("d", $start_date), $cycle_days)) {
      $start_date = strtotime("+ 1 day", $start_date);
      $safety_counter -= 1;

      if ($safety_counter == 0) {
        throw new Exception("There's something wrong with the nextCycleDay method.");
      }
    }
    // TODO: handle revive
  }

  protected function getStartDate(array $params) {
    // start with current date
    $startDate = new \DateTime('today');
    if (!empty($params['start_date'])) {
      // a specific start date was requested, try using it
      $startDate = new \DateTime($params['start_date']);
    }
    if ($startDate < new \DateTime()) {
      // requested start date is in the past, falling back to current date
      $startDate = new \DateTime('today');
    }
    if (!empty(\Civi::settings()->get("contract_minimum_change_date"))) {
      // CE's minimum change date is set
      $minimumChangeDate = new \DateTime(\Civi::settings()->get("contract_minimum_change_date"));
      // add one day so we don't re-debit on an already executed debit date
      $minimumChangeDate->add(new \DateInterval('P1D'));
      if ($startDate < $minimumChangeDate) {
        // minimum change date is after start date, falling back to it
        $startDate = $minimumChangeDate;
      }
    }
    return $startDate;
  }

  protected function getLatestSuccessfulMembershipPaymentDate() {
    $date = \CRM_Core_DAO::singleValueQuery(
      "SELECT MAX(receive_date) AS receive_date
       FROM civicrm_membership_payment mp
       JOIN civicrm_contribution ctr on ctr.id = mp.contribution_id
       WHERE mp.membership_id = %1
       AND contribution_status_id IN (%2, %3, %4)",
      [
        1 => [$this->membershipId, 'Integer'],
        2 => [
          \CRM_Core_PseudoConstant::getKey(
            'CRM_Contribute_BAO_Contribution',
            'contribution_status_id',
            'Pending'
          ),
          'Integer'
        ],
        3 => [
          \CRM_Core_PseudoConstant::getKey(
            'CRM_Contribute_BAO_Contribution',
            'contribution_status_id',
            'In Progress'
          ),
          'Integer'
        ],
        4 => [
          \CRM_Core_PseudoConstant::getKey(
            'CRM_Contribute_BAO_Contribution',
            'contribution_status_id',
            'Completed'
          ),
          'Integer'
        ],
      ]
    );

    if (!empty($date)) {
      return new \DateTime($date);
    }

    return NULL;
  }

}
