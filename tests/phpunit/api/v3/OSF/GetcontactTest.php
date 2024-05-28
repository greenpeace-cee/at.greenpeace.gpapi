<?php

use Civi\Test;
use Civi\Test\Api3TestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * OSF.Getcontact API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_OSF_GetcontactTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use Api3TestTrait;

  /**
   * Set up for headless tests.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
   */
  public function setUpHeadless() {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp(): void {
    parent::setUp();
    $session = CRM_Core_Session::singleton();
    $session->set('userID', 1);
    CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access OSF API'];
  }

  /**
   * The tearDown() method is executed after the test was executed (optional)
   * This can be used for cleanup.
   */
  public function tearDown(): void {
    parent::tearDown();
  }

  public function testContactGet() {
    // create test contact
    $contact = reset($this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Jane',
      'last_name' => 'Doe',
      'prefix_id' => 'Mrs.',
      'gender_id' => 'Female',
      'birth_date' => '2000-05-05',
      'email' => 'doe@example.com',
      'api.Address.create' => ['street_address' => 'Main Street', 'country_id' => 'US'],
      'api.Phone.create' => ['phone' => '1 234 55'],
    ])['values']);

    // fetch contact by hash
    $result = $this->callAPISuccess('OSF', 'getcontact', [
      'hash' => $contact['hash'],
      'check_permissions' => 1,
    ]);

    $this->assertEquals('Jane', $result['values'][0]['first_name']);
    $this->assertEquals('doe@example.com', $result['values'][0]['email']);
    $this->assertEquals('Female', $result['values'][0]['gender']);
    $this->assertEquals('2000-05-05', $result['values'][0]['birth_date']);
    $this->assertEquals('1 234 55', $result['values'][0]['phone']);
    $this->assertEquals('Main Street', $result['values'][0]['street_address']);
    $this->assertEquals('US', $result['values'][0]['country']);

    // delete contact
    $this->callAPISuccess('Contact', 'create', [
      'id' => $contact['id'],
      'is_deleted' => TRUE,
    ]);

    // ensure the call fails
    $result = $this->callAPIFailure('OSF', 'getcontact', [
      'hash' => $contact['hash'],
      'check_permissions' => 1,
    ], 'Unknown contact hash');
    $this->assertEquals('unknown_hash', $result['error_code']);
  }

}
