<?php

namespace Civi\Gpapi\ContractHelper;

use \Civi\Api4;
use \CRM_Gpapi_Processor;
use \CRM_Utils_Array;

abstract class AbstractHelper {
  public $isActiveContract;
  public $membership;
  public $recurringContribution;
  public $signActivity;

  abstract public function create(array $params);

  abstract public function update(array $params);

  abstract public function createInitialContribution(array $params);

  abstract protected function loadAdditionalPaymentData(int $membership_id);

  public function __construct($membership_id = NULL) {
    if (empty($membership_id)) return;

    $this->loadContract((int) $membership_id);
  }

  protected function loadContract(int $membership_id) {
    $this->membership = Api4\Membership::get()
      ->addWhere('id', '=', $membership_id)
      ->addSelect('*')
      ->execute()
      ->first();

    $recur_contrib_id = civicrm_api3('ContractPaymentLink', 'getvalue', [
      'contract_id' => $membership_id,
      'is_active'   => 1,
      'return'      => 'contribution_recur_id',
    ]);

    $this->recurringContribution = Api4\ContributionRecur::get()
      ->addWhere('id', '=', $recur_contrib_id)
      ->addSelect('*', 'contribution_status_id:name')
      ->execute()
      ->first();

    $rc_status = $this->recurringContribution['contribution_status_id:name'];
    $this->isActiveContract = !in_array($rc_status, ['Cancelled', 'Completed']);

    $this->signActivity = Api4\Activity::get()
      ->addWhere('activity_type_id:name', '=', 'Contract_Signed')
      ->addWhere('source_record_id', '=', $membership_id)
      ->addSelect('*')
      ->execute()
      ->first();

    $this->loadAdditionalPaymentData($membership_id);
  }

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
      // 'membership_type' => $this->membershipType,
      // 'status' => $this->membershipStatus,
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
    } catch (\CiviCRM_API3_Exception $e) {
      civicrm_api3('Activity', 'create', [
        'activity_type_id'  => 'manual_update_required',
        'target_id'         => $params['contact_id'],
        'subject'           => 'Invalid Referrer Submitted',
        'details'           => 'Membership was submitted with a referrer, but no contact was found for value "' . $params['referrer_contact_id'] . '"',
        'status_id'         => 'Scheduled',
        'check_permissions' => 0,
      ]);

      \CRM_Core_Error::debug_log_message("OSF.contract: Unable to find referrer {$params['referrer_contact_id']}: " . $e->getMessage());
    }

    return NULL;
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
      \CRM_Core_Error::debug_log_message(
        "OSF.contract: Unable to find bank account for {$params['iban']}: " . $ex->getMessage()
      );
    }

    return NULL;
  }

  public static function createBankAccount (array $params) {
    $psp_result_data = $params['psp_result_data'];

    $bank_account_id = self::getBankAccount([
      'contact_id' => $params['contact_id'],
      'iban'       => $psp_result_data['iban'],
    ]);

    if ($bank_account_id !== NULL) return;

    $bank_account_data = [
      'country' => substr($psp_result_data['iban'], 0, 2),
      'BIC'     => $psp_result_data['bic'],
    ];

    $bank_account = civicrm_api3('BankingAccount', 'create', [
      'contact_id'  => $params['contact_id'],
      'description' => "Bulk Importer",
      'data_parsed' => json_encode($bank_account_data),
    ]);

    $ba_reference_type_id = civicrm_api3('OptionValue', 'getvalue', [
      'is_active'       => 1,
      'option_group_id' => 'civicrm_banking.reference_types',
      'value'           => 'IBAN',
      'return'          => 'id',
    ]);

    $bank_account_reference = civicrm_api3('BankingAccountReference', 'create', [
      'ba_id'             => $bank_account['id'],
      'reference'         => $psp_result_data['iban'],
      'reference_type_id' => $ba_reference_type_id,
    ]);

    return $bank_account;
  }

  public function createReferrerOfRelationship ($params) {
    $referrer_id = self::getReferrerContactID($params);

    if (empty($referrer_id)) return;
    if (empty($this->membership)) return;

    $referrer_rel_type_id = civicrm_api3('RelationshipType', 'getvalue', [
      'return'   => 'id',
      'name_a_b' => 'Referrer of',
    ]);

    // it is necessary to wrap Relationship.create in a nested transaction to
    // prevent a rollback from bubbling up to the main API transaction when a
    // "Duplicate Relationship" exception occurs. This would otherwise cause
    // us to return a success response even though a rollback is performed.
    \CRM_Core_Transaction::create(TRUE)->run(
      function($subTx) use ($params, $referrer_id, $referrer_rel_type_id) {
        try {
          civicrm_api3('Relationship', 'create', [
            'contact_id_a'         => $referrer_id,
            'contact_id_b'         => $params['contact_id'],
            'relationship_type_id' => $referrer_rel_type_id,
            'start_date'           => date('Ymd'),
          ]);
        } catch (\CiviCRM_API3_Exception $e) {
          if ($e->getMessage() === 'Duplicate Relationship') {
            civicrm_api3('Activity', 'create', [
              'activity_type_id'  => 'manual_update_required',
              'target_id'         => [$params['contact_id'], $referrer_id],
              'subject'           => 'Potential Referrer Fraud',
              'details'           => 'Contact already referred a membership to the referee.',
              'status_id'         => 'Scheduled',
              'check_permissions' => 0,
            ]);

            \CRM_Core_Error::debug_log_message(
              "OSF.contract: Potential Referrer Fraud with contacts {$params['contact_id']} and $referrer_id"
            );
          } else {
            throw $e;
          }
        }
      }
    );

    $membership_data = [
      'id'                  => $this->membership['id'],
      'membership_referrer' => $referrer_id,
      'skip_handler'        => TRUE, // CE should ignore this change
    ];

    \CRM_Gpapi_Processor::resolveCustomFields($membership_data, ['membership_referral']);

    return civicrm_api3('Membership', 'create', $membership_data);
  }

  public static function resolvePaymentInstrument(array &$params) {
    if (empty($params['payment_instrument'])) return;

    $payment_instrument = $params['payment_instrument'];

    if (is_numeric($payment_instrument)) {
      $params['payment_instrument'] = (int) $payment_instrument;
    }

    $params['payment_instrument'] = (int) self::getOptionValue(
      'payment_instrument',
      $payment_instrument
    );
  }

  protected static function getFinancialTypeID(string $name) {
    return (int) Api4\FinancialType::get()
      ->addWhere('name', '=', $name)
      ->addSelect('id')
      ->setLimit(1)
      ->execute()
      ->first()['id'];
  }

  protected static function getOptionValue(string $optionGroup, string $name) {
    return (int) Api4\OptionValue::get()
      ->addWhere('option_group_id:name', '=', $optionGroup)
      ->addWhere('name', '=', $name)
      ->addSelect('value')
      ->setLimit(1)
      ->execute()
      ->first()['value'];
  }

  protected static function getPaymentInstrumentID(array $params) {
    if (empty($params['payment_instrument'])) return NULL;

    $payment_instrument = $params['payment_instrument'];

    if (is_numeric($payment_instrument)) return (int) $payment_instrument;

    return self::getOptionValue('payment_instrument', $payment_instrument);
  }

}
