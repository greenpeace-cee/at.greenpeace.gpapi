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


/**
 * Process OSF (online donation form) base submission
 *
 * @param see specs below (_civicrm_api3_engage_signpetition_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_engage_startcase($params) {
  CRM_Gpapi_Processor::preprocessCall($params, 'Engage.startcase');
  $result = array();

  return CRM_Gpapi_CaseHandler::startCase($params);
}

/**
 * Adjust Metadata for Payment action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_engage_startcase_spec(&$params) {
  // CONTACT BASE
  $params['case_type_id'] = array(
    'name'         => 'case_type_id',
    'api.required' => 1,
    'title'        => 'Case Type ID',
    );
  $params['contact_id'] = array(
    'name'         => 'contact_id',
    'api.required' => 1,
    'title'        => 'Contact ID',
    );
}
