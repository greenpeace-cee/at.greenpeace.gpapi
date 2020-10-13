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
  $permissions['access OSF API']        = 'GP-API: access OSF API';
  $permissions['access Engage API']     = 'GP-API: access Engage API';
  $permissions['access Newsletter API'] = 'GP-API: access Newsletter API';
}

/**
 * Set permissions for runner/engine API call
 */
function gpapi_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  // OSF
  $permissions['o_s_f']['submit']       = array('access OSF API');
  $permissions['o_s_f']['donation']     = array('access OSF API');
  $permissions['o_s_f']['order']        = array('access OSF API');
  $permissions['o_s_f']['contract']     = array('access OSF API');
  $permissions['o_s_f']['getcampaigns'] = array('access OSF API');
  $permissions['o_s_f']['getproducts']  = array('access OSF API');

  // Engage
  $permissions['engage']['signpetition'] = array('access Engage API');
  $permissions['engage']['getcampaigns'] = array('access Engage API');
  $permissions['engage']['getmedia']     = array('access Engage API');
  $permissions['engage']['getpetitions'] = array('access Engage API');
  $permissions['engage']['startcase']    = array('access Engage API');

  // Newsletter
  $permissions['newsletter']['subscribe']   = array('access Newsletter API');
  $permissions['newsletter']['unsubscribe'] = array('access Newsletter API');
  $permissions['newsletter']['getgroups'] = ['access Newsletter API'];
}

