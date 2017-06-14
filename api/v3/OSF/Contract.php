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
 * @param see specs below (_civicrm_api3_o_s_f_contract_spec)
 * @return array API result array
 * @access public
 */
function civicrm_api3_o_s_f_contract($params) {
  CRM_Core_Error::debug_log_message("OSF.contract: " . json_encode($params));
  gpapi_civicrm_fix_API_UID();

  if (empty($params['iban'])) {
    return civicrm_api3_create_error("No 'iban' provided.");
  }
  if (empty($params['bic'])) {
    return civicrm_api3_create_error("No 'bic' provided.");
  }

  // resolve campaign ID
  if (empty($params['campaign_id']) && !empty($params['campaign'])) {
    $campaign = civicrm_api3('Campaign', 'getsingle', array('external_identifier' => $params['campaign']));
    $params['campaign_id'] = $campaign['id'];
    unset($params['campaign']);
  }

  // prepare parameters
  $params['member_since'] = date('YmdHis'); // now
  $params['start_date'] = date('YmdHis'); // now
  $params['amount'] = number_format($params['amount'], 2, '.', '');

  $creditor = CRM_Sepa_Logic_Settings::defaultCreditor();
  if (empty($params['cycle_day'])) {
    // SEPA stuff (TODO: use new service)
    $buffer_days = (int) CRM_Sepa_Logic_Settings::getSetting("pp_buffer_days");
    $frst_notice_days = (int) CRM_Sepa_Logic_Settings::getSetting("batching.FRST.notice", $creditor->id);
    $earliest_rcur_date = strtotime("now + $frst_notice_days days + $buffer_days days");
    $cycle_days = CRM_Sepa_Logic_Settings::getListSetting("cycledays", range(1, 28), $creditor->id);
    $cycle_day = $earliest_rcur_date;
    while (!in_array(date('d', $cycle_day), $cycle_days)) {
      $cycle_day = strtotime("+ 1 day", $cycle_day);
    }
    $cycle_day = date('d', $cycle_day);
  } else {
    $cycle_day = (int) $params['cycle_day'];
  }

  // first: create a mandate
  $mandate = civicrm_api3('SepaMandate', 'createfull', array(
    'type'                => 'RCUR',
    'iban'                => $params['iban'],
    'bic'                 => $params['bic'],
    'amount'              => $params['amount'],
    'contact_id'          => $params['contact_id'],
    'creditor_id'         => $creditor->id,
    'currency'            => 'EUR',
    'frequency_unit'      => 'month',
    'cycle_day'           => $cycle_day,
    'frequency_interval'  => (int) (12.0 / $params['frequency']),
    'start_date'          => $params['start_date'],
    'campaign_id'         => $campaign_id,
    'financial_type_id'   => 3, // Membership Dues
    ));
  // reload mandate
  $mandate = civicrm_api3('SepaMandate', 'getsingle', array('id' => $mandate['id']));

  // create the contract
  $result = civicrm_api3('Contract', 'create', array(
    'contact_id'                                           => $params['contact_id'],
    'membership_type_id'                                   => $params['membership_type_id'],
    'join_date'                                            => $params['start_date'],
    'start_date'                                           => $params['start_date'],
    'source'                                               => 'OSF',
    'campaign_id'                                          => $campaign_id,
    'membership_payment.membership_annual'                 => number_format($params['amount'] * $params['frequency'], 2, '.', ''),
    'membership_payment.membership_frequency'              => $params['frequency'],
    'membership_payment.membership_recurring_contribution' => $mandate['entity_id'],
    'membership_payment.to_ba'                             => _civicrm_api3_o_s_f_contract_getBA($creditor->iban, $creditor->creditor_id),
    'membership_payment.from_ba'                           => _civicrm_api3_o_s_f_contract_getBA($params['iban'], $params['contact_id'], array('BIC' => $params['bic'])),
    'membership_payment.cycle_day'                         => $cycle_day
    ));
  // and return the good news (otherwise an Exception would have occurred)
  return $result;
}

/**
 * get or create the bank account of the given contact/iban
 * @return int banking_account.id
 */
function _civicrm_api3_o_s_f_contract_getBA($iban, $contact_id, $extra_data) {
  // look up reference type option value ID(!)
  $reference_type_value = civicrm_api3('OptionValue', 'getsingle', array(
    'value'           => 'IBAN',
    'option_group_id' => 'civicrm_banking.reference_types',
    'is_active'       => 1));

  // find existing references
  $existing_references = civicrm_api3('BankingAccountReference', 'get', array(
    'reference'         => $iban,
    'reference_type_id' => $reference_type_value['id'],
    'option.limit'      => 0));

  // get the accounts for this
  $bank_account_ids = array();
  foreach ($existing_references['values'] as $account_reference) {
    $bank_account_ids[] = $account_reference['ba_id'];
  }
  if (!empty($bank_account_ids)) {
    $contact_bank_accounts = civicrm_api3('BankingAccount', 'get', array(
      'id'           => array('IN' => $bank_account_ids),
      'contact_id'   => $contact_id,
      'option.limit' => 1));
    if ($contact_bank_accounts['count']) {
      // bank account already exists with the contact
      $bank_account = reset($contact_bank_accounts['values']);
      return $bank_account['id'];
    }
  }

  // if we get here, that means that there is no such bank account
  //  => create one
  $extra_data['country'] = substr($iban, 0, 2);
  $bank_account = civicrm_api3('BankingAccount', 'create', array(
    'contact_id'  => $contact_id,
    'description' => "Bulk Importer",
    'data_parsed' => json_encode($extra_data)));

  $bank_account_reference = civicrm_api3('BankingAccountReference', 'create', array(
    'reference'         => $iban,
    'reference_type_id' => $reference_type_value['id'],
    'ba_id'             => $bank_account['id']));
  return $bank_account['id'];
}


/**
 * Adjust Metadata for Payment action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_o_s_f_contract_spec(&$params) {
  // CONTACT BASE
  $params['contact_id'] = array(
    'name'         => 'contact_id',
    'api.required' => 1,
    'title'        => 'CiviCRM Contact ID',
    );
  $params['campaign'] = array(
    'name'         => 'campaign',
    'api.required' => 0,
    'title'        => 'CiviCRM Campaign (external identifier)',
    );
  $params['campaign_id'] = array(
    'name'         => 'campaign_id',
    'api.required' => 0,
    'title'        => 'CiviCRM Campaign ID',
    'description'  => 'Overwrites "campaign"',
    );
  $params['amount'] = array(
    'name'         => 'amount',
    'api.required' => 1,
    'title'        => 'Amount (per collection)',
    );
  $params['frequency'] = array(
    'name'         => 'frequency',
    'api.required' => 1,
    'title'        => 'Frequency (collections per year)',
    );
  $params['membership_type_id'] = array(
    'name'         => 'membership_type_id',
    'api.required' => 1,
    'title'        => 'Membership Type (CiviCRM ID)',
    );
  $params['iban'] = array(
    'name'         => 'iban',
    'api.required' => 1,
    'title'        => 'IBAN',
    );
  $params['bic'] = array(
    'name'         => 'bic',
    'api.required' => 1,
    'title'        => 'BIC',
    );
}
