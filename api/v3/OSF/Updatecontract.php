<?php

use Civi\Api4;
use Civi\Gpapi\ContractHelper;

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
  // Use default error handler. See GP-23825
  $tempErrorScope = CRM_Core_TemporaryErrorScope::useException();

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
    _civicrm_api3_o_s_f_updatecontract_preprocessCall($params);

    $contract_helper = ContractHelper\Factory::create($params);

    $lock = _civicrm_api3_o_s_f_updatecontract_acquireLock($params);

    try {
      $contract_helper->update($params);
    } catch (Exception $e) {
      throw $e;
    } finally {
      $lock->release();
    }

    $membership_id = $contract_helper->membership['id'];
    $null = NULL;

    return civicrm_api3_create_success(
      [[ 'id' => $membership_id ]],
      $params,
      'OSF',
      'updatecontract',
      $null,
      [ 'id' => $membership_id ]
    );
  } catch (Exception $e) {
    $tx->rollback();

    _civicrm_api3_o_s_f_updatecontract_handleException($e, $params);

    throw $e;
  }
}

function _civicrm_api3_o_s_f_updatecontract_acquireLock(array $params) {
  $lock = new CRM_Core_Lock('contribute.OSF.mandate', 90, TRUE);
  $lock->acquire();

  if (!$lock->isAcquired()) {
    $error = CRM_Gpapi_Error::create(
      'OSF.contract',
      "Mandate lock timeout. Try again later.",
      $params
    );

    throw new API_Exception($error['error_message'], 'mandate_lock_timeout');
  }

  return $lock;
}

function _civicrm_api3_o_s_f_updatecontract_handleException(Exception $e, array $params) {
  if ($e instanceof ContractHelper\Exception) {
    switch ($e->getCode()) {
      case ContractHelper\Exception::PAYMENT_INSTRUMENT_UNSUPPORTED:
        throw new API_Exception(
          'Requested payment instrument "' . $params['payment_instrument'] . '"is not supported',
          'payment_instrument_unsupported'
        );

      case ContractHelper\Exception::PAYMENT_METHOD_INVALID:
        throw new API_Exception(
          'Contract has invalid payment method',
          'payment_method_invalid'
        );

      case ContractHelper\Exception::PAYMENT_SERVICE_PROVIDER_UNSUPPORTED:
        throw new API_Exception(
          'Contract has unsupported payment service provider',
          'payment_service_provider_unsupported'
        );
    }
  }
}

function _civicrm_api3_o_s_f_updatecontract_preprocessCall(&$params) {
  CRM_Gpapi_Processor::preprocessCall($params, 'OSF.updatecontract');

  // --- Identify contact --- //

  $contact_id = CRM_Gpapi_Processor::resolveContactHash($params['hash']);

  if (empty($contact_id)) {
    throw new API_Exception('Unknown contact hash', 'unknown_hash');
  }

  $params['contact_id'] = $contact_id;

  // --- Ensure membershp exists and belongs to contact --- //

  $membership_count = Api4\Membership::get()
    ->selectRowCount()
    ->addWhere('id',         '=', $params['contract_id'])
    ->addWhere('contact_id', '=', $contact_id)
    ->execute()
    ->rowCount;

  if ($membership_count < 1) {
    throw new API_Exception('Unknown contract', 'unknown_contract');
  }

  // --- Resolve membership type name to ID --- //

  ContractHelper\AbstractHelper::resolveMembershipType($params);

  // --- Resolve payment instrument name to ID --- //

  ContractHelper\AbstractHelper::resolvePaymentInstrument($params);

}

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
