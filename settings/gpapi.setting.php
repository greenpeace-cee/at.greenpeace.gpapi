<?php

return [
  'gpapi_membership_type_id' => [
    'name' => 'gpapi_membership_type_id',
    'type' => 'Integer',
    'html_type' => 'text',
    'default' => 1,
    'add' => '1.6',
    'title' => ts('GPAPI: Default membership type ID'),
    'is_domain' => 1,
    'is_contact' => 0,
  ],
  'gpapi_gender_to_prefix_map' => [
    'name' => 'gpapi_gender_to_prefix_map',
    'type' => 'Array',
    'html_type' => 'text',
    'default' => [
      'Female' => 'Ms.',
      '1' => 'Ms.',
      'Male' => 'Mr.',
      '2' => 'Mr.',
    ],
    'add' => '1.7',
    'title' => ts('GPAPI: Gender to Prefix map'),
    'is_domain' => 1,
    'is_contact' => 0,
  ],
];
