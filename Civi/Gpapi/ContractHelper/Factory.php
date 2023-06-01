<?php

namespace Civi\Gpapi\ContractHelper;

use \Civi\Api4;
use Civi\Api4\Membership;
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

    if (empty($psp)) {
      $psp = isset($membership_id)
        ? self::getPaymentServiceProviderForContract((int) $membership_id)
        : 'civicrm';
    }

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

  private static function getPaymentServiceProviderForContract(int $membership_id) {
    try {
      $membership = Membership::get(FALSE)
        ->addSelect('membership_payment.membership_recurring_contribution')
        ->addWhere('id', '=', $membership_id)
        ->execute()
        ->first();
      $recur_contrib_id = $membership['membership_payment.membership_recurring_contribution'];

      // --- Check whether an associated SEPA mandate exists --- //

      $sepa_mandate_count = Api4\SepaMandate::get(FALSE)
        ->selectRowCount()
        ->addWhere('entity_id', '=', $recur_contrib_id)
        ->addWhere('entity_table', '=', 'civicrm_contribution_recur')
        ->execute()
        ->count();
      if ($sepa_mandate_count > 0) return 'civicrm';

      // --- Check whether the recurring contribution uses an Adyen payment processor --- //

      $recurring_contribution = Api4\ContributionRecur::get(FALSE)
        ->selectRowCount()
        ->addJoin(
          'PaymentProcessor AS payment_processor',
          'INNER',
          ['payment_processor_id', '=', 'payment_processor.id']
        )
        ->addJoin(
          'PaymentProcessorType AS payment_processor_type',
          'INNER',
          ['payment_processor.payment_processor_type_id', '=', 'payment_processor_type.id']
        )
        ->addWhere('id', '=', $recur_contrib_id)
        ->addWhere('payment_processor_type.name', '=', 'Adyen')
        ->setLimit(1)
        ->execute();

      if ($recurring_contribution->rowCount > 0) return 'adyen';

    } catch (Exception $e) {}

    return NULL;
  }

}
