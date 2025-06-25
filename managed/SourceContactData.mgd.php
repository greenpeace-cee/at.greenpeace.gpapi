<?php

use CRM_Gpapi_ExtensionUtil as E;

return [
  [
    'name' => 'CustomGroup_source_contact_data',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'source_contact_data',
        'title' => E::ts('Source Contact Data'),
        'extends' => 'Activity',
        'extends_entity_column_value:name' => [
          'Open Case',
          'Petition',
          'Webshop Order',
          'Ratgeber verschickt',
          'anonymisation_request',
        ],
        'style' => 'Inline',
        'help_pre' => '',
        'help_post' => '',
        'weight' => 21,
        'collapse_adv_display' => TRUE,
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_prefix_id',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'prefix_id',
        'label' => E::ts('Prefix'),
        'data_type' => 'Int',
        'html_type' => 'Select',
        'is_searchable' => TRUE,
        'column_name' => 'prefix_id',
        'option_group_id.name' => 'individual_prefix',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_gender_id',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'gender_id',
        'label' => E::ts('Gender'),
        'data_type' => 'Int',
        'html_type' => 'Select',
        'is_searchable' => TRUE,
        'column_name' => 'gender_id',
        'option_group_id.name' => 'gender',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_first_name',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'first_name',
        'label' => E::ts('First Name'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 64,
        'column_name' => 'first_name',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_last_name',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'last_name',
        'label' => E::ts('Last Name'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 64,
        'column_name' => 'last_name',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_birth_date',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'birth_date',
        'label' => E::ts('Birth Date'),
        'data_type' => 'Date',
        'html_type' => 'Select Date',
        'is_searchable' => TRUE,
        'start_date_years' => 100,
        'end_date_years' => 0,
        'date_format' => 'dd.mm.yy',
        'column_name' => 'birth_date',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_bpk',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'bpk',
        'label' => E::ts('bpk'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 28,
        'column_name' => 'bpk',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_email',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'email',
        'label' => E::ts('Email'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 255,
        'column_name' => 'email',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_phone',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'phone',
        'label' => E::ts('Phone'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 32,
        'column_name' => 'phone',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_country_id',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'country_id',
        'label' => E::ts('Country'),
        'data_type' => 'Country',
        'html_type' => 'Select',
        'is_searchable' => TRUE,
        'column_name' => 'country_id',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_postal_code',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'postal_code',
        'label' => E::ts('Postal Code'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 64,
        'column_name' => 'postal_code',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_city',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'city',
        'label' => E::ts('City'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 64,
        'column_name' => 'city',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_street_address',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'street_address',
        'label' => E::ts('Street Address'),
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'text_length' => 94,
        'column_name' => 'street_address',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_newsletter',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'newsletter',
        'label' => E::ts('Newsletter Opt-In'),
        'data_type' => 'Boolean',
        'html_type' => 'Radio',
        'default_value' => '0',
        'is_searchable' => TRUE,
        'column_name' => 'newsletter',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_source_contact_data_CustomField_geoip_country_id',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'source_contact_data',
        'name' => 'geoip_country_id',
        'label' => E::ts('GeoIP Country'),
        'data_type' => 'Country',
        'html_type' => 'Select',
        'is_searchable' => TRUE,
        'column_name' => 'geoip_country_id',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];