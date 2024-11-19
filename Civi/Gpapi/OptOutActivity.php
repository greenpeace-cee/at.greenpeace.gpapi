<?php

namespace Civi\Gpapi;

use Civi;
use Civi\Api4\Activity;
use Civi\Api4\ActivityContact;
use CRM_Core_DAO;
use CRM_Core_Exception;
use CRM_Core_PseudoConstant;
use CRM_Core_Session;
use DateTime;
use Exception;

class OptOutActivity {

  public static function create($contactId, $mailingId, $optOutType, $groupContactId = null) {
    $email = GpApiUtils::getContactPrimaryEmail($contactId);

    if (!empty($groupContactId)) {
      $subject = GpApiUtils::getGroupContactTitle($groupContactId);
    } else {
      $subject = GpApiUtils::getOptionValueLabel('optout_type', $optOutType);
    }

    $activity = Activity::create(FALSE)
      ->addValue('activity_type_id:name', 'Optout')
      ->addValue('source_contact_id', CRM_Core_Session::singleton()->getLoggedInContactID())
      ->addValue('medium_id:name', 'web')
      ->addValue('optout_information.optout_source:name', 'engagement_tool')
      ->addValue('optout_information.optout_type:name', $optOutType)
      ->addValue('optout_information.optout_identifier', !empty($mailingId) ? $mailingId : '')
      ->addValue('activity_date_time', (new DateTime())->format('Y-m-d H:i:s'))
      ->addValue('optout_information.optout_item', $email)
      ->addValue('subject', $subject);

    $activity->addChain('activity_contact', ActivityContact::create(FALSE)
      ->addValue('activity_id', '$id')
      ->addValue('contact_id', $contactId)
      ->addValue('record_type_id', 3)
    );

    if (!empty($groupContactId)) {
      $activity->addValue('source_record_id', $groupContactId);
    }

    $mailing = self::findMailing($mailingId);
    if (!empty($mailing)) {
      $parentActivity = self::findParentActivity($mailing['id'], $contactId, $email);
      if (!empty($parentActivity['id'])) {
        $activity->addValue('activity_hierarchy.parent_activity_id', $parentActivity['id']);
      }

      // prefer campaign_id from parent activity over mailing-derived campaign:
      if (!empty($parentActivity['campaign_id'])) {
        $campaignId = $parentActivity['campaign_id'];
      } else {
        $campaignId = $mailing['campaign_id'];
      }

      $activity->addValue('campaign_id', $campaignId);
    }

    try {
      $activity = $activity->execute()->first();
      civicrm_api3('ActivityContactEmail', 'create', [
        'activity_contact_id' => $activity['activity_contact'][0]['id'],
        'email' => $email,
      ]);
    } catch (Exception $e) {
      Civi::log()->warning('Cannot create OptOutActivity, error: ' . $e->getMessage());
    }
  }

  private static function findParentActivity($mailingId, $contactId, $email): ?array {
    if (empty($email)) {
      Civi::log()->warning('Cannot determine parent activity, cannot find primary email to contact(id=' . $contactId . ').');
      return null;
    }

    try {
      $targetRecordTypeId = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_ActivityContact', 'record_type_id', 'Activity Targets');
      $activityTypeId = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Online_Mailing');
      $emailProviderId = civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'email_provider',
        'name' => 'Mailingwork',
        'return' => 'value',
      ]);
    } catch (Exception $e) {
      Civi::log()->warning('Cannot determine parent activity, error: ' . $e->getMessage());
      return null;
    }

    $sql = "
      SELECT a.id AS activity_id, ac.id AS activity_contact_id, a.campaign_id
      FROM civicrm_activity a
      JOIN civicrm_value_email_information e ON e.entity_id = a.id
      JOIN civicrm_activity_contact ac ON ac.activity_id = a.id AND record_type_id = %1
      JOIN civicrm_activity_contact_email ace ON ace.activity_contact_id = ac.id
      WHERE
        a.activity_type_id = %2 AND
        e.email_provider = %3 AND
        e.mailing_id = %4 AND
        ac.contact_id = %5 AND
        ace.email = %6
      ORDER BY a.activity_date_time DESC
      LIMIT 1
    ";

    try {
      $query = CRM_Core_DAO::executeQuery($sql, [
        1 => [$targetRecordTypeId, 'Integer'],
        2 => [$activityTypeId, 'Integer'],
        3 => [$emailProviderId, 'Integer'],
        4 => [$mailingId, 'String'],
        5 => [$contactId, 'Integer'],
        6 => [$email, 'String'],
      ]);
    } catch (Exception $e) {
      Civi::log()->warning('Cannot determine parent activity, error: ' . $e->getMessage());
      return null;
    }

    if (!$query->fetch()) {
      return null;
    }

    return [
      'id' => $query->activity_id,
      'activity_contact_id' => $query->activity_contact_id,
      'campaign_id' => $query->campaign_id,
    ];
  }

  private static function findMailing($mailingId): ?array {
    if (empty($mailingId)) {
      return NULL;
    }
    try {
      $mailing = reset(civicrm_api3('MailingworkMailing', 'get', [
        'return' => ['id'],
        'mailingwork_identifier' => $mailingId,
        'api.MailingworkMailing.getcampaign' => [],
      ])['values']);
    } catch (CRM_Core_Exception $e) {}

    if (!empty($mailing)) {
      return [
        'id' => $mailing['id'],
        'campaign_id' => $mailing['api.MailingworkMailing.getcampaign']['values']['id'],
      ];
    }

    Civi::log()->warning('Creating OptOutActivity: Mailing id is provided(id=' . $mailingId . '), but cannot find "Mailing" entity.');
    return null;
  }

}
