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
  $null = NULL;
  $id = rand(1000000, 10000000);
  return civicrm_api3_create_success([['id' => $id]], $params, 'OSF', 'updatecontract', $null, ['id' => $id]);
}
