<?php

use CRM_Gpapi_ExtensionUtil as E;

return [
  'gpapi_membership_type_id' => [
    'name' => 'gpapi_membership_type_id',
    'type' => 'Integer',
    'html_type' => 'text',
    'default' => 1,
    'add' => '1.6',
    'title' => E::ts('GPAPI: Default membership type ID'),
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
    'title' => E::ts('GPAPI: Gender to Prefix map'),
    'is_domain' => 1,
    'is_contact' => 0,
  ],
  'gpapi_group_to_subscription_map' => [
    'name' => 'gpapi_group_to_subscription_map',
    'type' => 'Array',
    'default' => [],
    'add' => '1.9',
    'title' => E::ts('GPAPI: Group to Subscription Map'),
    'description' => E::ts('Map CiviCRM group names to HubSpot subscription names. Each group maps to exactly one subscription and vice-versa. Group memberships and subscriptions are kept in sync in both directions.'),
    'is_domain' => 1,
    'is_contact' => 0,
  ],
  'gpapi_opt_out_subscription' => [
    'name' => 'gpapi_opt_out_subscription',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'add' => '1.9',
    'title' => E::ts('GPAPI: Opt-out Subscription'),
    'description' => E::ts('Name of the HubSpot subscription that is mapped to the contact\'s "No Bulk Emails" (is_opt_out) flag. Opting out of the subscription sets the flag, opting in clears it. Setting the flag opts the contact out of the subscription, clearing it opts them back in if they have a subscription record.'),
    'is_domain' => 1,
    'is_contact' => 0,
  ],
];
