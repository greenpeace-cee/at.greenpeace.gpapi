<?php
use CRM_Gpapi_ExtensionUtil as E;

/**
 * OSF.updatecontract API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_o_s_f_updatecontract_spec(&$spec) {
  $spec['hash'] = [
    'name'         => 'hash',
    'api.required' => 1,
    'title'        => 'CiviCRM Contact Hash',
  ];
  $spec['contract_id'] = [
    'name'         => 'contract_id',
    'type'         => CRM_Utils_TYPE::T_INT,
    'api.required' => 1,
    'title'        => 'Contract/Membership ID',
  ];
  $spec['frequency'] = [
    'name'         => 'frequency',
    'type'         => CRM_Utils_TYPE::T_INT,
    'api.required' => 1,
    'title'        => 'Frequency',
  ];
  $spec['amount'] = [
    'name'         => 'amount',
    'type'         => CRM_Utils_TYPE::T_FLOAT,
    'api.required' => 1,
    'title'        => 'Amount',
  ];
  $spec['payment_instrument'] = [
    'name'         => 'payment_instrument',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
    'title'        => 'Payment Instrument',
  ];
  $spec['payment_service_provider'] = [
    'name'         => 'payment_service_provider',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'api.required' => 1,
    'title'        => 'Payment Service Provider',
  ];
  $spec['payment_details'] = [
    'name'         => 'payment_details',
    'api.required' => 1,
    'title'        => 'Payment Details',
  ];
  $spec['start_date'] = [
    'name'         => 'start_date',
    'type'         => CRM_Utils_TYPE::T_DATE,
    'title'        => 'Start Date',
  ];
  $spec['currency'] = [
    'name'         => 'currency',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'title'        => 'Currency',
  ];
  $spec['membership_type'] = [
    'name'         => 'membership_type',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'title'        => 'Membership Type',
  ];
  $spec['campaign_id'] = [
    'name'         => 'campaign_id',
    'type'         => CRM_Utils_TYPE::T_INT,
    'title'        => 'Campaign ID',
  ];
  $spec['external_identifier'] = [
    'name'         => 'external_identifier',
    'type'         => CRM_Utils_TYPE::T_STRING,
    'title'        => 'External Identifier',
  ];
  $spec['transaction_details'] = [
    'name'         => 'transaction_details',
    'title'        => 'Transaction Details',
  ];
}

/**
 * OSF.updatecontract API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws API_Exception
 */
function civicrm_api3_o_s_f_updatecontract($params) {

  try {
    return _civicrm_api3_o_s_f_updatecontract_process($params);
  } catch (Exception $e) {
    CRM_Gpapi_Error::create('OSF.updatecontract', $e, $params);
    throw $e;
  }
}

function _civicrm_api3_o_s_f_updatecontract_process(&$params) {
  $tx = new CRM_Core_Transaction();
  try {
    CRM_Gpapi_Processor::preprocessCall($params, 'OSF.updatecontract');
    $contact_id = CRM_Gpapi_Processor::resolveContactHash($params['hash']);
    if (is_null($contact_id)) {
      throw new API_Exception('Unknown contact hash', 'unknown_hash');
    }
    // ensure membership exists and belongs to contact
    $membership_exists = civicrm_api3('Membership', 'getcount', [
      'id'               => $params['contract_id'],
      'contact_id'       => $contact_id,
      'check_permissions' => 0,
    ]);
    if (!$membership_exists) {
      throw new API_Exception('Unknown contract', 'unknown_contract');
    }
    $lock = new CRM_Core_Lock('contribute.OSF.mandate', 90, TRUE);
    $lock->acquire();
    if (!$lock->isAcquired()) {
      throw new API_Exception('Mandate lock timeout. Try again later.', 'lock_timeout');
    }
    try {
      $contractHelper = \Civi\Gpapi\ContractHelper\Factory::createWithMembershipIdAndPspData(
        $params['contract_id'],
        $params['payment_instrument'],
        $params['payment_service_provider']
      );
      $id = $contractHelper->update($params);
      $null = NULL;
      return civicrm_api3_create_success([['id' => $id]], $params, 'OSF', 'updatecontract', $null, ['id' => $id]);
    } catch (Exception $e) {
      throw $e;
    } finally {
      $lock->release();
    }
  } catch (Exception $e) {
    $tx->rollback();
    if ($e instanceof Civi\Gpapi\ContractHelper\Exception) {
      switch ($e->getCode()) {
        case Civi\Gpapi\ContractHelper\Exception::PAYMENT_INSTRUMENT_UNSUPPORTED:
          throw new API_Exception('Requested payment instrument "' . $params['payment_instrument'] . '"is not supported', 'payment_instrument_unsupported');

        case Civi\Gpapi\ContractHelper\Exception::PAYMENT_METHOD_INVALID:
          throw new API_Exception('Contract has invalid payment method', 'payment_method_invalid');

        case Civi\Gpapi\ContractHelper\Exception::PAYMENT_METHOD_INVALID:
          throw new API_Exception('Contract has unsupported payment service provider', 'payment_service_provider_unsupported');
      }
    }
    throw $e;
  }
}
