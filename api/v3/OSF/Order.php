<?php
/*-------------------------------------------------------+
| Greenpeace.at API                                      |
| Copyright (C) 2017 SYSTOPIA                            |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/


/**
 * Process OSF (online donation form) DONATION submission
 *
 * @param see specs below (_civicrm_api3_o_s_f_order_spec)
 *
 * @return array API result array
 * @access public
 * @throws \Exception
 */
function civicrm_api3_o_s_f_order($params) {
  try {
    return _civicrm_api3_o_s_f_order_process($params);
  } catch (Exception $e) {
    CRM_Gpapi_Error::create('OSF.order', $e, $params);
    throw $e;
  }
}

/**
 * Process OSF.order in single transaction
 *
 * @param $params
 *
 * @return array
 * @throws \Exception
 */
function _civicrm_api3_o_s_f_order_process($params) {
  $tx = new CRM_Core_Transaction();
  try {
    CRM_Gpapi_Processor::preprocessCall($params, 'OSF.order');

    if (empty($params['contact_id'])) {
      return civicrm_api3_create_error("No 'contact_id' provided.");
    }

    if (empty($params['linked_contribution']) && empty($params['linked_membership'])) {
      return civicrm_api3_create_error("You need to provide a 'linked_contribution' or 'linked_membership' via OSF.order API.");
    }

    if (!empty($params['linked_contribution']) && !empty($params['linked_membership'])) {
      return civicrm_api3_create_error("You must not provide both 'linked_contribution' and 'linked_membership' via OSF.order API.");
    }

    CRM_Gpapi_Processor::identifyContactID($params['contact_id']);

    if (empty($params['contact_id'])) {
      return civicrm_api3_create_error('No contact found.');
    }

    // resolve campaign ID
    CRM_Gpapi_Processor::resolveCampaign($params);

    // adjust fields
    $params['target_id'] = $params['contact_id'];
    unset($params['contact_id']);
    $params['activity_type_id']  = 'Webshop Order';
    $params['status_id']         = 'Scheduled';
    $params['check_permissions'] = 0;

    $contactSourceFields = [
      'first_name',
      'last_name',
      'gender_id',
      'email',
      'phone',
      'street_address',
      'postal_code',
      'city',
      'country',
    ];

    if (!empty($params['civi_referrer_contact_id'])) {
      foreach ($contactSourceFields as $field) {
        if (isset($params[$field])) {
          unset($params[$field]);
        }
      }

      if (CRM_Gpapi_Processor::isContactExist($params['civi_referrer_contact_id'])) {
        $contactSourceData = CRM_Gpapi_Processor::retrieveContactSourceData($params['civi_referrer_contact_id']);
        $params = array_merge($params, $contactSourceData);
      }
    }

    if (empty($params['civi_referrer_contact_id']) && isset($params['country']) && !empty($params['country'])) {
      $countryId = CRM_Gpapi_Processor::getCountryIdByIsoCode($params['country']);
      if (!empty($countryId)) {
        $params['country_id'] = $countryId;
      }
    }

    // resolve custom fields
    CRM_Gpapi_Processor::resolveCustomFields($params, ['webshop_information', 'source_contact_data']);

    // create Webshop Order activity
    return civicrm_api3('Activity', 'create', $params);
  } catch (Exception $e) {
    $tx->rollback();
    throw $e;
  }
}

/**
 * Adjust Metadata for Payment action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_o_s_f_order_spec(&$params) {
  // CONTACT BASE
  $params['contact_id'] = [
    'name'         => 'contact_id',
    'api.required' => 1,
    'title'        => 'CiviCRM Contact ID',
  ];
  $params['campaign'] = [
    'name'         => 'campaign',
    'api.required' => 0,
    'title'        => 'CiviCRM Campaign (external identifier)',
  ];
  $params['campaign_id'] = [
    'name'         => 'campaign_id',
    'api.required' => 0,
    'title'        => 'CiviCRM Campaign ID',
    'description'  => 'Overwrites "campaign"',
  ];
  $params['subject'] = [
    'name'         => 'subject',
    'api.default'  => "Webshop Order",
    'title'        => 'Webshop Order Subject Line. DEPRECATED',
  ];
  $params['order_type'] = [
    'name'         => 'order_type',
    'api.required' => 1,
    'title'        => 'Webshop Order Type',
  ];
  $params['shirt_type'] = [
    'name'         => 'shirt_type',
    'api.required' => 0,
    'title'        => 'T-Shirt Type: M/W',
  ];
  $params['shirt_size'] = [
    'name'         => 'shirt_size',
    'api.required' => 0,
    'title'        => 'T-Shirt Size: S/M/L/XL',
  ];
  $params['order_count'] = [
    'name'         => 'order_count',
    'api.required' => 1,
    'title'        => 'Webshop Order Count',
  ];
  $params['linked_contribution'] = [
    'name'         => 'linked_contribution',
    'api.required' => 0,
    'title'        => 'Linked Contribution ID',
  ];
  $params['linked_membership'] = [
    'name'         => 'linked_membership',
    'api.required' => 0,
    'title'        => 'Linked Membership ID',
  ];
  $params['payment_received'] = [
    'name'         => 'payment_received',
    'api.required' => 1,
    'title'        => 'Webshop Order Payment Received',
  ];
  $params['multi_purpose'] = [
    'name'         => 'multi_purpose',
    'api.required' => 0,
    'title'        => 'Webshop Order CustomData',
  ];
  $params['civi_referrer_contact_id'] = [
    'name'         => 'civi_referrer_contact_id',
    'api.required' => 0,
    'title'        => 'Source contact id',
    'description'  => 'If this field exist code ignore all other "Source contact" field. Source contact data gets by this contact id.',
    'type'         => CRM_Utils_TYPE::T_STRING,
  ];

  // Source contact fields:
  $params['first_name'] = [
    'name'         => 'first_name',
    'api.required' => 0,
    'title'        => 'First name',
    'description'  => '(Source contact)',
    'type'         => CRM_Utils_TYPE::T_STRING,
  ];
  $params['last_name'] = [
    'name'         => 'last_name',
    'api.required' => 0,
    'title'        => 'Last name',
    'description'  => '(Source contact)',
    'type'         => CRM_Utils_TYPE::T_STRING,
  ];
  $params['gender_id'] = [
    'name'         => 'gender_id',
    'api.required' => 0,
    'title'        => 'Gender',
    'description'  => '(Source contact)',
    'type'         => CRM_Utils_TYPE::T_STRING,
  ];
  $params['email'] = [
    'name'         => 'email',
    'api.required' => 0,
    'title'        => 'Email',
    'description'  => '(Source contact)',
    'type'         => CRM_Utils_TYPE::T_STRING,
  ];
  $params['phone'] = [
    'name'         => 'phone',
    'api.required' => 0,
    'title'        => 'Phone',
    'description'  => '(Source contact)',
    'type'         => CRM_Utils_TYPE::T_STRING,
  ];
  $params['street_address'] = [
    'name'         => 'street_address',
    'api.required' => 0,
    'title'        => 'Street address',
    'description'  => '(Source contact)',
    'type'         => CRM_Utils_TYPE::T_STRING,
  ];
  $params['postal_code'] = [
    'name'         => 'postal_code',
    'api.required' => 0,
    'title'        => 'Postal code',
    'description'  => '(Source contact)',
    'type'         => CRM_Utils_TYPE::T_STRING,
  ];
  $params['city'] = [
    'name'         => 'city',
    'api.required' => 0,
    'title'        => 'City',
    'description'  => '(Source contact)',
    'type'         => CRM_Utils_TYPE::T_STRING,
  ];
  $params['country'] = [
    'name'         => 'country',
    'api.required' => 0,
    'title'        => 'Country iso code',
    'description'  => '(Source contact)',
    'type'         => CRM_Utils_TYPE::T_STRING,
  ];
}
