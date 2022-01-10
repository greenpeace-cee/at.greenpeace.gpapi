<?php

use CRM_Gpapi_ExtensionUtil as E;

/**
 * OSF.getcontract API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_o_s_f_getcontract_spec(&$spec) {
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
}

/**
 * OSF.getcontract API
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
function civicrm_api3_o_s_f_getcontract($params) {
  // Use default error handler. See GP-23825
  $tempErrorScope = CRM_Core_TemporaryErrorScope::useException();

  CRM_Gpapi_Processor::preprocessCall($params, 'OSF.getcontract');
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

  try {
    $contractHelper = \Civi\Gpapi\ContractHelper\Factory::createWithMembershipId($params['contract_id']);
  } catch (Civi\Gpapi\ContractHelper\Exception $e) {
    switch ($e->getCode()) {
      case Civi\Gpapi\ContractHelper\Exception::PAYMENT_INSTRUMENT_UNSUPPORTED:
        throw new API_Exception('Contract has unsupported payment instrument', 'payment_instrument_unsupported');

      case Civi\Gpapi\ContractHelper\Exception::PAYMENT_METHOD_INVALID:
        throw new API_Exception('Contract has invalid payment method', 'payment_method_invalid');

      case Civi\Gpapi\ContractHelper\Exception::PAYMENT_METHOD_INVALID:
        throw new API_Exception('Contract has unsupported payment service provider', 'payment_service_provider_unsupported');

      default:
        throw $e;
    }

  }
  $null = NULL;
  return civicrm_api3_create_success([$contractHelper->getContractDetails()], $params, 'OSF', 'getcontract', $null, ['id' => $params['contract_id']]);
}
