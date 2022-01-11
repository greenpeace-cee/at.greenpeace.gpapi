<?php
/*-------------------------------------------------------+
| Greenpeace.at API                                      |
| Copyright (C) 2018 SYSTOPIA                            |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

define('GPAPI_DEFAULT_TIMELINE', 'web_default');

/**
 * Create a new case
 *
 * @see _civicrm_api3_engage_signpetition_spec
 *
 * @param $params
 *
 * @return array
 * @throws \Exception
 */
function civicrm_api3_engage_startcase($params) {
  // Use default error handler. See GP-23825
  $tempErrorScope = CRM_Core_TemporaryErrorScope::useException();

  try {
    return _civicrm_api3_engage_startcase_process($params);
  } catch (Exception $e) {
    CRM_Gpapi_Error::create('Engage.startcase', $e, $params);
    throw $e;
  }
}

/**
 * Process Engage.startcase in single transaction
 *
 * @param $params
 *
 * @return array
 * @throws \Exception
 */
function _civicrm_api3_engage_startcase_process($params) {
  $tx = new CRM_Core_Transaction();
  try {
    CRM_Gpapi_Processor::preprocessCall($params, 'Engage.startcase');
    return CRM_Gpapi_CaseHandler::startCase($params);
  } catch (Exception $e) {
    $tx->rollback();
    throw $e;
  }
}

/**
 * @param $params
 */
function _civicrm_api3_engage_startcase_spec(&$params) {
  $params['case_type_id'] = [
    'name'         => 'case_type_id',
    'api.required' => 1,
    'title'        => 'Case Type ID',
  ];
  $params['contact_id'] = [
    'name'         => 'contact_id',
    'api.required' => 1,
    'title'        => 'Contact ID',
  ];
  $params['timeline'] = [
    'name'         => 'timeline',
    'api.required' => 0,
    'api.default'  => GPAPI_DEFAULT_TIMELINE,
    'title'        => 'Case timeline to add to cases in addition to the standard timeline',
  ];
}
