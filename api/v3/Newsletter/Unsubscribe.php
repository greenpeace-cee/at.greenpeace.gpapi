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
 *
 * @return array API result array
 * @access public
 * @throws \Exception
 */
function civicrm_api3_newsletter_unsubscribe($params) {
  // Use default error handler. See GP-23825
  $tempErrorScope = CRM_Core_TemporaryErrorScope::useException();

  try {
    return _civicrm_api3_newsletter_unsubscribe_process($params);
  } catch (Exception $e) {
    CRM_Gpapi_Error::create('Newsletter.unsubscribe', $e, $params);
    throw $e;
  }
}

/**
 * Process Newsletter.unsubscribe in single transaction
 *
 * @param $params
 *
 * @return array
 * @throws \Exception
 */
function _civicrm_api3_newsletter_unsubscribe_process($params) {
  $tx = new CRM_Core_Transaction();
  try {
    CRM_Gpapi_Processor::preprocessCall($params, 'Newsletter.unsubscribe');

    if (empty($params['group_ids']) && empty($params['opt_out'])) {
      return civicrm_api3_create_error("Nothing to do");
    }

    // find contact (via identity tracker)
    CRM_Gpapi_Processor::identifyContactID($params['contact_id']);

    if (empty($params['contact_id'])) {
      return civicrm_api3_create_error('No contacts found.');
    }

    $contacts = [$params['contact_id']];

    // find additional contacts with the same primary email
    try {
      $email = civicrm_api3('Contact', 'getvalue', [
        'return' => 'email',
        'id' => $contacts[0],
      ]);
      if (!empty($email)) {
        $email_results = civicrm_api3('Contact', 'get', [
          'return' => 'id',
          'email' => $email,
        ]);
        foreach ($email_results['values'] as $contact) {
          $contacts[] = $contact['id'];
        }
      }
    } catch (CiviCRM_API3_Exception $e) {
      CRM_Core_Error::debug_log_message("Newsletter.unsubscribe: Exception when looking up email for contact {$contacts[0]}: " . $e->getMessage());
    }

    $contacts = array_unique($contacts);

    // process group unsubscribe
    if (!empty($params['group_ids'])) {
      $group_ids = explode(',', $params['group_ids']);
      foreach ($group_ids as $group_id) {
        $group_id = (int) $group_id;
        if ($group_id) {
          foreach ($contacts as $contact) {
            civicrm_api3('GroupContact', 'create', [
              'group_id'   => $group_id,
              'contact_id' => $contact,
              'status'     => 'Removed'
            ]);
          }
        }
      }
    }

    // process opt-out
    if (!empty($params['opt_out'])) {
      foreach ($contacts as $contact) {
        civicrm_api3('Contact', 'create', [
          'id'         => $contact,
          'is_opt_out' => 1
        ]);
      }
    }

    return civicrm_api3_create_success();
  } catch (Exception $e) {
    $tx->rollback();
    throw $e;
  }
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
