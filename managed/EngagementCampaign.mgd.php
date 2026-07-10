<?php

use CRM_Gpapi_ExtensionUtil as E;

return [
  [
    'name' => 'CustomGroup_Engagement_Campaign',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'engagement_campaign',
        'title' => E::ts('Engagement Campaign'),
        'extends' => 'Activity',
        'extends_entity_column_value:name' => [
          'Contribution',
          'Petition',
          'Contract_Signed',
          'Contract_Updated',
          'Contract_Revived',
        ],
        'style' => 'Inline',
        'help_pre' => '',
        'help_post' => '',
        'weight' => 66,
        'collapse_adv_display' => TRUE,
        'is_public' => FALSE,
        'icon' => '',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_Engagement_Campaign_CustomField_Engagement_Campaign',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'engagement_campaign',
        'name' => 'engagement_campaign',
        'label' => E::ts('Engagement Campaign'),
        'data_type' => 'EntityReference',
        'html_type' => 'Autocomplete-Select',
        'is_searchable' => TRUE,
        'column_name' => 'engagement_campaign',
        'filter' => 'parent_id.name=ENGAGE',
        'fk_entity' => 'Campaign',
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
];