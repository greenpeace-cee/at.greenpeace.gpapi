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
use CRM_Core_DAO;

/**
 *
 * @group headless
 */
class SubscriptionManagerTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  const MAPPED_GROUP = 'community_nl';

  const MAPPED_SUBSCRIPTION = 'community_newsletter';

  /**
   * Name of the subscription that is mapped to Contact.is_opt_out.
   */
  const OPT_OUT_SUBSCRIPTION = 'opt_out_subscription';

  /**
   * @var int[]
   *   Group IDs keyed by 'mapped' and 'unmapped'.
   */
  protected array $groupIds = [];

  /**
   * @var int[]
   *   Subscription IDs keyed by 'mapped', 'unmapped' and 'opt_out'.
   */
  protected array $subscriptionIds = [];

  /**
   * @var int[]
   */
  protected array $contactIds = [];

  /**
   * Called from hook_civicrm_post, if set.
   *
   * @var callable|null
   */
  protected $onPost = NULL;

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

    $this->groupIds = [
      'mapped' => $this->createGroup(self::MAPPED_GROUP),
      'unmapped' => $this->createGroup('admin'),
    ];
    $this->subscriptionIds = [
      'mapped' => $this->createSubscription(self::MAPPED_SUBSCRIPTION),
      'unmapped' => $this->createSubscription('unmapped_newsletter'),
      'opt_out' => $this->createSubscription(self::OPT_OUT_SUBSCRIPTION),
    ];
    $this->contactIds = [
      'first' => $this->createContact('First'),
      'second' => $this->createContact('Second'),
    ];

    Civi::settings()->set('gpapi_group_to_subscription_map', [self::MAPPED_GROUP => self::MAPPED_SUBSCRIPTION]);
    Civi::settings()->set('gpapi_opt_out_subscription', self::OPT_OUT_SUBSCRIPTION);
  }

  public function tearDown():void {
    Civi::settings()->revert('gpapi_group_to_subscription_map');
    Civi::settings()->revert('gpapi_opt_out_subscription');
    parent::tearDown();
  }

  public function testGroupContactChangesAreMirrored(): void {
    // subscription_status has no equivalent of 'Pending'
    $groupContactId = $this->createGroupContact('mapped', 'first', 'Pending');
    $subscriptionContact = $this->assertSubscriptionStatus('opt_out', 'mapped', 'first', "GroupContact created as 'Pending'");

    // re-asserting opt_out must not discard the pending double opt-in
    SubscriptionContact::update(FALSE)
      ->addWhere('id', '=', $subscriptionContact['id'])
      ->addValue('subscription_status:name', 'opt_out')
      ->execute();
    $this->assertGroupStatus('Pending', 'mapped', 'first', 'opt_out mirrored back');

    // note that API4 only passes the changed values to hook_civicrm_post,
    // so the hook has to resolve group_id/contact_id itself
    GroupContact::update(FALSE)
      ->addWhere('id', '=', $groupContactId)
      ->addValue('status', 'Added')
      ->execute();
    $this->assertSubscriptionStatus('opt_in', 'mapped', 'first', "GroupContact changed to 'Added'");

    GroupContact::delete(FALSE)->addWhere('id', '=', $groupContactId)->execute();
    $this->assertSubscriptionStatus('opt_out', 'mapped', 'first', 'GroupContact deleted');
  }

  public function testSubscriptionContactChangesAreMirrored(): void {
    $subscriptionContactId = $this->createSubscriptionContact('mapped', 'first', 'opt_in');
    $this->assertGroupStatus('Added', 'mapped', 'first', "SubscriptionContact created as 'opt_in'");

    SubscriptionContact::update(FALSE)
      ->addWhere('id', '=', $subscriptionContactId)
      ->addValue('subscription_status:name', 'opt_out')
      ->execute();
    $this->assertGroupStatus('Removed', 'mapped', 'first', "SubscriptionContact changed to 'opt_out'");

    SubscriptionContact::delete(FALSE)->addWhere('id', '=', $subscriptionContactId)->execute();
    $this->assertGroupStatus(NULL, 'mapped', 'first', 'SubscriptionContact deleted');
  }

  /**
   * The legacy BAO (also used by API3) passes the group ID and a list of
   * contact IDs to hook_civicrm_post, rather than a GroupContact ID and DAO.
   */
  public function testLegacyBulkGroupContactPathsAreMirrored(): void {
    $contactIds = array_values($this->contactIds);
    $firstContactIds = [$this->contactIds['first']];

    CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIds, $this->groupIds['mapped']);
    $this->assertSubscriptionStatus('opt_in', 'mapped', 'first', 'bulk add');
    $this->assertSubscriptionStatus('opt_in', 'mapped', 'second', 'bulk add');

    CRM_Contact_BAO_GroupContact::removeContactsFromGroup($firstContactIds, $this->groupIds['mapped']);
    $this->assertSubscriptionStatus('opt_out', 'mapped', 'first', 'bulk remove');
  }

  public function testUnmappedGroupsAndSubscriptionsAreIgnored(): void {
    $this->createGroupContact('unmapped', 'first', 'Added');
    $this->assertCount(0, SubscriptionContact::get(FALSE)
      ->addWhere('contact_id', '=', $this->contactIds['first'])
      ->execute(), 'Unmapped group should not produce a SubscriptionContact');

    $this->createSubscriptionContact('unmapped', 'second', 'opt_out');
    $this->assertCount(0, GroupContact::get(FALSE)
      ->addWhere('contact_id', '=', $this->contactIds['second'])
      ->execute(), 'Unmapped subscription should not produce a GroupContact');
    $this->assertIsOptOut(FALSE, 'second', 'opt_out of a subscription other than the opt-out subscription');
  }

  public function testOptOutSubscriptionIsAppliedToIsOptOut(): void {
    $subscriptionContactId = $this->createSubscriptionContact('opt_out', 'first', 'opt_out');
    $this->assertIsOptOut(TRUE, 'first', 'SubscriptionContact created as opt_out');

    SubscriptionContact::update(FALSE)
      ->addWhere('id', '=', $subscriptionContactId)
      ->addValue('subscription_status:name', 'opt_in')
      ->execute();
    $this->assertIsOptOut(FALSE, 'first', 'SubscriptionContact changed to opt_in');

    // deleting the SubscriptionContact leaves is_opt_out as it is
    SubscriptionContact::delete(FALSE)->addWhere('id', '=', $subscriptionContactId)->execute();
    $this->assertIsOptOut(FALSE, 'first', 'SubscriptionContact deleted');
  }

  public function testIsOptOutIsAppliedToOptOutSubscription(): void {
    $this->setIsOptOut('first', TRUE);
    $this->assertSubscriptionStatus('opt_out', 'opt_out', 'first', 'is_opt_out set');

    $this->setIsOptOut('first', FALSE);
    $this->assertSubscriptionStatus('opt_in', 'opt_out', 'first', 'is_opt_out cleared');

    // only changes are applied: raw SQL bypasses the hooks
    CRM_Core_DAO::executeQuery('UPDATE civicrm_contact SET is_opt_out = 1 WHERE id = %1', [1 => [$this->contactIds['first'], 'Integer']]);
    $this->setIsOptOut('first', TRUE);
    $this->assertSubscriptionStatus('opt_in', 'opt_out', 'first', 'is_opt_out saved unchanged');

    CRM_Core_DAO::executeQuery('UPDATE civicrm_contact SET is_opt_out = 1 WHERE id = %1', [1 => [$this->contactIds['second'], 'Integer']]);
    $this->setIsOptOut('second', FALSE);
    $this->assertSubscriptionStatus(NULL, 'opt_out', 'second', 'is_opt_out cleared without a SubscriptionContact');

    $this->contactIds['third'] = $this->createContact('Third', TRUE);
    $this->assertSubscriptionStatus('opt_out', 'opt_out', 'third', 'contact created with is_opt_out');

    // trashing must not look like clearing is_opt_out
    Contact::delete(FALSE)->addWhere('id', '=', $this->contactIds['third'])->execute();
    $this->assertSubscriptionStatus('opt_out', 'opt_out', 'third', 'contact trashed');

    SubscriptionContact::update(FALSE)
      ->addWhere('contact_id', '=', $this->contactIds['third'])
      ->addValue('subscription_status:name', 'opt_in')
      ->execute();
    $this->assertIsOptOut(FALSE, 'third', 'SubscriptionContact of trashed contact changed to opt_in');
  }

  /**
   * Merges move subscriptions with SQL, so the merged contact's opt-out
   * subscription is brought in line with its is_opt_out afterwards.
   */
  public function testIsOptOutIsAppliedToOptOutSubscriptionAfterMerge(): void {
    // the aggressive merge keeps the primary's is_opt_out, while the merged
    // subscription is opted in because the duplicate's is
    $this->contactIds['third'] = $this->createContact('Third', TRUE);
    $this->createSubscriptionContact('opt_out', 'first', 'opt_in');
    $this->mergeContacts('third', 'first');
    $this->assertIsOptOut(TRUE, 'third', 'merged opted-out contact');
    $this->assertSubscriptionStatus('opt_out', 'opt_out', 'third', 'merged opted-out contact');

    // the aggressive merge keeps the primary's cleared is_opt_out, while the
    // opted-out subscription is moved over from the duplicate
    $this->contactIds['fourth'] = $this->createContact('Fourth', TRUE);
    $this->mergeContacts('second', 'fourth');
    $this->assertIsOptOut(FALSE, 'second', 'merged contact that is not opted out');
    $this->assertSubscriptionStatus('opt_in', 'opt_out', 'second', 'merged contact that is not opted out');
  }

  public function testWritesByOtherListenersDuringMirroringAreMirrored(): void {
    $this->onPost = function (string $objectName, $objectRef) {
      if ($objectName === 'SubscriptionContact' && (int) $objectRef->contact_id === $this->contactIds['first']) {
        $this->onPost = NULL;
        $this->createGroupContact('mapped', 'second', 'Added');
      }
    };

    $this->createGroupContact('mapped', 'first', 'Added');
    $this->assertSubscriptionStatus('opt_in', 'mapped', 'second', 'GroupContact created while mirroring another one');
  }

  public function hook_civicrm_post($op, $objectName, $objectId, &$objectRef): void {
    if ($this->onPost) {
      ($this->onPost)($objectName, $objectRef);
    }
  }

  /*
   * Helpers
   */

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

  protected function createContact(string $lastName, bool $isOptOut = FALSE): int {
    return (int) Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Subscription')
      ->addValue('last_name', $lastName)
      ->addValue('is_opt_out', $isOptOut)
      ->execute()
      ->first()['id'];
  }

  protected function setIsOptOut(string $contact, bool $isOptOut): void {
    Contact::update(FALSE)
      ->addWhere('id', '=', $this->contactIds[$contact])
      ->addValue('is_opt_out', $isOptOut)
      ->execute();
  }

  protected function mergeContacts(string $primary, string $duplicate): void {
    $result = Contact::mergeDuplicates(FALSE)
      ->setContactId($this->contactIds[$primary])
      ->setDuplicateId($this->contactIds[$duplicate])
      ->setMode('aggressive')
      ->execute()
      ->first();
    $this->assertCount(1, $result['merged'], "Merge of {$duplicate} into {$primary}");
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
   */
  protected function assertGroupStatus(?string $expected, string $group, string $contact, string $context = ''): void {
    $records = (array) GroupContact::get(FALSE)
      ->addSelect('id', 'status')
      ->addWhere('group_id', '=', $this->groupIds[$group])
      ->addWhere('contact_id', '=', $this->contactIds[$contact])
      ->execute()
      ->getArrayCopy();

    $where = $this->describe("group '{$group}', contact '{$contact}'", $context);
    $this->assertLessThan(2, count($records), "Duplicate GroupContact records for {$where}");
    $this->assertSame($expected, $records[0]['status'] ?? NULL, "Unexpected GroupContact status for {$where}");
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

  protected function assertIsOptOut(bool $expected, string $contact, string $context = ''): void {
    $isOptOut = Contact::get(FALSE)
      ->addSelect('is_opt_out')
      ->addWhere('id', '=', $this->contactIds[$contact])
      ->execute()
      ->single()['is_opt_out'];
    $where = $this->describe("contact '{$contact}'", $context);
    $this->assertSame($expected, (bool) $isOptOut, "Unexpected is_opt_out for {$where}");
  }

  protected function describe(string $where, string $context): string {
    return $context === '' ? $where : "{$where} after {$context}";
  }

}
