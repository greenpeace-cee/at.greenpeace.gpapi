<?php

namespace Civi\Gpapi\ContractHelper;

use Civi;
use Civi\Api4\Activity;
use Civi\Api4\ActivityContact;
use Civi\Gpapi\GpApiUtils;
use CiviCRM_API3_Exception;
use CRM_Core_PseudoConstant;
use CRM_Core_Session;
use CRM_Core_Transaction;
use CRM_Gpapi_Processor;
use DateTime;

class NewsletterUnsubscribe extends ApiBase {

  public function getResult(): array {
    $tx = new CRM_Core_Transaction();
    try {
      CRM_Gpapi_Processor::preprocessCall($this->params, 'Newsletter.unsubscribe');

      if (empty($this->params['group_ids']) && empty($this->params['opt_out'])) {
        return civicrm_api3_create_error("Nothing to do");
      }

      // find contact (via identity tracker)
      CRM_Gpapi_Processor::identifyContactID($this->params['contact_id']);// TODO: check: why result is never used?

      if (empty($this->params['contact_id'])) {
        return civicrm_api3_create_error('No contacts found.');
      }

      $relatedContactsIds = $this->findRelatedContacts($this->params['contact_id']);

      $this->unsubscribeGroupContacts($relatedContactsIds, $this->params['group_ids'], $this->params['mailing_id']);

      if ($this->params['opt_out']) {
        $this->setOptOutToContacts($relatedContactsIds, $this->params['mailing_id']);
      }
    } catch (Exception $e) {
      $tx->rollback();
      throw $e;
    }

    $tx->commit();

    return civicrm_api3_create_success();
  }

  /**
   * Create opt-out Activity
   */
  private function createOptOutActivity($contactId, $mailingId, $optOutType, $groupContactId = null) {
    $email = GpApiUtils::getContactPrimaryEmail($contactId);
    if (!empty($groupContactId)) {
      $subject = GpApiUtils::findGroupContactTitle($groupContactId);
    } else {
      $subject = civicrm_api3('OptionValue', 'getvalue', [
        'return' => 'label',
        'option_group_id' => 'optout_type',
        'name' => $optOutType,
      ]);
    }

    //TODO: 'Engagement Tool' add there to mgd file for 'optout_source' option group
    $activity = Activity::create(FALSE);
    $activity->addValue('activity_type_id', CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Optout'));
    $activity->addValue('source_contact_id', CRM_Core_Session::singleton()->getLoggedInContactID());
    $activity->addValue('medium_id', CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'medium_id', 'web'));
    $activity->addValue('optout_information.optout_source', GpApiUtils::getOptionValueValue('optout_source', 'Engagement Tool'));
    $activity->addValue('optout_information.optout_type', GpApiUtils::getOptionValueValue('optout_type', $optOutType));
    $activity->addValue('optout_information.optout_identifier', $mailingId);
    $activity->addValue('activity_date_time', (new DateTime())->format('Y-m-d H:i:s'));// TODO: which time need to set?
    $activity->addValue('optout_information.optout_item', $email);
    $activity->addValue('subject', 'Added "' . $subject . '" via Engagement Tool');
    $activity->addChain('activity_contact', ActivityContact::create(FALSE)
      ->addValue('activity_id', '$id')
      ->addValue('contact_id', $contactId)
      ->addValue('record_type_id', 3)
    );

    if (!empty($groupContactId)) {
      $activity->addValue('source_record_id', $groupContactId);
    }

    $parentActivityId = $this->findParentActivityId();
    if (!empty($parentActivityId)) {
      $activity->addValue('activity_hierarchy.parent_activity_id', $parentActivityId);
    }

    $campaignId = $this->findCampaignId($parentActivityId);
    if (!empty($campaignId)) {
      $activity->addValue('campaign_id', $campaignId);
    }

    $activity = $activity->execute()->first();

    civicrm_api3('ActivityContactEmail', 'create', [
      'activity_contact_id' => $activity['activity_contact'][0]['id'],
      'email' => $email,
    ]);
  }

  /**
   * Find additional contacts with the same primary email
   */
  private function findRelatedContacts($contactId): array {
    $contactIds = [$contactId];

    try {
      $email = civicrm_api3('Contact', 'getvalue', [
        'return' => 'email',
        'id' => $contactId,
      ]);

      if (!empty($email)) {
        $relatedContacts = civicrm_api3('Contact', 'get', [
          'return' => 'id',
          'email' => $email,
        ]);
        foreach ($relatedContacts['values'] as $contact) {
          $contactIds[] = $contact['id'];
        }
      }
    } catch (CiviCRM_API3_Exception $e) {
      Civi::log()->warning("Newsletter.unsubscribe: Exception when looking up email for contact {$contactId}: " . $e->getMessage());
    }

    return array_unique($contactIds);
  }


  /**
   * Process group unsubscribe
   */
  private function unsubscribeGroupContacts($contactsIds, $groupContactIds, $mailingId) {
    foreach ($groupContactIds as $groupContactId) {
      foreach ($contactsIds as $contactId) {
        civicrm_api3('GroupContact', 'create', [
          'group_id' => $groupContactId,
          'contact_id' => $contactId,
          'status' => 'Removed'
        ]);

        $this->createOptOutActivity($contactId, $mailingId, 'group', $groupContactId);
      }
    }
  }

  /**
   * Process opt-out
   */
  private function setOptOutToContacts($contactsIds, $mailingId) {
    foreach ($contactsIds as $contactId) {
      civicrm_api3('Contact', 'create', [
        'id' => $contactId,
        'is_opt_out' => 1
      ]);
      //TODO which $optOutType is set for it
      $this->createOptOutActivity($contactId, $mailingId, 'group');
    }
  }

  private function findCampaignId($parentActivityId) {
    // TODO: implement it, or remove
    return null;
  }

  private function findParentActivityId() {
    // TODO: implement it, or remove
    return null;
  }

  protected function setParams($params): array {
    $groupContactIds =  [];
    if (!empty($params['group_ids'])) {
      $rawGroupIds = explode(',', $params['opt_out']);
      foreach ($rawGroupIds as $groupId) {
        $groupContactId = (int) $groupId;
        if ($groupContactId) {
          $groupContactIds[] = $groupId;
        }
      }
    }

    return [
      'contact_id' => (int) $params['contact_id'],
      'group_ids' => $groupContactIds,
      'opt_out' => !empty($params['opt_out']),
      'mailing_id' => !empty($params['mailing_id']) ? (int) $params['mailing_id'] : null,
    ];
  }

}
