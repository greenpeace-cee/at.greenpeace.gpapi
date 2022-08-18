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
  $result = NULL;
  $error = NULL;
  $caseActivityCallback = CRM_Gpapi_CaseHandler::caseActivityCallback($params);

  try {
    CRM_Gpapi_Processor::preprocessCall($params, 'Engage.startcase');
    Civi::dispatcher()->addListener('hook_civicrm_post', $caseActivityCallback, 1);
    $result = CRM_Gpapi_CaseHandler::startCase($params);
  } catch (Exception $e) {
    $tx->rollback();
    $error = $e;
  } finally {
    Civi::dispatcher()->removeListener('hook_civicrm_post', $caseActivityCallback);
  }

  if (isset($error)) throw $error;

  return $result;
}

/**
 * @param $params
 */
function _civicrm_api3_engage_startcase_spec(&$params) {
  $params['case_type_id'] = [
    'name'         => 'case_type_id',
    'title'        => 'Case Type ID',
    'api.required' => 1,
  ];
  $params['contact_id'] = [
    'name'         => 'contact_id',
    'title'        => 'Contact ID',
    'description'  => 'Client contact of the case',
    'api.required' => 1,
  ];
  $params['medium_id'] = [
    'name'         => 'medium_id',
    'title'        => 'Encounter Medium ID',
    'api.required' => 0,
  ];
  $params['timeline'] = [
    'name'         => 'timeline',
    'title'        => 'Timeline',
    'description'  => 'Case timeline to add to cases in addition to the standard timeline',
    'api.required' => 0,
    'api.default'  => GPAPI_DEFAULT_TIMELINE,
  ];
  $params['utm_campaign'] = [
    'name'         => 'utm_campaign',
    'title'        => 'UTM Campaign',
    'api.required' => 0,
  ];
  $params['utm_content'] = [
    'name'         => 'utm_content',
    'title'        => 'UTM Content',
    'api.required' => 0,
  ];
  $params['utm_medium'] = [
    'name'         => 'utm_medium',
    'title'        => 'UTM Medium',
    'api.required' => 0,
  ];
  $params['utm_source'] = [
    'name'         => 'utm_source',
    'title'        => 'UTM Source',
    'api.required' => 0,
  ];
}
