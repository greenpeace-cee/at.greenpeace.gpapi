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

  abstract public function create(array $params);

  abstract public function getPaymentLabel();

  abstract public function getPaymentDetails();

  abstract public function getPspName();

  abstract public function update(array $params);

  public function getContractDetails() {
    if (!in_array($this->recurringContribution['frequency_unit'], ['month', 'year'])) {
      throw new Exception('Invalid recurring contribution frequency unit');
    }
    $frequencyDividend = $this->recurringContribution['frequency_unit'] == 'month' ? 12 : 1;
    $this->contractDetails = [
      'frequency' => $frequencyDividend / $this->recurringContribution['frequency_interval'],
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

  public static function getReferrerContactID(array $params) {
    if (empty($params['referrer_contact_id'])) return NULL;

    try {
      return civicrm_api3('Contact', 'identify', [
        'identifier_type' => 'internal',
        'identifier'      => (int) $params['referrer_contact_id'],
      ])['id'];
    } catch (CiviCRM_API3_Exception $e) {
      civicrm_api3('Activity', 'create', [
        'activity_type_id'  => 'manual_update_required',
        'target_id'         => $params['contact_id'],
        'subject'           => 'Invalid Referrer Submitted',
        'details'           => 'Membership was submitted with a referrer, but no contact was found for value "' . $params['referrer_contact_id'] . '"',
        'status_id'         => 'Scheduled',
        'check_permissions' => 0,
      ]);

      CRM_Core_Error::debug_log_message("OSF.contract: Unable to find referrer {$params['referrer_contact_id']}: " . $e->getMessage());
    }

    return NULL;
  }

  public static function calculateNextDebitDate(array $params, array $creditor) {
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

  public static function createInitialContribution (array $params) {
    civicrm_api3('EntityTag', 'create', [
      'tag_id'       => _civicrm_api3_o_s_f_contract_getPSPTagId(),
      'entity_table' => 'civicrm_activity',
      'entity_id'    => $params['activity_id'],
    ]);

    $contribution_status_id = (int) CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution',
      'contribution_status_id',
      'Completed'
    );

    $contribution_data = [
      'total_amount'           => $params['rcur_amount'],
      'currency'               => $params['rcur_currency'],
      'receive_date'           => $params['member_since'],
      'contact_id'             => $params['contact_id'],
      'contribution_recur_id'  => $params['rcur_id'],
      'financial_type_id'      => $params['financial_type_id'],
      'campaign_id'            => $params['campaign_id'],
      'is_test'                => $params['is_test'],
      'payment_instrument_id'  => $params['payment_instrument_id'],
      'contribution_status_id' => $contribution_status_id,
      'trxn_id'                => $params['trxn_id'],
      'source'                 => 'OSF',
    ];

    $to_ba_field_id = civicrm_api3('CustomField', 'getvalue', [
      'name'            => 'to_ba',
      'custom_group_id' => 'contribution_information',
      'return'          => 'id'
    ]);

    $contribution_data["custom_$to_ba_field_id"] = self::getBankAccount([
      'contact_id' => \GPAPI_GP_ORG_CONTACT_ID,
      'iban'       => $params['creditor_iban'],
    ]);

    $contribution_result = civicrm_api3('Contribution', 'create', $contribution_data);

    CRM_Utils_SepaCustomisationHooks::installment_created(
      $params['sepa_mandate_id'],
      $params['rcur_id'],
      $contribution_result['id']
    );

    return $contribution_result;
  }

  public static function getBankAccount (array $params) {
    try {
      $ba_reference_type_id = civicrm_api3('OptionValue', 'getvalue', [
        'is_active'       => 1,
        'option_group_id' => 'civicrm_banking.reference_types',
        'value'           => 'IBAN',
        'return'          => 'id',
      ]);

      $ba_references = civicrm_api3('BankingAccountReference', 'get', [
        'reference'         => $params['iban'],
        'reference_type_id' => $ba_reference_type_id,
        'option.limit'      => 0,
        'return'            => ['id'],
      ])['values'];

      $ba_ids = array_map(function ($ref) { return $ref['id']; }, $ba_references);

      if (empty($ba_ids)) return NULL;

      $contact_bank_accounts = civicrm_api3('BankingAccount', 'get', [
        'id'           => [ 'IN' => $ba_ids ],
        'contact_id'   => $params['contact_id'],
        'option.limit' => 1,
      ]);

      if ((int) $contact_bank_accounts['count'] === 0) return NULL;

      return $contact_bank_accounts['values'][0]['id'];
    } catch (\Exception $ex) {
      CRM_Core_Error::debug_log_message(
        "OSF.contract: Unable to find bank account for {$params['iban']}: " . $ex->getMessage()
      );
    }

    return NULL;
  }

  public static function createBankAccount (array $params) {
    $bank_account_data = [
      'country' => substr($params['iban'], 0, 2),
      'BIC'     => $params['bic'],
    ];

    $bank_account = civicrm_api3('BankingAccount', 'create', [
      'contact_id'  => $params['contact_id'],
      'description' => "Bulk Importer",
      'data_parsed' => json_encode($bank_account_data),
    ]);

    $ba_reference_type_id = civicrm_api3('OptionValue', 'getvalue', [
      'is_active'       => 1,
      'option_group_id' => 'civicrm_banking.reference_types',
      'value'           => $params['reference_type'],
      'return'          => 'id',
    ]);

    $bank_account_reference = civicrm_api3('BankingAccountReference', 'create', [
      'ba_id'             => $bank_account['id'],
      'reference'         => $params['iban'],
      'reference_type_id' => $ba_reference_type_id,
    ]);

    return $bank_account;
  }

  public static function createReferrerOfRelationship (array $params) {
    $referrer_rel_type_id = civicrm_api3('RelationshipType', 'getvalue', [
      'return'   => 'id',
      'name_a_b' => 'Referrer of',
    ]);

    // it is necessary to wrap Relationship.create in a nested transaction to
    // prevent a rollback from bubbling up to the main API transaction when a
    // "Duplicate Relationship" exception occurs. This would otherwise cause
    // us to return a success response even though a rollback is performed.
    \CRM_Core_Transaction::create(TRUE)->run(function($subTx) use ($params, $referrer_rel_type_id) {
      try {
        civicrm_api3('Relationship', 'create', [
          'contact_id_a'         => $params['referrer_id'],
          'contact_id_b'         => $params['contact_id'],
          'relationship_type_id' => $referrer_rel_type_id,
          'start_date'           => date('Ymd'),
        ]);
      } catch (\CiviCRM_API3_Exception $e) {
        if ($e->getMessage() === 'Duplicate Relationship') {
          civicrm_api3('Activity', 'create', [
            'activity_type_id'  => 'manual_update_required',
            'target_id'         => [$params['contact_id'], $params['referrer_id']],
            'subject'           => 'Potential Referrer Fraud',
            'details'           => 'Contact already referred a membership to the referee.',
            'status_id'         => 'Scheduled',
            'check_permissions' => 0,
          ]);

          \CRM_Core_Error::debug_log_message(
            "OSF.contract: Potential Referrer Fraud with contacts {$params['contact_id']} and {$params['referrer_id']}"
          );
        } else {
          throw $e;
        }
      }
    });

    $membership_data = [
      'id'                  => $params['membership_id'],
      'membership_referrer' => $params['referrer_id'],
      'skip_handler'        => TRUE, // CE should ignore this change
    ];

    \CRM_Gpapi_Processor::resolveCustomFields($membership_data, ['membership_referral']);

    return civicrm_api3('Membership', 'create', $membership_data);
  }

}
