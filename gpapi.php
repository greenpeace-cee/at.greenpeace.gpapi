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

require_once 'gpapi.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function gpapi_civicrm_config(&$config) {
  _gpapi_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function gpapi_civicrm_xmlMenu(&$files) {
  _gpapi_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function gpapi_civicrm_install() {
  _gpapi_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function gpapi_civicrm_postInstall() {
  _gpapi_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function gpapi_civicrm_uninstall() {
  _gpapi_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function gpapi_civicrm_enable() {
  _gpapi_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function gpapi_civicrm_disable() {
  _gpapi_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function gpapi_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _gpapi_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function gpapi_civicrm_managed(&$entities) {
  _gpapi_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function gpapi_civicrm_caseTypes(&$caseTypes) {
  _gpapi_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function gpapi_civicrm_angularModules(&$angularModules) {
  _gpapi_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function gpapi_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _gpapi_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Define custom (Drupal) permissions
 */
function gpapi_civicrm_permission(&$permissions) {
  $permissions['access OSF API']    = 'GP-API: access OSF API';
  // $permissions['access Engage API'] = 'GP-API: access Engage API';
}

/**
 * Set permissions for runner/engine API call
 */
function gpapi_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  // OSF
  $permissions['osf']['submit']       = array('access OSF API');
  $permissions['osf']['donation']     = array('access OSF API');
  $permissions['osf']['order']        = array('access OSF API');
  $permissions['osf']['contract']     = array('access OSF API');
  $permissions['osf']['getcampaigns'] = array('access OSF API');
  $permissions['osf']['getproducts']  = array('access OSF API');
}




 /**********************************************
  *               HELPER                       *
  *********************************************/

/**
 * Fixed API bug, where activity creation needs a valid userID
 *
 * Copied from https://github.com/CiviCooP/org.civicoop.apiuidfix
 * by Jaap Jansma, CiviCoop
 */
function gpapi_civicrm_fix_API_UID() {
  // see https://github.com/CiviCooP/org.civicoop.apiuidfix
  $session = CRM_Core_Session::singleton();
  $userId = $session->get('userID');
  if (empty($userId)) {
    $valid_user = FALSE;

    // Check and see if a valid secret API key is provided.
    $api_key = CRM_Utils_Request::retrieve('api_key', 'String', $store, FALSE, NULL, 'REQUEST');
    if (!$api_key || strtolower($api_key) == 'null') {
      return; // nothing we can do
    }

    $valid_user = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $api_key, 'id', 'api_key');

    // If we didn't find a valid user, die
    if (!empty($valid_user)) {
      //now set the UID into the session
      $session->set('userID', $valid_user);
    }
  }
}

/**
 * internal function to replace keys in the data
 * with the appropriate custom_XX notation.
 */
function gpapi_civicrm_resolveCustomFields(&$data, $customgroups) {
  // error_log("BEFORE: ".json_encode($data));
  $custom_fields = civicrm_api3('CustomField', 'get', array(
    'custom_group_id' => array('IN' => $customgroups),
    'option.limit'    => 0,
    'return'          => 'id,name,is_active'
    ));

  // compile indexed list
  $field_list = array();
  foreach ($custom_fields['values'] as $custom_field) {
    $field_list[$custom_field['name']] = $custom_field;
  }

  // replace stuff
  foreach (array_keys($data) as $key) {
    if (isset($field_list[$key])) {
      $custom_key = 'custom_' . $field_list[$key]['id'];
      $data[$custom_key] = $data[$key];
      unset($data[$key]);
    }
  }
  // error_log("AFTER: ".json_encode($data));
}