<?php

use CRM_Gpapi_ExtensionUtil as E;

return [
  [
    'name' => 'CustomGroup_optout_information',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'optout_information',
        'title' => E::ts('Opt-Out Information'),
        'extends' => 'Activity',
        'extends_entity_column_value:name' => [
          'Optout',
        ],
        'style' => 'Inline',
        'weight' => 20,
        'collapse_adv_display' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_optout_type',
    'entity' => 'OptionGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'optout_type',
        'title' => E::ts('Opt-Out Type'),
        'description' => E::ts('Opt-Out type'),
        'option_value_fields' => [
          'name',
          'label',
          'description',
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_optout_type_OptionValue_group',
    'entity' => 'OptionValue',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'optout_type',
        'label' => E::ts('Group'),
        'value' => '1',
        'name' => 'group',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_optout_type_OptionValue_is_opt_out',
    'entity' => 'OptionValue',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'optout_type',
        'label' => E::ts('NO BULK EMAILS (User Opt Out)'),
        'value' => '2',
        'name' => 'is_opt_out',
      ],
      'match' => [
        'name',
        'option_group_id'
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_optout_information_CustomField_optout_type',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'optout_information',
        'name' => 'optout_type',
        'label' => E::ts('Opt-Out Type'),
        'data_type' => 'Int',
        'html_type' => 'Select',
        'is_searchable' => TRUE,
        'is_view' => TRUE,
        'column_name' => 'optout_type',
        'option_group_id.name' => 'optout_type',
      ],
      'match' => [
        'name',
        'custom_group_id'
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_optout_source',
    'entity' => 'OptionGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'optout_source',
        'title' => E::ts('Opt-Out Source'),
        'description' => E::ts('Source for Opt-Out'),
        'option_value_fields' => [
          'name',
          'label',
          'description',
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionGroup_optout_source_OptionValue_Mailingwork',
    'entity' => 'OptionValue',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'optout_source',
        'label' => E::ts('Engagement Tool'),
        'value' => '1',
        'name' => 'engagement_tool',
      ],
      'match' => [
        'name',
        'option_group_id'
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_optout_information_CustomField_optout_source',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'optout_information',
        'name' => 'optout_source',
        'label' => E::ts('Opt-Out Source'),
        'data_type' => 'Int',
        'html_type' => 'Select',
        'is_searchable' => TRUE,
        'is_view' => TRUE,
        'column_name' => 'optout_source',
        'option_group_id.name' => 'optout_source',
      ],
      'match' => [
        'name',
        'custom_group_id'
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_optout_information_CustomField_optout_identifier',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'optout_information',
        'name' => 'optout_identifier',
        'label' => E::ts('Opt-Out Identifier'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'is_view' => TRUE,
        'text_length' => 255,
        'column_name' => 'optout_identifier',
      ],
      'match' => [
        'name',
        'custom_group_id'
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_optout_information_CustomField_optout_item',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'optout_information',
        'name' => 'optout_item',
        'label' => E::ts('Opt-Out Item'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'is_view' => TRUE,
        'text_length' => 255,
        'column_name' => 'optout_item',
      ],
      'match' => [
        'name',
        'custom_group_id'
      ],
    ],
  ],
];
