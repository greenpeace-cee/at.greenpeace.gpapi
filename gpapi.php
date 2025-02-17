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
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function gpapi_civicrm_install() {
  _gpapi_civix_civicrm_install();
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
 * Define custom (Drupal) permissions
 */
function gpapi_civicrm_permission(&$permissions) {
  $permissions['access OSF API'] = [
    'label' => 'GP-API: access OSF API',
    'description' => 'GP-API: access OSF API',
  ];

  $permissions['access Engage API'] = [
    'label' => 'GP-API: access Engage API',
    'description' => 'GP-API: access Engage API',
  ];

  $permissions['access Newsletter API'] = [
    'label' => 'GP-API: access Newsletter API',
    'description' => 'GP-API: access Newsletter API',
  ];
}

/**
 * Set permissions for runner/engine API call
 */
function gpapi_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  // OSF
  $permissions['o_s_f']['submit']       = ['access OSF API'];
  $permissions['o_s_f']['donation']     = ['access OSF API'];
  $permissions['o_s_f']['order']        = ['access OSF API'];
  $permissions['o_s_f']['contract']     = ['access OSF API'];
  $permissions['o_s_f']['getcampaigns'] = ['access OSF API'];
  $permissions['o_s_f']['getproducts']  = ['access OSF API'];
  $permissions['o_s_f']['getcontact']   = ['access OSF API'];
  $permissions['o_s_f']['getcontract']  = ['access OSF API'];
  $permissions['o_s_f']['updatecontract'] = ['access OSF API'];

  // Engage
  $permissions['engage']['signpetition'] = ['access Engage API'];
  $permissions['engage']['getcampaigns'] = ['access Engage API'];
  $permissions['engage']['getmedia']     = ['access Engage API'];
  $permissions['engage']['getpetitions'] = ['access Engage API'];
  $permissions['engage']['startcase']    = ['access Engage API'];

  // Newsletter
  $permissions['newsletter']['subscribe']   = ['access Newsletter API'];
  $permissions['newsletter']['unsubscribe'] = ['access Newsletter API'];
  $permissions['newsletter']['getgroups'] = ['access Newsletter API'];
}
