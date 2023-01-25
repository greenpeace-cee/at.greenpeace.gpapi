<?php

namespace Civi\Gpapi\ContractHelper;

use \CRM_Utils_Array;

class Factory {

  /**
   * @param array $params
   * @return \Civi\Gpapi\ContractHelper\AbstractHelper
   * @throws \Civi\Gpapi\ContractHelper\Exception
   */
  public static function create(array $params) {
    $psp = CRM_Utils_Array::value('payment_service_provider', $params);
    $membership_id = CRM_Utils_Array::value('contract_id', $params);

    if (empty($psp)) return new Sepa($membership_id);

    switch ($psp) {
      case 'adyen':
        return new Adyen($membership_id);

      case 'civicrm':
        return new Sepa($membership_id);

      default:
        throw new Exception(
          "Unsupported payment service provider $psp",
          Exception::PAYMENT_SERVICE_PROVIDER_UNSUPPORTED
        );
    }
  }

  /**
   * @param $membershipId
   *
   * @return \Civi\Gpapi\ContractHelper\Adyen|\Civi\Gpapi\ContractHelper\Sepa
   * @throws \CiviCRM_API3_Exception
   * @throws \Civi\Gpapi\ContractHelper\Exception
   */
  public static function createWithMembershipId($membershipId) {
    $recurring_contribution_field = \CRM_Contract_CustomData::getCustomFieldKey(
      'membership_payment',
      'membership_recurring_contribution'
    );
    $contract = civicrm_api3('Contract', 'getsingle', [
      'id' => $membershipId,
      'api.ContributionRecur.get' => [
        'id' => '$value.' . $recurring_contribution_field
      ],
      'api.SepaMandate.get' => [
        'entity_table' => 'civicrm_contribution_recur',
        'entity_id' => '$value.' . $recurring_contribution_field,
        'api.SepaCreditor.getsingle' => [
          'id' => '$value.creditor_id',
        ]
      ],
      'check_permissions' => 0,
    ]);
    if (empty($contract[$recurring_contribution_field]) || empty($contract['api.ContributionRecur.get']['values'][0]['id'])) {
      throw new Exception('No payment method associated with contract', Exception::PAYMENT_METHOD_INVALID);
    }
    if (empty($contract['api.SepaMandate.get']['values'][0]['id'])) {
      // currently, all supported payment instruments are backed by SepaMandates
      // this may change in the future
      throw new Exception('No mandate associated with contract', Exception::PAYMENT_METHOD_INVALID);
    }
    $creditor = $contract['api.SepaMandate.get']['values'][0]['api.SepaCreditor.getsingle'];
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
            throw new Exception('Unsupported payment service provider "' . $psp_name . '"', Exception::PAYMENT_SERVICE_PROVIDER_UNSUPPORTED);
        }

      default:
        throw new Exception('Unsupported payment instrument', Exception::PAYMENT_INSTRUMENT_UNSUPPORTED);
    }
  }

  /**
   * @param $membershipId
   * @param $paymentInstrumentName
   * @param $paymentServiceProvider
   *
   * @return \Civi\Gpapi\ContractHelper\Adyen|\Civi\Gpapi\ContractHelper\Sepa
   * @throws \Civi\Gpapi\ContractHelper\Exception
   */
  public static function createWithMembershipIdAndPspData($membershipId, $paymentServiceProvider) {
    switch ($paymentServiceProvider) {
      case 'adyen':
        return new Adyen($membershipId);

      case 'civicrm':
        return new Sepa($membershipId);

      default:
        throw new Exception(
          'Unsupported payment service provider "' . $paymentServiceProvider . '"',
          Exception::PAYMENT_SERVICE_PROVIDER_UNSUPPORTED
        );
    }
  }

  /**
   * @param array $params
   *
   * @return \Civi\Gpapi\ContractHelper\Adyen|\Civi\Gpapi\ContractHelper\Sepa
   */
  public static function createWithoutExistingMembership($params) {
    if (empty($params['payment_service_provider'])) return new Sepa();

    switch ($params['payment_service_provider']) {
      case 'adyen':
        return new Adyen();

      case 'civicrm':
        return new Sepa();

      default:
        throw new Exception(
          'Unsupported payment service provider "' . $params['payment_service_provider'] . '"',
          Exception::PAYMENT_SERVICE_PROVIDER_UNSUPPORTED
        );
    }
  }

}
