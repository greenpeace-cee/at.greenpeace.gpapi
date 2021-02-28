<?php
use CRM_Gpapi_ExtensionUtil as E;

/**
 * OSF.Getcontact API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_o_s_f_getcontact_spec(&$spec) {
  $spec['hash'] = [
    'name'         => 'hash',
    'api.required' => 1,
    'title'        => 'CiviCRM Contact Hash',
  ];
}

/**
 * OSF.Getcontact API
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
function civicrm_api3_o_s_f_getcontact($params) {
  CRM_Gpapi_Processor::preprocessCall($params, 'OSF.getcontact');
  $contact_id = CRM_Gpapi_Processor::resolveContactHash($params['hash']);
  if (is_null($contact_id)) {
    throw new API_Exception('Unknown contact hash', 'unknown_hash');
  }
  $contact = CRM_Gpapi_Processor::getContactData($contact_id);
  $null = NULL;
  return civicrm_api3_create_success([$contact], $params, 'OSF', 'getcontact', $null, ['id' => $contact_id]);
}
