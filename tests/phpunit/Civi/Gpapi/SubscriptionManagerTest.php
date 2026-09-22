<?php
namespace Civi\Gpapi;

use Civi;
use Civi\Api4\Contact;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Api4\Subscription;
use Civi\Api4\SubscriptionContact;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_Contact_BAO_GroupContact;

/**
 *
 * @group headless
 */
class SubscriptionManagerTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * Names of the groups that are mapped to a subscription.
   */
  const MAPPED_GROUPS = [
    'community' => 'community_nl',
    'event' => 'event_nl',
  ];

  /**
   * Names of the subscriptions the mapped groups point to.
   */
  const MAPPED_SUBSCRIPTIONS = [
    'community' => 'community_newsletter',
    'event' => 'event_newsletter',
  ];

  const UNMAPPED_GROUP = 'admin';

  const UNMAPPED_SUBSCRIPTION = 'unmapped_newsletter';

  /**
   * @var int[]
   *   Group IDs keyed by shorthand, plus 'unmapped'.
   */
  protected array $groupIds = [];

  /**
   * @var int[]
   *   Subscription IDs keyed by shorthand, plus 'unmapped'.
   */
  protected array $subscriptionIds = [];

  /**
   * @var int[]
   */
  protected array $contactIds = [];

  /**
   * Setup used when HeadlessInterface is implemented.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * @link https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   *
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      // gpapi's class scanning loads CRM_Gpapi_Identitytracker_Configuration,
      // which extends a class provided by de.systopia.identitytracker
      ->install('de.systopia.identitytracker')
      ->install('hubspot')
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp():void {
    parent::setUp();

    foreach (self::MAPPED_GROUPS as $key => $name) {
      $this->groupIds[$key] = $this->createGroup($name);
    }
    $this->groupIds['unmapped'] = $this->createGroup(self::UNMAPPED_GROUP);

    foreach (self::MAPPED_SUBSCRIPTIONS as $key => $name) {
      $this->subscriptionIds[$key] = $this->createSubscription($name);
    }
    $this->subscriptionIds['unmapped'] = $this->createSubscription(self::UNMAPPED_SUBSCRIPTION);

    $this->contactIds = [
      'first' => $this->createContact('First'),
      'second' => $this->createContact('Second'),
    ];

    Civi::settings()->set('gpapi_group_to_subscription_map', self::defaultMap());
  }

  public function tearDown():void {
    Civi::settings()->revert('gpapi_group_to_subscription_map');
    parent::tearDown();
  }

  /**
   * civicrm_group_contact.status and the subscription_status it maps to.
   */
  public function groupStatusProvider(): array {
    return [
      'added' => ['Added', 'opt_in'],
      'removed' => ['Removed', 'opt_out'],
      // subscription_status has no equivalent of 'Pending'
      'pending' => ['Pending', 'opt_out'],
    ];
  }

  /**
   * subscription_status and the civicrm_group_contact.status it maps to.
   */
  public function subscriptionStatusProvider(): array {
    return [
      'opt in' => ['opt_in', 'Added'],
      'opt out' => ['opt_out', 'Removed'],
    ];
  }

  /**
   * Group shorthands and maps for which no SubscriptionContact should appear.
   */
  public function unmappedGroupProvider(): array {
    return [
      'group missing from map' => ['unmapped', self::defaultMap()],
      'empty map' => ['community', []],
      'unknown subscription' => ['community', [self::MAPPED_GROUPS['community'] => 'no_such_subscription']],
    ];
  }

  /**
   * Subscription shorthands and maps for which no GroupContact should appear.
   */
  public function unmappedSubscriptionProvider(): array {
    return [
      'subscription missing from map' => ['unmapped', self::defaultMap()],
      'empty map' => ['community', []],
      'unknown group' => ['community', ['no_such_group' => self::MAPPED_SUBSCRIPTIONS['community']]],
    ];
  }

  /**
   * Every GroupContact status is mirrored, from every other status.
   *
   * @dataProvider groupStatusProvider
   */
  public function testGroupContactStatusIsMirrored(string $status, string $expected): void {
    $groupContactId = $this->createGroupContact('community', 'first', $status);
    $subscriptionContact = $this->assertSubscriptionStatus(
      $expected, 'community', 'first', "GroupContact created as '{$status}'"
    );

    foreach ($this->groupStatusProvider() as [$newStatus, $newExpected]) {
      // note that API4 only passes the changed values to hook_civicrm_post,
      // so the hook has to resolve group_id/contact_id itself
      GroupContact::update(FALSE)
        ->addWhere('id', '=', $groupContactId)
        ->addValue('status', $newStatus)
        ->execute();

      $context = "GroupContact changed from '{$status}' to '{$newStatus}'";
      $updated = $this->assertSubscriptionStatus($newExpected, 'community', 'first', $context);
      $this->assertEquals(
        $subscriptionContact['id'],
        $updated['id'],
        "SubscriptionContact was recreated rather than updated: {$context}"
      );
    }
  }

  /**
   * Every subscription status is mirrored, from every other status.
   *
   * @dataProvider subscriptionStatusProvider
   */
  public function testSubscriptionContactStatusIsMirrored(string $status, string $expected): void {
    $subscriptionContactId = $this->createSubscriptionContact('community', 'first', $status);
    $groupContact = $this->assertGroupStatus(
      $expected, 'community', 'first', "SubscriptionContact created as '{$status}'"
    );

    foreach ($this->subscriptionStatusProvider() as [$newStatus, $newExpected]) {
      SubscriptionContact::update(FALSE)
        ->addWhere('id', '=', $subscriptionContactId)
        ->addValue('subscription_status:name', $newStatus)
        ->execute();

      $context = "SubscriptionContact changed from '{$status}' to '{$newStatus}'";
      $updated = $this->assertGroupStatus($newExpected, 'community', 'first', $context);
      $this->assertEquals(
        $groupContact['id'],
        $updated['id'],
        "GroupContact was recreated rather than updated: {$context}"
      );
    }
  }

  public function testSaveActionIsMirroredFromBothSides(): void {
    GroupContact::save(FALSE)
      ->addRecord(['group_id' => $this->groupIds['community'], 'contact_id' => $this->contactIds['first'], 'status' => 'Removed'])
      ->setMatch(['group_id', 'contact_id'])
      ->execute();
    $this->assertSubscriptionStatus('opt_out', 'community', 'first', 'GroupContact saved as new record');

    GroupContact::save(FALSE)
      ->addRecord(['group_id' => $this->groupIds['community'], 'contact_id' => $this->contactIds['first'], 'status' => 'Added'])
      ->setMatch(['group_id', 'contact_id'])
      ->execute();
    $this->assertSubscriptionStatus('opt_in', 'community', 'first', 'GroupContact saved over existing record');

    SubscriptionContact::save(FALSE)
      ->addRecord(['contact_id' => $this->contactIds['first'], 'subscription_id' => $this->subscriptionIds['event'], 'subscription_status:name' => 'opt_in'])
      ->setMatch(['contact_id', 'subscription_id'])
      ->execute();
    $this->assertGroupStatus('Added', 'event', 'first', 'SubscriptionContact saved as new record');

    SubscriptionContact::save(FALSE)
      ->addRecord(['contact_id' => $this->contactIds['first'], 'subscription_id' => $this->subscriptionIds['event'], 'subscription_status:name' => 'opt_out'])
      ->setMatch(['contact_id', 'subscription_id'])
      ->execute();
    $this->assertGroupStatus('Removed', 'event', 'first', 'SubscriptionContact saved over existing record');
  }

  public function testDeletionIsMirroredFromBothSides(): void {
    $groupContactId = $this->createGroupContact('community', 'first', 'Added');
    $this->assertSubscriptionStatus('opt_in', 'community', 'first');

    GroupContact::delete(FALSE)->addWhere('id', '=', $groupContactId)->execute();
    $this->assertSubscriptionStatus(NULL, 'community', 'first', 'GroupContact deleted');

    $subscriptionContactId = $this->createSubscriptionContact('community', 'first', 'opt_in');
    $this->assertGroupStatus('Added', 'community', 'first');

    SubscriptionContact::delete(FALSE)->addWhere('id', '=', $subscriptionContactId)->execute();
    $this->assertGroupStatus(NULL, 'community', 'first', 'SubscriptionContact deleted');
  }

  /**
   * The legacy BAO passes the group ID and a list of contact IDs to
   * hook_civicrm_post, rather than a GroupContact ID and DAO.
   */
  public function testLegacyBulkGroupContactPathsAreMirrored(): void {
    $contactIds = array_values($this->contactIds);

    CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIds, $this->groupIds['community']);
    $this->assertSubscriptionStatus('opt_in', 'community', 'first', 'bulk add');
    $this->assertSubscriptionStatus('opt_in', 'community', 'second', 'bulk add');

    CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contactIds, $this->groupIds['community']);
    $this->assertGroupStatus('Removed', 'community', 'first', 'bulk remove');
    $this->assertSubscriptionStatus('opt_out', 'community', 'first', 'bulk remove');
    $this->assertSubscriptionStatus('opt_out', 'community', 'second', 'bulk remove');

    CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contactIds, $this->groupIds['community'], 'Admin', 'Deleted');
    $this->assertGroupStatus(NULL, 'community', 'first', 'bulk delete');
    $this->assertSubscriptionStatus(NULL, 'community', 'first', 'bulk delete');
    $this->assertSubscriptionStatus(NULL, 'community', 'second', 'bulk delete');

    CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIds, $this->groupIds['community'], 'Admin', 'Pending');
    $this->assertSubscriptionStatus('opt_out', 'community', 'first', 'bulk add as pending');
  }

  public function testApi3GroupContactCreateAndDeleteAreMirrored(): void {
    civicrm_api3('GroupContact', 'create', [
      'contact_id' => $this->contactIds['first'],
      'group_id' => $this->groupIds['event'],
    ]);
    $this->assertSubscriptionStatus('opt_in', 'event', 'first', 'API3 create');

    // API3 delete sets the status to 'Removed' rather than dropping the record
    civicrm_api3('GroupContact', 'delete', [
      'contact_id' => $this->contactIds['first'],
      'group_id' => $this->groupIds['event'],
    ]);
    $this->assertSubscriptionStatus('opt_out', 'event', 'first', 'API3 delete');
  }

  public function testGroupsAndContactsAreMirroredIndependently(): void {
    $this->createGroupContact('community', 'first', 'Added');
    $this->createGroupContact('event', 'first', 'Removed');

    $this->assertSubscriptionStatus('opt_in', 'community', 'first');
    $this->assertSubscriptionStatus('opt_out', 'event', 'first');
    $this->assertSubscriptionStatus(NULL, 'community', 'second', 'contact is in neither group');
    $this->assertSubscriptionStatus(NULL, 'event', 'second', 'contact is in neither group');
  }

  public function testOptOutLeavesPendingGroupContactAlone(): void {
    $this->createGroupContact('community', 'first', 'Pending');
    $this->assertSubscriptionStatus('opt_out', 'community', 'first');

    // re-asserting opt_out must not discard the pending double opt-in
    SubscriptionContact::update(FALSE)
      ->addWhere('contact_id', '=', $this->contactIds['first'])
      ->addWhere('subscription_id', '=', $this->subscriptionIds['community'])
      ->addValue('subscription_status:name', 'opt_out')
      ->execute();

    $this->assertGroupStatus('Pending', 'community', 'first', 'opt_out mirrored back');
  }

  /**
   * @dataProvider unmappedGroupProvider
   */
  public function testUnmappedGroupIsIgnored(string $group, array $map): void {
    Civi::settings()->set('gpapi_group_to_subscription_map', $map);

    $this->createGroupContact($group, 'first', 'Added');

    $this->assertCount(0, SubscriptionContact::get(FALSE)
      ->addWhere('contact_id', '=', $this->contactIds['first'])
      ->execute(), 'Unmapped group should not produce a SubscriptionContact');
  }

  /**
   * @dataProvider unmappedSubscriptionProvider
   */
  public function testUnmappedSubscriptionIsIgnored(string $subscription, array $map): void {
    Civi::settings()->set('gpapi_group_to_subscription_map', $map);

    $this->createSubscriptionContact($subscription, 'first', 'opt_in');

    $this->assertCount(0, GroupContact::get(FALSE)
      ->addWhere('contact_id', '=', $this->contactIds['first'])
      ->execute(), 'Unmapped subscription should not produce a GroupContact');
  }

  /*
   * Helpers
   */

  protected static function defaultMap(): array {
    return [
      self::MAPPED_GROUPS['community'] => self::MAPPED_SUBSCRIPTIONS['community'],
      self::MAPPED_GROUPS['event'] => self::MAPPED_SUBSCRIPTIONS['event'],
    ];
  }

  protected function createGroup(string $name): int {
    return (int) Group::create(FALSE)
      ->addValue('name', $name)
      ->addValue('title', $name)
      ->execute()
      ->first()['id'];
  }

  protected function createSubscription(string $name): int {
    return (int) Subscription::create(FALSE)
      ->addValue('name', $name)
      ->addValue('title', $name)
      ->execute()
      ->first()['id'];
  }

  protected function createContact(string $lastName): int {
    return (int) Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Subscription')
      ->addValue('last_name', $lastName)
      ->execute()
      ->first()['id'];
  }

  protected function createGroupContact(string $group, string $contact, string $status): int {
    return (int) GroupContact::create(FALSE)
      ->addValue('group_id', $this->groupIds[$group])
      ->addValue('contact_id', $this->contactIds[$contact])
      ->addValue('status', $status)
      ->execute()
      ->first()['id'];
  }

  protected function createSubscriptionContact(string $subscription, string $contact, string $status): int {
    return (int) SubscriptionContact::create(FALSE)
      ->addValue('subscription_id', $this->subscriptionIds[$subscription])
      ->addValue('contact_id', $this->contactIds[$contact])
      ->addValue('subscription_status:name', $status)
      ->execute()
      ->first()['id'];
  }

  /**
   * Assert the GroupContact mirroring a subscription is in the given state.
   *
   * @param string|null $expected
   *   civicrm_group_contact.status, or NULL if there should be no record.
   * @param string $group
   * @param string $contact
   * @param string $context
   *   What was done to bring about the expected state.
   *
   * @return array|null
   *   The GroupContact record, if any.
   */
  protected function assertGroupStatus(?string $expected, string $group, string $contact, string $context = ''): ?array {
    $records = (array) GroupContact::get(FALSE)
      ->addSelect('id', 'status')
      ->addWhere('group_id', '=', $this->groupIds[$group])
      ->addWhere('contact_id', '=', $this->contactIds[$contact])
      ->execute()
      ->getArrayCopy();

    $where = $this->describe("group '{$group}', contact '{$contact}'", $context);
    $this->assertLessThan(2, count($records), "Duplicate GroupContact records for {$where}");
    $this->assertSame($expected, $records[0]['status'] ?? NULL, "Unexpected GroupContact status for {$where}");

    return $records[0] ?? NULL;
  }

  /**
   * Assert the SubscriptionContact mirroring a group is in the given state.
   *
   * @param string|null $expected
   *   subscription_status option name, or NULL if there should be no record.
   * @param string $subscription
   * @param string $contact
   * @param string $context
   *   What was done to bring about the expected state.
   *
   * @return array|null
   *   The SubscriptionContact record, if any.
   */
  protected function assertSubscriptionStatus(?string $expected, string $subscription, string $contact, string $context = ''): ?array {
    $records = (array) SubscriptionContact::get(FALSE)
      ->addSelect('id', 'subscription_status:name')
      ->addWhere('subscription_id', '=', $this->subscriptionIds[$subscription])
      ->addWhere('contact_id', '=', $this->contactIds[$contact])
      ->execute()
      ->getArrayCopy();

    $where = $this->describe("subscription '{$subscription}', contact '{$contact}'", $context);
    $this->assertLessThan(2, count($records), "Duplicate SubscriptionContact records for {$where}");
    $this->assertSame($expected, $records[0]['subscription_status:name'] ?? NULL, "Unexpected SubscriptionContact status for {$where}");

    return $records[0] ?? NULL;
  }

  protected function describe(string $where, string $context): string {
    return $context === '' ? $where : "{$where} after {$context}";
  }

}
