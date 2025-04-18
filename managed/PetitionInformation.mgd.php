<?php

use CRM_Gpapi_ExtensionUtil as E;

return [
  [
    'name' => 'CustomGroup_petition_information',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'petition_information',
        'title' => E::ts('Petition Information'),
        'extends' => 'Activity',
        'extends_entity_column_value:name' => [
          'Petition',
        ],
        'style' => 'Inline',
        'help_pre' => '',
        'help_post' => '',
        'weight' => 34,
        'collapse_adv_display' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_petition_information_CustomField_petition_dialoger',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'petition_information',
        'name' => 'petition_dialoger',
        'label' => E::ts('Dialoger'),
        'data_type' => 'ContactReference',
        'html_type' => 'Autocomplete-Select',
        'is_searchable' => TRUE,
        'column_name' => 'petition_dialoger',
        'filter' => 'action=lookup&group=',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_petition_information_CustomField_external_identifier',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'petition_information',
        'name' => 'external_identifier',
        'label' => E::ts('External Identifier'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 256,
        'column_name' => 'external_identifier',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];