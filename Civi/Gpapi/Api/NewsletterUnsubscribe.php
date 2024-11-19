<?php

namespace Civi\Gpapi\Api;

use Civi;
use Civi\Gpapi\OptOutActivity;
use CiviCRM_API3_Exception;
use CRM_Core_Transaction;
use CRM_Gpapi_Processor;
use Exception;

class NewsletterUnsubscribe extends ApiBase {

  public function getResult(): array {
    $transaction = new CRM_Core_Transaction();

    try {
      CRM_Gpapi_Processor::preprocessCall($this->params, 'Newsletter.unsubscribe');

      if (empty($this->params['group_ids']) && empty($this->params['opt_out'])) {
        throw new Exception("Nothing to do");
      }

      // find contact (via identity tracker)
      $this->params['contact_id'] = CRM_Gpapi_Processor::identifyContactID($this->params['contact_id']);

      if (empty($this->params['contact_id'])) {
        throw new Exception("No contacts found.");
      }

      $relatedContactsIds = $this->findRelatedContacts($this->params['contact_id']);

      $this->unsubscribeGroupContacts($relatedContactsIds, $this->params['group_ids'], $this->params['mailing_id']);

      if ($this->params['opt_out']) {
        $this->setOptOutToContacts($relatedContactsIds, $this->params['mailing_id']);
      }
    } catch (Exception $e) {
      $transaction->rollback();
      throw $e;
    }

    $transaction->commit();

    return [];
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

        try {
          OptOutActivity::create($contactId, $mailingId, 'group', $groupContactId);
        } catch (Exception $e) {
          Civi::log()->warning("Newsletter.unsubscribe: Cannot create OptOutActivity, error:" . $e->getMessage());
        }
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

      try {
        OptOutActivity::create($contactId, $mailingId, 'is_opt_out');
      } catch (Exception $e) {
        Civi::log()->warning("Newsletter.unsubscribe: Cannot create OptOutActivity, error:" . $e->getMessage());
      }
    }
  }

  protected function setParams($params): array {
    $groupContactIds =  [];
    if (!empty($params['group_ids'])) {
      $rawGroupIds = explode(',', $params['group_ids']);
      foreach ($rawGroupIds as $rawGroupId) {
        $groupContactId = (int) $rawGroupId;
        if ($groupContactId) {
          $groupContactIds[] = $groupContactId;
        }
      }
    }

    return [
      'contact_id' => (int) $params['contact_id'],
      'group_ids' => array_unique($groupContactIds),
      'opt_out' => !empty($params['opt_out']),
      'mailing_id' => !empty($params['mailing_id']) ? (int) $params['mailing_id'] : null,
    ];
  }

}
