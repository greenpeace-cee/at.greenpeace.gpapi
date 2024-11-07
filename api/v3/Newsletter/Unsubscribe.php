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

use Civi\Gpapi\ContractHelper\NewsletterUnsubscribe;

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
    return (new NewsletterUnsubscribe($params))->getResult();
  } catch (Exception $e) {
    CRM_Gpapi_Error::create('Newsletter.unsubscribe', $e, $params);
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
  $params['contact_id'] = [
    'name' => 'contact_id',
    'api.required' => 1,
    'title' => 'Contact ID',
  ];
  $params['group_ids'] = [
    'name' => 'group_ids',
    'api.required' => 0,
    'title' => 'CiviCRM Group IDs',
    'description' => 'List of group IDs to unsubscribe contact from, separated by a comma',
  ];
  $params['opt_out'] = [
    'name' => 'opt_out',
    'api.default' => 0,
    'title' => 'Complete opt-out',
    'description' => 'If set, contact opted out of all mass mailings',
  ];
  $params['mailing_id'] = [
    'name' => 'mailing_id',
    'api.default' => 0,
    'title' => 'Mailing id',
    'description' => 'That Parameter will contain the ID of the mailing/newsletter the opt-out was initiated from',
  ];
}
