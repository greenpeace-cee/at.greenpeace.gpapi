<?php

use CRM_Gpapi_ExtensionUtil as E;

return [
  [
    'name' => 'OptionValue_UTM_Tracking',
    'entity' => 'OptionValue',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'label' => E::ts('UTM Tracking'),
        'name' => 'UTM Tracking',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'OptionValue_Ratgeber_verschickt',
    'entity' => 'OptionValue',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'label' => E::ts('Ratgeber verschickt'),
        'name' => 'Ratgeber verschickt',
      ],
      'match' => [
        'option_group_id',
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_utm',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'utm',
        'title' => E::ts('UTM Tracking Information'),
        'extends' => 'Activity',
        'extends_entity_column_value:name' => [
          'Contribution',
          'Open Case',
          'Petition',
          'Contract_Signed',
          'Ratgeber verschickt',
          'UTM Tracking',
        ],
        'style' => 'Inline',
        'collapse_display' => TRUE,
        'help_pre' => '',
        'help_post' => '',
        'weight' => 12,
        'collapse_adv_display' => TRUE,
        'icon' => '',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_utm_CustomField_utm_source',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'utm',
        'name' => 'utm_source',
        'label' => E::ts('Source'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 255,
        'column_name' => 'utm_source',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_utm_CustomField_utm_medium',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'utm',
        'name' => 'utm_medium',
        'label' => E::ts('Medium'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 255,
        'column_name' => 'utm_medium',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_utm_CustomField_utm_campaign',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'utm',
        'name' => 'utm_campaign',
        'label' => E::ts('Campaign'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 255,
        'column_name' => 'utm_campaign',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_utm_CustomField_utm_content',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'utm',
        'name' => 'utm_content',
        'label' => E::ts('Content'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 255,
        'column_name' => 'utm_content',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_utm_CustomField_utm_term',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'utm',
        'name' => 'utm_term',
        'label' => E::ts('Term'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 255,
        'column_name' => 'utm_term',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_utm_CustomField_utm_id',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'utm',
        'name' => 'utm_id',
        'label' => E::ts('Id'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 255,
        'column_name' => 'utm_id',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];