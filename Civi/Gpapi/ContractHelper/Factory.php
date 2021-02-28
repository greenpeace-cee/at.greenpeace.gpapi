<?php

namespace Civi\Gpapi\ContractHelper;

class Factory {
  public static function create($membershipId) {
    $recurring_contribution_field = \CRM_Contract_CustomData::getCustomFieldKey(
      'membership_payment',
      'membership_recurring_contribution'
    );
    $contract = civicrm_api3('Contract', 'getsingle', [
      'id' => $membershipId,
      'api.ContributionRecur.getsingle' => [
        'id' => '$value.' . $recurring_contribution_field
      ],
      'api.SepaMandate.getsingle' => [
        'entity_table' => 'civicrm_contribution_recur',
        'entity_id' => '$value.' . $recurring_contribution_field,
        'api.SepaCreditor.getsingle' => [
          'id' => '$value.creditor_id',
        ]
      ],
      'check_permissions' => 0,
    ]);
    if (empty($contract[$recurring_contribution_field]) || empty($contract['api.ContributionRecur.getsingle']['id'])) {
      throw new Exception('No payment method associated with contract', Exception::PAYMENT_METHOD_INVALID);
    }
    if (empty($contract['api.SepaMandate.getsingle']['id'])) {
      // currently, all supported payment instruments are backed by SepaMandates
      // this may change in the future
      throw new Exception('No mandate associated with contract', Exception::PAYMENT_METHOD_INVALID);
    }
    $creditor = $contract['api.SepaMandate.getsingle']['api.SepaCreditor.getsingle'];
    switch ($creditor['creditor_type']) {
      case 'SEPA':
        return new Sepa($membershipId);

      case 'PSP':
        // this is a PSP creditor. sepa_file_format_id contains the actual PSP
        $psp_name = civicrm_api3('OptionValue', 'getvalue', [
          'return'            => 'name',
          'option_group_id'   => 'sepa_file_format',
          'value'             => $creditor['sepa_file_format_id'],
          'check_permissions' => 0,
        ]);
        switch ($psp_name) {
          case 'adyen':
            return new Adyen($membershipId);

          default:
            throw new Exception('Unsupported payment service provider', Exception::PAYMENT_INSTRUMENT_UNSUPPORTED);
        }

      default:
        throw new Exception('Unsupported payment instrument', Exception::PAYMENT_INSTRUMENT_UNSUPPORTED);
    }
  }

}
