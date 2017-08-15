<?php
/*-------------------------------------------------------+
| Greenpeace.at API                                      |
| Copyright (C) 2017 SYSTOPIA                            |
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
 * Process Newsletter newsletter subscription
 *
 * @param see specs below (_civicrm_api3_newsletter_unsubscribe_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_newsletter_unsubscribe($params) {
  CRM_Gpapi_Processor::preprocessCall($params, 'Newsletter.unsubscribe');
  $result = array();

  if (empty($params['group_ids']) && empty($params['opt_out'])) {
    return civicrm_api3_create_error("Nothing to do");
  }

  // find contact (via identity tracker)
  $contact = civicrm_api3('Contact', 'identify', array(
    'identifier_type' => 'internal',
    'identifier'      => (int) $params['contact_id']));

  // process group unsubscribe
  if (!empty($params['group_ids'])) {
    $group_ids = explode(',', $params['group_ids']);
    foreach ($group_ids as $group_id) {
      $group_id = (int) $group_id;
      if ($group_id) {
        civicrm_api3('GroupContact', 'create', array(
          'group_id'   => $group_id,
          'contact_id' => $contact['id'],
          'status'     => 'Removed'));
      }
    }
  }

  // process opt-out
  if (!empty($params['opt_out'])) {
    civicrm_api3('Contact', 'create', array(
      'id'         => $contact['id'],
      'is_opt_out' => 1));
  }

  return civicrm_api3_create_success();
}

/**
 * Adjust Metadata for Payment action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_newsletter_unsubscribe_spec(&$params) {
  $params['contact_id'] = array(
    'name'         => 'contact_id',
    'api.required' => 1,
    'title'        => 'Contact ID',
    );
  $params['group_ids'] = array(
    'name'         => 'group_ids',
    'api.required' => 0,
    'title'        => 'CiviCRM Group IDs',
    'description'  => 'List of group IDs to unsubscribe contact from, separated by a comma',
    );
  $params['opt_out'] = array(
    'name'         => 'opt_out',
    'api.default'  => 0,
    'title'        => 'Complete opt-out',
    'description'  => 'If set, contact opted out of all mass mailings',
    );
}
