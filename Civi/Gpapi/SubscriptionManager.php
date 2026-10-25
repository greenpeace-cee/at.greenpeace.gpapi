<?php

namespace Civi\Gpapi;

use Civi;
use Civi\Api4\Contact;
use Civi\Core\Event\PostEvent;
use Civi\Core\Event\PreEvent;
use Civi\Core\Service\AutoSubscriber;

/**
 * Keeps GroupContact and SubscriptionContact records in sync, and the opt-out
 * subscription in sync with Contact.is_opt_out.
 *
 * The gpapi_group_to_subscription_map setting maps group names to subscription
 * names, one to one. For each mapped pair, a change to a contact's GroupContact
 * is applied to their SubscriptionContact and vice versa, including creation
 * and deletion. The exception is a deleted GroupContact, which is mirrored as
 * 'opt_out': the HubSpot sync only pushes changed SubscriptionContacts, not
 * deleted ones. 'Pending' has no subscription equivalent and is mirrored as
 * 'opt_out', which in turn leaves a 'Pending' GroupContact alone.
 *
 * The subscription named in the gpapi_opt_out_subscription setting is synced
 * with Contact.is_opt_out as described in the setting. Changes that bypass
 * hook_civicrm_post, such as CiviMail opt-outs, are not synced. Contact merges
 * also move subscriptions without hooks, so is_opt_out of the merged contact
 * is applied to the opt-out subscription once a merge is done.
 */
class SubscriptionManager extends AutoSubscriber {

  const GROUP_STATUS_TO_SUBSCRIPTION_STATUS = [
    'Added' => 'opt_in',
    'Removed' => 'opt_out',
    'Pending' => 'opt_out',
  ];

  const SUBSCRIPTION_STATUS_TO_GROUP_STATUS = [
    'opt_in' => 'Added',
    'opt_out' => 'Removed',
  ];

  const SUBSCRIPTION_STATUS_TO_IS_OPT_OUT = [
    'opt_in' => FALSE,
    'opt_out' => TRUE,
  ];

  const CONTACT_TYPES = ['Individual', 'Organization', 'Household'];

  /**
   * [group/subscription ID field, status field] of each mirrored entity.
   */
  const ENTITY_FIELDS = [
    'GroupContact' => ['group_id', 'status'],
    'SubscriptionContact' => ['subscription_id', 'subscription_status:name'],
  ];

  /**
   * Records we are currently writing (see writingKey()), so their own post
   * events aren't mirrored back to their origin. Other writes made meanwhile,
   * e.g. by other listeners, are still mirrored.
   */
  private static array $writing = [];

  /**
   * Contact.is_opt_out before an edit, keyed by contact ID.
   */
  private static array $previousIsOptOut = [];

  public static function getSubscribedEvents(): array {
    // SubscriptionContact availability is checked in the listener instead:
    // this runs while the container is compiled, which may happen before the
    // extension providing it has been loaded
    return [
      'hook_civicrm_pre' => ['hook_civicrm_pre', 0],
      'hook_civicrm_post' => ['hook_civicrm_post', 0],
    ];
  }

  public static function hook_civicrm_pre(PreEvent $event): void {
    // note that is_opt_out may be missing from the params even if it changes:
    // the contact edit form only adds it for unchecked boxes after this hook
    if ($event->action === 'edit'
      && in_array($event->entity, self::CONTACT_TYPES, TRUE)
      && !empty(self::getOptOutSubscriptionName())
    ) {
      // keep the value from before the outermost of nested edits: the first
      // post to run applies the change
      self::$previousIsOptOut[(int) $event->id] ??= self::getIsOptOut((int) $event->id);
    }
  }

  public static function hook_civicrm_post(PostEvent $event): void {
    if (!class_exists('\\Civi\\Api4\\SubscriptionContact')) {
      return;
    }

    if ($event->action === 'merge' && $event->entity === 'Contact') {
      self::applyIsOptOutAfterMerge((int) $event->id);
      return;
    }

    if (in_array($event->entity, self::CONTACT_TYPES, TRUE)) {
      self::applyIsOptOutChange($event);
      return;
    }

    if (!in_array($event->entity, ['GroupContact', 'SubscriptionContact'], TRUE)
      || (empty(self::getGroupToSubscriptionMap()) && empty(self::getOptOutSubscriptionName()))
    ) {
      return;
    }

    if ($event->entity === 'GroupContact') {
      [$groupId, $contactIds] = self::getAffectedGroupContacts($event);
      self::mirrorGroupContacts($groupId, $contactIds);
      return;
    }

    $keys = self::getRecordKeys('SubscriptionContact', $event);
    if (!$keys || self::isWriting('SubscriptionContact', ...$keys)) {
      return;
    }
    [$subscriptionId, $contactId] = $keys;
    $status = self::getRecord('SubscriptionContact', $subscriptionId, $contactId)['subscription_status:name'] ?? NULL;
    self::mirrorSubscriptionContact($subscriptionId, $contactId, $status);
    self::applyOptOutSubscription($subscriptionId, $contactId, $status);
  }

  /**
   * hook_civicrm_post passes two different payloads for GroupContact:
   * CRM_Contact_BAO_GroupContact::addContactsToGroup() and
   * ::removeContactsFromGroup() pass the *group* ID and a list of contact IDs,
   * while the regular DAO/API4 write path passes the GroupContact ID and the
   * corresponding DAO.
   *
   * @return array
   *   [group_id, list of contact IDs]
   */
  private static function getAffectedGroupContacts(PostEvent $event): array {
    if (is_array($event->object)) {
      return [(int) $event->id, array_filter(array_map('intval', $event->object))];
    }
    $keys = self::getRecordKeys('GroupContact', $event);
    return $keys ? [$keys[0], [$keys[1]]] : [0, []];
  }

  /**
   * @return int[]|null
   *   [group_id or subscription_id, contact_id] of the record the DAO-style
   *   post event is about, NULL if unknown.
   */
  private static function getRecordKeys(string $entity, PostEvent $event): ?array {
    $targetField = self::ENTITY_FIELDS[$entity][0];
    $targetId = (int) ($event->object->$targetField ?? 0);
    $contactId = (int) ($event->object->contact_id ?? 0);
    if (empty($targetId) || empty($contactId)) {
      // the DAO passed to hook_civicrm_post only contains the values that were
      // written, e.g. when updating nothing but the status via API4
      $record = civicrm_api4($entity, 'get', [
        'select' => [$targetField, 'contact_id'],
        'where' => [['id', '=', (int) $event->id]],
        'checkPermissions' => FALSE,
      ])->first();
      $targetId = (int) ($record[$targetField] ?? 0);
      $contactId = (int) ($record['contact_id'] ?? 0);
    }
    return empty($targetId) || empty($contactId) ? NULL : [$targetId, $contactId];
  }

  private static function mirrorGroupContacts(int $groupId, array $contactIds): void {
    $subscriptionId = self::getSubscriptionIdForGroup($groupId);
    if (is_null($subscriptionId)) {
      return;
    }
    foreach ($contactIds as $contactId) {
      if (self::isWriting('GroupContact', $groupId, $contactId)) {
        continue;
      }
      $groupStatus = self::getRecord('GroupContact', $groupId, $contactId)['status'] ?? NULL;
      if (!is_null($groupStatus) && !isset(self::GROUP_STATUS_TO_SUBSCRIPTION_STATUS[$groupStatus])) {
        Civi::log()->warning(
          "Gpapi.SubscriptionManager: Unknown GroupContact status '{$groupStatus}', not mirroring group {$groupId} for contact {$contactId}"
        );
        continue;
      }
      $subscriptionStatus = is_null($groupStatus) ? 'opt_out' : self::GROUP_STATUS_TO_SUBSCRIPTION_STATUS[$groupStatus];
      self::applyStatus('SubscriptionContact', $subscriptionId, $contactId, $subscriptionStatus);
    }
  }

  private static function mirrorSubscriptionContact(int $subscriptionId, int $contactId, ?string $status): void {
    $groupId = self::getGroupIdForSubscription($subscriptionId);
    if (is_null($groupId)) {
      return;
    }
    if (!is_null($status) && !isset(self::SUBSCRIPTION_STATUS_TO_GROUP_STATUS[$status])) {
      Civi::log()->warning(
        "Gpapi.SubscriptionManager: Unknown subscription status '{$status}', not mirroring subscription {$subscriptionId} for contact {$contactId}"
      );
      return;
    }
    // a deleted SubscriptionContact maps to NULL, deleting the GroupContact
    self::applyStatus('GroupContact', $groupId, $contactId, self::SUBSCRIPTION_STATUS_TO_GROUP_STATUS[$status] ?? NULL);
  }

  private static function applyOptOutSubscription(int $subscriptionId, int $contactId, ?string $status): void {
    if ($subscriptionId !== self::getOptOutSubscriptionId()) {
      return;
    }
    if (is_null($status)) {
      // a deleted record says nothing about whether the contact wants to be
      // opted out, so leave is_opt_out as it is
      return;
    }
    if (!isset(self::SUBSCRIPTION_STATUS_TO_IS_OPT_OUT[$status])) {
      Civi::log()->warning(
        "Gpapi.SubscriptionManager: Unknown subscription status '{$status}', not applying opt-out subscription {$subscriptionId} to contact {$contactId}"
      );
      return;
    }

    $isOptOut = self::SUBSCRIPTION_STATUS_TO_IS_OPT_OUT[$status];
    $updated = Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addWhere('is_opt_out', '!=', $isOptOut)
      ->addValue('is_opt_out', $isOptOut)
      ->execute();
    if ($updated->count()) {
      self::logChange('update', 'Contact', ['id' => $contactId, 'is_opt_out' => $isOptOut]);
    }
  }

  /**
   * Apply a change of Contact.is_opt_out to the opt-out subscription.
   *
   * Changes made by applyOptOutSubscription() come through here as well, but
   * the subscription already matches then, so applyStatus() does nothing.
   */
  private static function applyIsOptOutChange(PostEvent $event): void {
    $contactId = (int) $event->id;
    if ($event->action === 'edit' && array_key_exists($contactId, self::$previousIsOptOut)) {
      $wasOptOut = self::$previousIsOptOut[$contactId];
      unset(self::$previousIsOptOut[$contactId]);
    }
    elseif ($event->action === 'create' && !empty(self::getOptOutSubscriptionName())) {
      $wasOptOut = FALSE;
    }
    else {
      return;
    }

    $isOptOut = self::getIsOptOut($contactId);
    if ($isOptOut === $wasOptOut) {
      return;
    }
    self::applyIsOptOut($contactId, $isOptOut);
  }

  /**
   * Apply is_opt_out of a merged contact to the opt-out subscription.
   *
   * Merges move the duplicate's subscriptions with SQL, so the merged contact
   * may e.g. have an opted-in subscription while is_opt_out is set.
   */
  private static function applyIsOptOutAfterMerge(int $contactId): void {
    if (empty(self::getOptOutSubscriptionName())) {
      return;
    }
    self::applyIsOptOut($contactId, self::getIsOptOut($contactId));
  }

  /**
   * Bring the contact's opt-out subscription in line with is_opt_out.
   */
  private static function applyIsOptOut(int $contactId, bool $isOptOut): void {
    $subscriptionId = self::getOptOutSubscriptionId();
    if (is_null($subscriptionId)) {
      return;
    }
    if (!$isOptOut && empty(self::getRecord('SubscriptionContact', $subscriptionId, $contactId))) {
      // clearing is_opt_out doesn't subscribe contacts who never were
      return;
    }
    self::applyStatus('SubscriptionContact', $subscriptionId, $contactId, $isOptOut ? 'opt_out' : 'opt_in');
  }

  /**
   * Bring a contact's GroupContact/SubscriptionContact record into the given
   * status, creating or deleting it as needed. A NULL $status means the record
   * should not exist.
   */
  private static function applyStatus(string $entity, int $targetId, int $contactId, ?string $status): void {
    [$targetField, $statusField] = self::ENTITY_FIELDS[$entity];
    $record = self::getRecord($entity, $targetId, $contactId);
    $currentStatus = $record[$statusField] ?? NULL;
    if ($currentStatus === $status) {
      return;
    }
    if ($currentStatus === 'Pending' && $status === 'Removed') {
      // 'Pending' already mirrors as 'opt_out', so there's nothing to do and
      // we'd needlessly discard a pending double opt-in
      return;
    }

    $values = ['contact_id' => $contactId, $targetField => $targetId, $statusField => $status];
    $writingKey = self::writingKey($entity, $targetId, $contactId);
    self::$writing[$writingKey] = TRUE;
    try {
      if (is_null($status)) {
        civicrm_api4($entity, 'delete', [
          'where' => [['id', '=', $record['id']]],
          'checkPermissions' => FALSE,
        ]);
        self::logChange('delete', $entity, ['id' => $record['id']] + $values);
      }
      elseif (empty($record)) {
        $created = civicrm_api4($entity, 'create', [
          'values' => $values,
          'checkPermissions' => FALSE,
        ])->first();
        self::logChange('create', $entity, ['id' => $created['id']] + $values);
      }
      else {
        civicrm_api4($entity, 'update', [
          'where' => [['id', '=', $record['id']]],
          'values' => [$statusField => $status],
          'checkPermissions' => FALSE,
        ]);
        self::logChange('update', $entity, ['id' => $record['id'], 'previous_status' => $currentStatus] + $values);
      }
    }
    finally {
      unset(self::$writing[$writingKey]);
    }
  }

  /**
   * @return array|null
   *   id and status of the contact's GroupContact/SubscriptionContact record.
   */
  private static function getRecord(string $entity, int $targetId, int $contactId): ?array {
    [$targetField, $statusField] = self::ENTITY_FIELDS[$entity];
    return civicrm_api4($entity, 'get', [
      'select' => ['id', $statusField],
      'where' => [[$targetField, '=', $targetId], ['contact_id', '=', $contactId]],
      'checkPermissions' => FALSE,
    ])->first();
  }

  private static function getSubscriptionIdForGroup(int $groupId): ?int {
    $map = self::getGroupToSubscriptionMap();
    if (empty($map)) {
      return NULL;
    }
    $subscriptionName = $map[self::getNameById('Group', $groupId)] ?? NULL;
    return is_null($subscriptionName) ? NULL : self::getIdByName('Subscription', $subscriptionName);
  }

  private static function getGroupIdForSubscription(int $subscriptionId): ?int {
    $map = self::getGroupToSubscriptionMap();
    if (empty($map)) {
      return NULL;
    }
    $groupName = array_search(self::getNameById('Subscription', $subscriptionId), $map, TRUE);
    return $groupName === FALSE ? NULL : self::getIdByName('Group', $groupName);
  }

  private static function getGroupToSubscriptionMap(): array {
    return (array) Civi::settings()->get('gpapi_group_to_subscription_map');
  }

  private static function getOptOutSubscriptionName(): string {
    return (string) Civi::settings()->get('gpapi_opt_out_subscription');
  }

  private static function getOptOutSubscriptionId(): ?int {
    $subscriptionName = self::getOptOutSubscriptionName();
    return empty($subscriptionName) ? NULL : self::getIdByName('Subscription', $subscriptionName);
  }

  private static function getIsOptOut(int $contactId): bool {
    return (bool) (Contact::get(FALSE)
      ->addSelect('is_opt_out')
      ->addWhere('id', '=', $contactId)
      ->execute()
      ->first()['is_opt_out'] ?? FALSE);
  }

  private static function getNameById(string $entity, int $id): ?string {
    return civicrm_api4($entity, 'get', [
      'select' => ['name'],
      'where' => [['id', '=', $id]],
      'checkPermissions' => FALSE,
    ])->first()['name'] ?? NULL;
  }

  /**
   * Resolve a group/subscription name taken from the settings.
   */
  private static function getIdByName(string $entity, string $name): ?int {
    $id = civicrm_api4($entity, 'get', [
      'select' => ['id'],
      'where' => [['name', '=', $name]],
      'checkPermissions' => FALSE,
    ])->first()['id'] ?? NULL;
    if (is_null($id)) {
      Civi::log()->warning("Gpapi.SubscriptionManager: Configured {$entity} '{$name}' does not exist");
      return NULL;
    }
    return (int) $id;
  }

  private static function writingKey(string $entity, int $targetId, int $contactId): string {
    return "{$entity}:{$targetId}:{$contactId}";
  }

  private static function isWriting(string $entity, int $targetId, int $contactId): bool {
    return isset(self::$writing[self::writingKey($entity, $targetId, $contactId)]);
  }

  private static function logChange(string $action, string $entity, array $change): void {
    Civi::log()->debug(
      "Gpapi.SubscriptionManager: {$action} {$entity} " . json_encode($change),
      ['action' => $action, 'entity' => $entity] + $change
    );
  }

}
