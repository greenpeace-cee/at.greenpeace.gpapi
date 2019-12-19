<?php
/*
 * Settings metadata file
 */
return array (
  'gpapi_membership_type_id' => array(
    'group_name' => 'gpapi',
    'group' => 'gpapi',
    'name' => 'gpapi_membership_type_id',
    'type' => 'Integer',
    'default' => 1,
    'add' => '4.6',
    'is_domain' => 1,
    'is_contact' => 0,
  ),
  'gpapi_gender_to_prefix_map' => array(
    'group_name' => 'gpapi',
    'group' => 'gpapi',
    'name' => 'gpapi_gender_to_prefix_map',
    'type' => 'String',
    'serialize' => CRM_Core_DAO::SERIALIZE_JSON,
    'default' => [
      'Female' => 'Ms.',
      '1' => 'Ms.',
      'Male' => 'Mr.',
      '2' => 'Mr.',
    ],
    'add' => '4.6',
    'is_domain' => 1,
    'is_contact' => 0,
  ),
);
