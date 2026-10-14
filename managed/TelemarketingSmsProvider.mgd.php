<?php

use CRM_Gpapi_ExtensionUtil as E;

return [
  [
    'name' => 'OptionGroup_sms_provider_name_OptionValue_gpapi_telemarketing',
    'entity' => 'OptionValue',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'sms_provider_name',
        'name' => 'gpapi_telemarketing',
        'label' => E::ts('Telemarketing'),
        // CiviCRM maps this to the class gpapi_telemarketing
        'value' => 'gpapi.telemarketing',
        'is_active' => TRUE,
      ],
      'match' => [
        'name',
        'option_group_id',
      ],
    ],
  ],
];
