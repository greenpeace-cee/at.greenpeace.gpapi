<?php

namespace Civi\Gpapi;

use Civi;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Api4\Subscription;
use Civi\Api4\SubscriptionContact;
use Civi\Core\Event\PostEvent;
use Civi\Core\Service\AutoSubscriber;
use CRM_Contact_DAO_GroupContact;

/**
 * Keeps GroupContact and SubscriptionContact records in sync.
 *
 * Groups are mapped to subscriptions via the gpapi_group_to_subscription_map
 * setting, which maps group names to subscription names, one to one. For every
 * mapped group/subscription pair, both entities are mirrors of each other: a
 * change to one is applied to the other, including creation and deletion.
 *
 * civicrm_group_contact.status has no equivalent of 'Pending' on the
 * subscription side, so 'Pending' is mirrored as 'opt_out'. To keep the
 * mapping convergent (and to avoid destroying a pending double opt-in),
 * 'opt_out' only flips an existing GroupContact to 'Removed' when it isn't
 * already 'Pending'.
 */
class SubscriptionManager extends AutoSubscriber {

  /**
   * Maps civicrm_group_contact.status to subscription_status option names.
   */
  const GROUP_STATUS_TO_SUBSCRIPTION_STATUS = [
    'Added' => 'opt_in',
    'Removed' => 'opt_out',
    'Pending' => 'opt_out',
  ];

  /**
   * Maps subscription_status option names to civicrm_group_contact.status.
   */
  const SUBSCRIPTION_STATUS_TO_GROUP_STATUS = [
    'opt_in' => 'Added',
    'opt_out' => 'Removed',
  ];

  /**
   * Guards against bouncing our own mirrored writes back to their origin.
   *
   * @var bool
   */
  private static bool $isSyncing = FALSE;

  public static function getSubscribedEvents(): array {
    // note that the availability of SubscriptionContact is checked in the
    // listener rather than here: this runs while the container is compiled,
    // which may happen before the extension providing it has been loaded
    return [
      'hook_civicrm_post' => ['hook_civicrm_post', 0],
    ];
  }

  /**
   * Synchronize changes to GroupContact to SubscriptionContact and vice-versa
   *
   * @param \Civi\Core\Event\PostEvent $event
   *
   * @return void
   */
  public static function hook_civicrm_post(PostEvent $event): void {
    if (!in_array($event->entity, ['GroupContact', 'SubscriptionContact'], TRUE)) {
      return;
    }
    if (!class_exists('\\Civi\\Api4\\SubscriptionContact')) {
      // can't manage subscriptions without a subscription API4
      return;
    }
    if (self::$isSyncing) {
      // this change is one we made ourselves while mirroring
      return;
    }

    self::$isSyncing = TRUE;
    try {
      if ($event->entity === 'GroupContact') {
        self::mirrorGroupContacts(self::getAffectedGroupContacts($event));
      }
      else {
        self::mirrorSubscriptionContacts(self::getAffectedSubscriptionContacts($event));
      }
    }
    finally {
      self::$isSyncing = FALSE;
    }
  }

  /**
   * Determine the group/contact pairs affected by a GroupContact post event.
   *
   * hook_civicrm_post passes two different payloads for GroupContact:
   * CRM_Contact_BAO_GroupContact::addContactsToGroup() and
   * ::removeContactsFromGroup() pass the *group* ID and a list of contact IDs,
   * while the regular DAO/API4 write path passes the GroupContact ID and the
   * corresponding DAO.
   *
   * @param \Civi\Core\Event\PostEvent $event
   *
   * @return array
   *   List of [group_id, contact_id] pairs.
   */
  private static function getAffectedGroupContacts(PostEvent $event): array {
    if (is_array($event->object)) {
      $groupId = (int) $event->id;
      if (empty($groupId)) {
        return [];
      }
      $groupContacts = [];
      foreach ($event->object as $contactId) {
        if (!empty($contactId)) {
          $groupContacts[] = [$groupId, (int) $contactId];
        }
      }
      return $groupContacts;
    }

    if (!$event->object instanceof CRM_Contact_DAO_GroupContact) {
      return [];
    }

    $groupId = (int) ($event->object->group_id ?? 0);
    $contactId = (int) ($event->object->contact_id ?? 0);
    if (empty($groupId) || empty($contactId)) {
      // the DAO passed to hook_civicrm_post only contains the values that were
      // written, e.g. when updating nothing but the status via API4
      [$groupId, $contactId] = self::loadGroupContactKeys((int) $event->id);
    }

    return empty($groupId) || empty($contactId) ? [] : [[$groupId, $contactId]];
  }

  /**
   * Determine the contact/subscription pairs affected by a SubscriptionContact
   * post event.
   *
   * @param \Civi\Core\Event\PostEvent $event
   *
   * @return array
   *   List of [subscription_id, contact_id] pairs.
   */
  private static function getAffectedSubscriptionContacts(PostEvent $event): array {
    if (!is_object($event->object)) {
      return [];
    }

    $subscriptionId = (int) ($event->object->subscription_id ?? 0);
    $contactId = (int) ($event->object->contact_id ?? 0);
    if (empty($subscriptionId) || empty($contactId)) {
      [$subscriptionId, $contactId] = self::loadSubscriptionContactKeys((int) $event->id);
    }

    return empty($subscriptionId) || empty($contactId) ? [] : [[$subscriptionId, $contactId]];
  }

  /**
   * Apply GroupContact changes to the mapped SubscriptionContact records.
   *
   * @param array $groupContacts
   *   List of [group_id, contact_id] pairs.
   *
   * @return void
   */
  private static function mirrorGroupContacts(array $groupContacts): void {
    $subscriptionIds = [];
    foreach ($groupContacts as [$groupId, $contactId]) {
      if (!array_key_exists($groupId, $subscriptionIds)) {
        $subscriptionIds[$groupId] = self::getSubscriptionIdForGroup($groupId);
      }
      if (empty($subscriptionIds[$groupId])) {
        continue;
      }

      $groupStatus = self::getGroupContactStatus($groupId, $contactId);
      if (is_null($groupStatus)) {
        // the GroupContact record is gone, so the mirror should be gone too
        $subscriptionStatus = NULL;
      }
      elseif (isset(self::GROUP_STATUS_TO_SUBSCRIPTION_STATUS[$groupStatus])) {
        $subscriptionStatus = self::GROUP_STATUS_TO_SUBSCRIPTION_STATUS[$groupStatus];
      }
      else {
        Civi::log()->warning(
          "Gpapi.SubscriptionManager: Unknown GroupContact status '{$groupStatus}', not mirroring group {$groupId} for contact {$contactId}"
        );
        continue;
      }

      self::applySubscriptionContact($subscriptionIds[$groupId], $contactId, $subscriptionStatus);
    }
  }

  /**
   * Apply SubscriptionContact changes to the mapped GroupContact records.
   *
   * @param array $subscriptionContacts
   *   List of [subscription_id, contact_id] pairs.
   *
   * @return void
   */
  private static function mirrorSubscriptionContacts(array $subscriptionContacts): void {
    $groupIds = [];
    foreach ($subscriptionContacts as [$subscriptionId, $contactId]) {
      if (!array_key_exists($subscriptionId, $groupIds)) {
        $groupIds[$subscriptionId] = self::getGroupIdForSubscription($subscriptionId);
      }
      if (empty($groupIds[$subscriptionId])) {
        continue;
      }

      $subscriptionStatus = self::getSubscriptionContactStatus($subscriptionId, $contactId);
      if (is_null($subscriptionStatus)) {
        // the SubscriptionContact record is gone, so the mirror should be gone too
        $groupStatus = NULL;
      }
      elseif (isset(self::SUBSCRIPTION_STATUS_TO_GROUP_STATUS[$subscriptionStatus])) {
        $groupStatus = self::SUBSCRIPTION_STATUS_TO_GROUP_STATUS[$subscriptionStatus];
      }
      else {
        Civi::log()->warning(
          "Gpapi.SubscriptionManager: Unknown subscription status '{$subscriptionStatus}', not mirroring subscription {$subscriptionId} for contact {$contactId}"
        );
        continue;
      }

      self::applyGroupContact($groupIds[$subscriptionId], $contactId, $groupStatus);
    }
  }

  /**
   * Bring a SubscriptionContact record into the given state.
   *
   * @param int $subscriptionId
   * @param int $contactId
   * @param string|null $status
   *   subscription_status option name, or NULL if the record should not exist.
   *
   * @return void
   */
  private static function applySubscriptionContact(int $subscriptionId, int $contactId, ?string $status): void {
    $subscriptionContact = SubscriptionContact::get(FALSE)
      ->addSelect('id', 'subscription_status:name')
      ->addWhere('subscription_id', '=', $subscriptionId)
      ->addWhere('contact_id', '=', $contactId)
      ->execute()
      ->first();

    $change = [
      'contact_id' => $contactId,
      'subscription_id' => $subscriptionId,
      'subscription_status' => $status,
    ];

    if (is_null($status)) {
      if (empty($subscriptionContact)) {
        return;
      }
      SubscriptionContact::delete(FALSE)
        ->addWhere('id', '=', $subscriptionContact['id'])
        ->execute();
      self::logChange('delete', 'SubscriptionContact', ['id' => $subscriptionContact['id']] + $change);
      return;
    }

    if (empty($subscriptionContact)) {
      $created = SubscriptionContact::create(FALSE)
        ->addValue('contact_id', $contactId)
        ->addValue('subscription_id', $subscriptionId)
        ->addValue('subscription_status:name', $status)
        ->execute()
        ->first();
      self::logChange('create', 'SubscriptionContact', ['id' => $created['id']] + $change);
      return;
    }

    if ($subscriptionContact['subscription_status:name'] === $status) {
      return;
    }

    SubscriptionContact::update(FALSE)
      ->addWhere('id', '=', $subscriptionContact['id'])
      ->addValue('subscription_status:name', $status)
      ->execute();
    self::logChange('update', 'SubscriptionContact', [
      'id' => $subscriptionContact['id'],
      'previous_subscription_status' => $subscriptionContact['subscription_status:name'],
    ] + $change);
  }

  /**
   * Bring a GroupContact record into the given state.
   *
   * @param int $groupId
   * @param int $contactId
   * @param string|null $status
   *   civicrm_group_contact.status, or NULL if the record should not exist.
   *
   * @return void
   */
  private static function applyGroupContact(int $groupId, int $contactId, ?string $status): void {
    $groupContact = GroupContact::get(FALSE)
      ->addSelect('id', 'status')
      ->addWhere('group_id', '=', $groupId)
      ->addWhere('contact_id', '=', $contactId)
      ->execute()
      ->first();

    $change = [
      'contact_id' => $contactId,
      'group_id' => $groupId,
      'status' => $status,
    ];

    if (is_null($status)) {
      if (empty($groupContact)) {
        return;
      }
      GroupContact::delete(FALSE)
        ->addWhere('id', '=', $groupContact['id'])
        ->execute();
      self::logChange('delete', 'GroupContact', ['id' => $groupContact['id']] + $change);
      return;
    }

    if (empty($groupContact)) {
      $created = GroupContact::create(FALSE)
        ->addValue('contact_id', $contactId)
        ->addValue('group_id', $groupId)
        ->addValue('status', $status)
        ->execute()
        ->first();
      self::logChange('create', 'GroupContact', ['id' => $created['id']] + $change);
      return;
    }

    if ($groupContact['status'] === $status) {
      return;
    }

    if ($status === 'Removed' && $groupContact['status'] === 'Pending') {
      // 'Pending' already mirrors as 'opt_out', so there's nothing to do and
      // we'd needlessly discard a pending double opt-in
      return;
    }

    GroupContact::update(FALSE)
      ->addWhere('id', '=', $groupContact['id'])
      ->addValue('status', $status)
      ->execute();
    self::logChange('update', 'GroupContact', [
      'id' => $groupContact['id'],
      'previous_status' => $groupContact['status'],
    ] + $change);
  }

  /**
   * Get the current status of a GroupContact record.
   *
   * @param int $groupId
   * @param int $contactId
   *
   * @return string|null
   *   NULL if there is no such record.
   */
  private static function getGroupContactStatus(int $groupId, int $contactId): ?string {
    $groupContact = GroupContact::get(FALSE)
      ->addSelect('status')
      ->addWhere('group_id', '=', $groupId)
      ->addWhere('contact_id', '=', $contactId)
      ->execute()
      ->first();
    return $groupContact['status'] ?? NULL;
  }

  /**
   * Get the current status of a SubscriptionContact record.
   *
   * @param int $subscriptionId
   * @param int $contactId
   *
   * @return string|null
   *   subscription_status option name, NULL if there is no such record.
   */
  private static function getSubscriptionContactStatus(int $subscriptionId, int $contactId): ?string {
    $subscriptionContact = SubscriptionContact::get(FALSE)
      ->addSelect('subscription_status:name')
      ->addWhere('subscription_id', '=', $subscriptionId)
      ->addWhere('contact_id', '=', $contactId)
      ->execute()
      ->first();
    return $subscriptionContact['subscription_status:name'] ?? NULL;
  }

  /**
   * Get the group_id/contact_id of a GroupContact record.
   *
   * @param int $groupContactId
   *
   * @return array
   *   [group_id, contact_id], both 0 if the record doesn't exist.
   */
  private static function loadGroupContactKeys(int $groupContactId): array {
    if (empty($groupContactId)) {
      return [0, 0];
    }
    $groupContact = GroupContact::get(FALSE)
      ->addSelect('group_id', 'contact_id')
      ->addWhere('id', '=', $groupContactId)
      ->execute()
      ->first();
    return [(int) ($groupContact['group_id'] ?? 0), (int) ($groupContact['contact_id'] ?? 0)];
  }

  /**
   * Get the subscription_id/contact_id of a SubscriptionContact record.
   *
   * @param int $subscriptionContactId
   *
   * @return array
   *   [subscription_id, contact_id], both 0 if the record doesn't exist.
   */
  private static function loadSubscriptionContactKeys(int $subscriptionContactId): array {
    if (empty($subscriptionContactId)) {
      return [0, 0];
    }
    $subscriptionContact = SubscriptionContact::get(FALSE)
      ->addSelect('subscription_id', 'contact_id')
      ->addWhere('id', '=', $subscriptionContactId)
      ->execute()
      ->first();
    return [
      (int) ($subscriptionContact['subscription_id'] ?? 0),
      (int) ($subscriptionContact['contact_id'] ?? 0),
    ];
  }

  /**
   * Resolve the subscription a group is mapped to.
   *
   * @param int $groupId
   *
   * @return int|null
   *   NULL if the group isn't mapped or the subscription doesn't exist.
   */
  private static function getSubscriptionIdForGroup(int $groupId): ?int {
    $map = self::getGroupToSubscriptionMap();
    if (empty($map)) {
      return NULL;
    }

    $groupName = Group::get(FALSE)
      ->addSelect('name')
      ->addWhere('id', '=', $groupId)
      ->execute()
      ->first()['name'] ?? NULL;
    if (is_null($groupName) || !isset($map[$groupName])) {
      return NULL;
    }

    $subscriptionId = Subscription::get(FALSE)
      ->addSelect('id')
      ->addWhere('name', '=', $map[$groupName])
      ->execute()
      ->first()['id'] ?? NULL;
    if (is_null($subscriptionId)) {
      Civi::log()->warning(
        "Gpapi.SubscriptionManager: Group '{$groupName}' is mapped to unknown subscription '{$map[$groupName]}'"
      );
      return NULL;
    }

    return (int) $subscriptionId;
  }

  /**
   * Resolve the group a subscription is mapped to.
   *
   * @param int $subscriptionId
   *
   * @return int|null
   *   NULL if the subscription isn't mapped or the group doesn't exist.
   */
  private static function getGroupIdForSubscription(int $subscriptionId): ?int {
    $map = self::getGroupToSubscriptionMap();
    if (empty($map)) {
      return NULL;
    }

    $subscriptionName = Subscription::get(FALSE)
      ->addSelect('name')
      ->addWhere('id', '=', $subscriptionId)
      ->execute()
      ->first()['name'] ?? NULL;
    if (is_null($subscriptionName)) {
      return NULL;
    }

    $groupName = array_search($subscriptionName, $map, TRUE);
    if ($groupName === FALSE) {
      return NULL;
    }

    $groupId = Group::get(FALSE)
      ->addSelect('id')
      ->addWhere('name', '=', $groupName)
      ->execute()
      ->first()['id'] ?? NULL;
    if (is_null($groupId)) {
      Civi::log()->warning(
        "Gpapi.SubscriptionManager: Subscription '{$subscriptionName}' is mapped to unknown group '{$groupName}'"
      );
      return NULL;
    }

    return (int) $groupId;
  }

  /**
   * @return array
   *   Map of group name to subscription name.
   */
  private static function getGroupToSubscriptionMap(): array {
    return (array) (Civi::settings()->get('gpapi_group_to_subscription_map') ?? []);
  }

  /**
   * Log a change made by this hook.
   *
   * @param string $action
   *   One of 'create', 'update', 'delete'.
   * @param string $entity
   * @param array $change
   *
   * @return void
   */
  private static function logChange(string $action, string $entity, array $change): void {
    Civi::log()->debug(
      "Gpapi.SubscriptionManager: {$action} {$entity} " . json_encode($change),
      ['action' => $action, 'entity' => $entity] + $change
    );
  }

}
