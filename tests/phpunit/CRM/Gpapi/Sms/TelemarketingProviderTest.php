<?php

use Civi\Test;
use Civi\Test\Api3TestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test the telemarketing SMS provider
 *
 * @group headless
 */
class CRM_Gpapi_Sms_TelemarketingProviderTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use Api3TestTrait;

  const SENDER_NUMBER = '+436641234567';
  const RECEIVER_NUMBER = '+43800911971';
  const UNKNOWN_RECEIVER_NUMBER = '+4319999999';

  const SAMPLE_REQUEST = [
    'sender_number'   => self::SENDER_NUMBER,
    'receiver_number' => self::RECEIVER_NUMBER,
    'message'         => 'message content',
  ];

  const DIRECTION_OUTBOUND = 'outbound';

  /**
   * Request overrides swapping the sample request's numbers, so the same
   * contacts take the same activity roles in both directions and assertions
   * hold for either.
   */
  const OUTBOUND_REQUEST = [
    'direction'       => self::DIRECTION_OUTBOUND,
    'sender_number'   => self::RECEIVER_NUMBER,
    'receiver_number' => self::SENDER_NUMBER,
  ];

  /**
   * @var CRM_Gpapi_Sms_TelemarketingProvider
   */
  private $provider;

  /**
   * @var int
   */
  private $providerId;

  /**
   * @var int
   */
  private $receiverContactId;

  /**
   * @var int
   */
  private $campaignId;

  public function setUpHeadless() {
    return Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    $this->providerId = $this->createSmsProvider('Telemarketing');
    $this->provider = new CRM_Gpapi_Sms_TelemarketingProvider([
      'provider_id' => $this->providerId,
    ]);
    $_REQUEST['key'] = 'hunter2';
    http_response_code(200);

    $this->receiverContactId = $this->callAPISuccess('Contact', 'create', [
      'contact_type'      => 'Organization',
      'organization_name' => 'Telemarketing Number',
      'api.Phone.create'  => ['phone' => self::RECEIVER_NUMBER],
    ])['id'];

    $this->campaignId = $this->callAPISuccess('Campaign', 'create', [
      'title' => 'Telemarketing Campaign',
    ])['id'];
  }

  public function tearDown(): void {
    unset($_REQUEST['key']);
    parent::tearDown();
  }

  public function testProviderIsResolvedFromProviderName() {
    $providerName = $this->callAPISuccess('OptionValue', 'getvalue', [
      'option_group_id' => 'sms_provider_name',
      'name'            => 'gpapi_telemarketing',
      'return'          => 'value',
    ]);
    $this->assertEquals('gpapi.telemarketing', $providerName);
    $this->assertInstanceOf(
      CRM_Gpapi_Sms_TelemarketingProvider::class,
      CRM_SMS_Provider::singleton([
        'provider_id' => $this->createSmsProvider('Telemarketing (resolved)', $providerName),
      ], TRUE)
    );
  }

  public function testSendIsNotSupported() {
    $this->expectException(CRM_Core_Exception::class);
    $this->expectExceptionMessage('cannot send messages');
    $this->provider->send(self::SENDER_NUMBER, NULL, 'nope');
  }

  public function errorResponseDataProvider() {
    return [
      'missing key' => [[], 401, 'Missing authentication parameter "key"', NULL],
      'wrong key' => [[], 401, 'Wrong authentication key', 'wrong'],
      'no key configured' => [[], 500, 'No authentication key configured', 'hunter2', TRUE],
      'empty request' => [
        NULL,
        400,
        'Required fields are missing: sender_number, receiver_number, message',
      ],
      'missing sender' => [
        ['sender_number' => NULL],
        400,
        'Required fields are missing: sender_number',
      ],
      'blank message_id' => [['message_id' => '  '], 400, 'message_id must not be empty'],
      'overlong message_id' => [
        ['message_id' => str_repeat('x', 256)],
        400,
        'message_id is too long',
      ],
      'invalid direction' => [
        ['direction' => 'sideways'],
        400,
        'direction must be one of: inbound, outbound',
      ],
      'non-ISO sent_at' => [
        ['sent_at' => '13/10/2026'],
        400,
        'sent_at must be an ISO-8601 date and time, e.g. "2026-10-13T11:22:33+02:00"',
      ],
      'impossible sent_at' => [['sent_at' => '2026-02-31'], 400, 'sent_at is not a valid date'],
      'non-numeric campaign_id' => [
        ['campaign_id' => 'spring'],
        400,
        'campaign_id must be the ID of a campaign',
      ],
      'unknown campaign_id' => [
        ['campaign_id' => 999999999],
        400,
        'campaign_id 999999999 does not exist',
      ],
    ];
  }

  /**
   * @dataProvider errorResponseDataProvider
   */
  public function testInboundErrorResponse($overrides, $httpCode, $error, $key = 'hunter2', $unconfiguredProvider = FALSE) {
    if (is_null($key)) {
      unset($_REQUEST['key']);
    }
    else {
      $_REQUEST['key'] = $key;
    }
    if ($unconfiguredProvider) {
      $this->provider = new CRM_Gpapi_Sms_TelemarketingProvider([]);
    }

    $this->assertEquals(['error' => $error], $this->deliver($overrides));
    $this->assertEquals($httpCode, http_response_code());
  }

  public function directionDataProvider() {
    return [
      'inbound' => [[]],
      'outbound' => [self::OUTBOUND_REQUEST],
    ];
  }

  /**
   * The activity source is the receiver of an inbound and the sender of an
   * outbound message, its target the other party
   *
   * @dataProvider directionDataProvider
   */
  public function testMessageForKnownContact(array $overrides) {
    $targetContactId = $this->callAPISuccess('Contact', 'create', [
      'contact_type'     => 'Individual',
      'first_name'       => 'Jane',
      'last_name'        => 'Doe',
      'api.Phone.create' => ['phone' => self::SENDER_NUMBER],
    ])['id'];

    // not the default, so asserting 200 proves the success path sets it
    http_response_code(500);
    $response = $this->deliver($overrides);
    $this->assertEquals(200, http_response_code());
    $this->assertSame(['activity_id', 'duplicate'], array_keys($response));
    $this->assertFalse($response['duplicate']);

    $activity = $this->getActivity($overrides);
    $this->assertEquals($response['activity_id'], $activity['id']);
    $this->assertEquals($targetContactId, $activity['target_contact_id'][0]);
    $this->assertEquals($this->receiverContactId, $activity['source_contact_id']);
    $this->assertEquals(
      '436641234567',
      preg_replace('/[^0-9]/', '', $activity['phone_number'])
    );
  }

  public function activityValueDataProvider() {
    return [
      'message text' => [[], 'details', 'message content'],
      'blank message' => [['message' => '   '], 'details', '   '],
      'message_id' => [['message_id' => 'abc123'], 'result', '{provider_id}:abc123'],
      'no message_id' => [[], 'result', ''],
      'sent_at' => [['sent_at' => '2026-10-13T11:22:33'], 'activity_date_time', '2026-10-13 11:22:33'],
      'outbound message text' => [self::OUTBOUND_REQUEST, 'details', 'message content'],
      'outbound message_id' => [
        ['message_id' => 'abc123'] + self::OUTBOUND_REQUEST,
        'result',
        '{provider_id}:abc123',
      ],
      'outbound sent_at' => [
        ['sent_at' => '2026-10-13T11:22:33'] + self::OUTBOUND_REQUEST,
        'activity_date_time',
        '2026-10-13 11:22:33',
      ],
      'campaign_id' => [['campaign_id' => '{campaign_id}'], 'campaign_id', '{campaign_id}'],
      'outbound campaign_id' => [
        ['campaign_id' => '{campaign_id}'] + self::OUTBOUND_REQUEST,
        'campaign_id',
        '{campaign_id}',
      ],
    ];
  }

  /**
   * @dataProvider activityValueDataProvider
   */
  public function testActivityValue(array $overrides, $field, $expected) {
    // only known once the fixtures are set up
    $placeholders = ['{provider_id}', '{campaign_id}'];
    $values = [$this->providerId, $this->campaignId];
    if (isset($overrides['campaign_id'])) {
      $overrides['campaign_id'] = (int) $this->campaignId;
    }
    $this->deliver($overrides);

    // APIv3 omits NULL fields
    $this->assertEquals(
      str_replace($placeholders, $values, $expected),
      $this->getActivity($overrides)[$field] ?? ''
    );
  }

  public function testSentAtOffsetIsConvertedToLocalTime() {
    $this->deliver(['sent_at' => '2026-10-13T11:22:33+00:00']);

    $expected = (new DateTime('2026-10-13T11:22:33+00:00'))
      ->setTimezone(new DateTimeZone(date_default_timezone_get()))
      ->format('Y-m-d H:i:s');
    $this->assertEquals($expected, $this->getActivity()['activity_date_time']);
  }

  public function redeliveryDataProvider() {
    return [
      'same message_id' => ['abc123', 'abc123', FALSE, TRUE, 1],
      'different message_id' => ['abc123', 'def456', FALSE, FALSE, 2],
      'no message_id' => [NULL, NULL, FALSE, FALSE, 2],
      'same message_id, other provider' => ['abc123', 'abc123', TRUE, FALSE, 2],
      'same message_id, other direction' => [
        'abc123', 'abc123', FALSE, TRUE, 1, self::OUTBOUND_REQUEST,
      ],
    ];
  }

  /**
   * @dataProvider redeliveryDataProvider
   */
  public function testRedelivery($firstMessageId, $secondMessageId, $otherProvider, $isDuplicate, $activityCount, array $secondOverrides = []) {
    $first = $this->deliver(['message_id' => $firstMessageId]);
    $this->assertFalse($first['duplicate']);

    if ($otherProvider) {
      $this->provider = new CRM_Gpapi_Sms_TelemarketingProvider([
        'provider_id' => $this->createSmsProvider('Telemarketing (other)'),
      ]);
    }
    $second = $this->deliver(['message_id' => $secondMessageId] + $secondOverrides);

    $this->assertEquals(200, http_response_code());
    $this->assertEquals($isDuplicate, $second['duplicate']);
    $this->assertEquals($activityCount, $this->countActivities());
    if ($isDuplicate) {
      $this->assertEquals($first['activity_id'], $second['activity_id']);
    }
    else {
      $this->assertNotEquals($first['activity_id'], $second['activity_id']);
    }
  }

  public function unknownNumberDataProvider() {
    return [
      'inbound receiver number' => [
        ['receiver_number' => self::UNKNOWN_RECEIVER_NUMBER],
        'source_contact_id',
      ],
      'outbound receiver number' => [
        ['receiver_number' => self::UNKNOWN_RECEIVER_NUMBER] + self::OUTBOUND_REQUEST,
        'target_contact_id',
      ],
    ];
  }

  /**
   * An unknown number gets a contact, created once and reused
   *
   * @dataProvider unknownNumberDataProvider
   */
  public function testCreatesContactForUnknownNumber(array $overrides, $field) {
    $this->deliver($overrides);
    $this->deliver($overrides);

    $activities = $this->callAPISuccess('Activity', 'get', [
      'activity_type_id' => $this->getActivityType($overrides),
      'return'           => [$field],
      'sequential'       => 1,
    ])['values'];
    $this->assertCount(2, $activities);

    $contactId = $this->getContactId($activities[0][$field]);
    $this->assertNotEmpty($contactId);
    $this->assertEquals($contactId, $this->getContactId($activities[1][$field]));

    $phone = $this->callAPISuccess('Phone', 'getsingle', ['contact_id' => $contactId]);
    $this->assertEquals(
      '4319999999',
      preg_replace('/[^0-9]/', '', $phone['phone'])
    );
    $contact = $this->callAPISuccess('Contact', 'getsingle', ['id' => $contactId]);
    $this->assertEquals($phone['phone'], $contact['display_name']);
  }

  /**
   * Deliver a message and return the decoded response body
   *
   * @param array|null $overrides Request overrides, NULL for an empty request.
   *   NULL values omit the field from the request.
   */
  private function deliver($overrides = []) {
    $request = is_null($overrides) ? [] : $overrides + self::SAMPLE_REQUEST;
    $request = array_filter($request, function ($value) {
      return !is_null($value);
    });

    ob_start();
    try {
      $this->provider->processInboundRequest($request);
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      // sendResponse() calls CRM_Utils_System::civiExit()
    }

    return json_decode(ob_get_clean(), TRUE);
  }

  /**
   * The activity recorded for a request, by the type its direction records
   */
  private function getActivity(array $overrides = []) {
    return $this->callAPISuccess('Activity', 'getsingle', [
      'activity_type_id' => $this->getActivityType($overrides),
      'return'           => [
        'activity_date_time',
        'campaign_id',
        'details',
        'phone_number',
        'result',
        'source_contact_id',
        'target_contact_id',
      ],
    ]);
  }

  private function getActivityType(array $overrides) {
    return ($overrides['direction'] ?? NULL) === self::DIRECTION_OUTBOUND ? 'SMS' : 'Inbound SMS';
  }

  /**
   * APIv3 returns target contacts as an array, source contacts as a value
   */
  private function getContactId($contactIds) {
    return is_array($contactIds) ? $contactIds[0] : $contactIds;
  }

  private function countActivities() {
    return $this->callAPISuccess('Activity', 'getcount', [
      'activity_type_id' => ['IN' => ['Inbound SMS', 'SMS']],
    ]);
  }

  private function createSmsProvider($title, $name = 'gpapi.telemarketing') {
    return $this->callAPISuccess('SmsProvider', 'create', [
      'name'       => $name,
      'title'      => $title,
      // TODO: older versions of Civi expect this as int, replace with "http"
      // once we don't care about those
      'api_type'   => CRM_Core_PseudoConstant::getKey('CRM_SMS_DAO_Provider', 'api_type', 'http'),
      'api_params' => 'key=hunter2',
    ])['id'];
  }

}
