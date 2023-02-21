<?php

use Civi\Api4;
use Civi\Gpapi\ContractHelper;

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

  _civicrm_api3_o_s_f_getcontract_preprocessCall($params);

  $contract_details = [];

  try {
    $contract_helper = ContractHelper\Factory::create($params);
    $contract_details = $contract_helper->getContractDetails();
  } catch (Exception $e) {
    _civicrm_api3_o_s_f_getcontract_handleException($e, $params);

    throw $e;
  }

  $null = NULL;

  return civicrm_api3_create_success(
    [$contract_details],
    $params,
    'OSF',
    'getcontract',
    $null,
    [ 'id' => $params['contract_id'] ]
  );
}

function _civicrm_api3_o_s_f_getcontract_handleException(Exception $e, array $params) {
  // ...
}

function _civicrm_api3_o_s_f_getcontract_preprocessCall(array &$params) {
  CRM_Gpapi_Processor::preprocessCall($params, 'OSF.getcontract');

  // --- Identify contact --- //

  $contact_id = CRM_Gpapi_Processor::resolveContactHash($params['hash']);

  if (empty($contact_id)) {
    throw new API_Exception('Unknown contact hash', 'unknown_hash');
  }

  $params['contact_id'] = $contact_id;

  // --- Ensure membershp exists and belongs to contact --- //

  $membership_count = Api4\Membership::get(FALSE)
    ->selectRowCount()
    ->addWhere('id',         '=', $params['contract_id'])
    ->addWhere('contact_id', '=', $contact_id)
    ->execute()
    ->rowCount;

  if ($membership_count < 1) {
    throw new API_Exception('Unknown contract', 'unknown_contract');
  }

}

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
