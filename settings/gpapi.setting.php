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
    'type' => 'Array',
    'default' => [
      1 => 'Ms.',
      2 => 'Mr.',
    ],
    'add' => '4.6',
    'is_domain' => 1,
    'is_contact' => 0,
  ),
);
