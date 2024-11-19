<?php

use Civi\Gpapi\Api\NewsletterUnsubscribe;

function civicrm_api3_newsletter_unsubscribe($params) {
  // Use default error handler. See GP-23825
  $tempErrorScope = CRM_Core_TemporaryErrorScope::useException();

  try {
    return civicrm_api3_create_success((new NewsletterUnsubscribe($params))->getResult());
  } catch (Exception $e) {
    CRM_Gpapi_Error::create('Newsletter.unsubscribe', $e, $params);
    throw $e;
  }
}

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
