<?php
/*-------------------------------------------------------+
| Greenpeace.at API                                      |
| Copyright (C) 2018 Greenpeace CEE                      |
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
 * Get list of all groups
 *
 * @param $params empty array
 *
 * @return array list of groups
 * @throws \CiviCRM_API3_Exception
 */
function civicrm_api3_newsletter_getgroups($params) {
  CRM_Gpapi_Processor::preprocessCall($params, 'Newsletter.getgroups');

  return civicrm_api3('Group', 'get', [
    'sequential' => 1,
    'options' => ['limit' => 0],
    'is_active' => 1,
    'check_permissions' => 0,
  ]);
}

