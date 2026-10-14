<?php

use Civi\Api4\Activity;
use Civi\Api4\Campaign;
use Civi\Api4\Contact;
use Civi\Api4\Phone;

/**
 * SMS provider for messages exchanged by TM agencies
 *
 * Records messages a TM agency forwards to us: replies from supporters as
 * Inbound SMS activities, and messages the agency sent on our behalf as
 * Outbound SMS activities. Nothing is ever transmitted from here, the agency
 * does the sending.
 *
 * The body carries the sender number, the receiver number and the message
 * text, plus an optional direction (defaults to inbound), send time and
 * campaign:
 *
 *   {
 *     "direction": "outbound",
 *     "sender_number": "+436991234567",
 *     "receiver_number": "+436641234567",
 *     "message": "STOP",
 *     "sent_at": "2026-10-13T11:22:33+02:00",
 *     "campaign_id": 42
 *   }
 *
 * Both numbers are resolved to contacts, and an unknown number gets one
 * created. The activity's source is the receiver of an inbound and the sender
 * of an outbound message, its target the other party.
 *
 * An optional "message_id" makes delivery idempotent: it is stored in the
 * activity's "result" field, prefixed with the provider ID, and a message we
 * already hold is reported back instead of being recorded twice. It is shared
 * between directions, so the same ID is only ever recorded once.
 *
 * The shared secret is configured on the SMS provider record by adding
 * "key={secret}" to its API Parameters.
 */
class CRM_Gpapi_Sms_TelemarketingProvider extends CRM_SMS_Provider {

  /**
   * Fields every request has to provide.
   */
  const REQUIRED_FIELDS = [
    'sender_number',
    'receiver_number',
    'message',
  ];

  /**
   * Fields that additionally need to carry actual content. An empty or
   * whitespace-only message is a valid SMS, so "message" is not one of them.
   */
  const NON_BLANK_FIELDS = [
    'sender_number',
    'receiver_number',
  ];

  const DIRECTION_INBOUND = 'inbound';

  const DIRECTION_OUTBOUND = 'outbound';

  /**
   * Activity type recorded per direction, keyed by the accepted directions.
   */
  const ACTIVITY_TYPES = [
    self::DIRECTION_INBOUND  => 'Inbound SMS',
    self::DIRECTION_OUTBOUND => 'SMS',
  ];

  /**
   * Accepted "sent_at" syntax: an ISO-8601 date, optionally with time and UTC
   * offset. Deliberately narrower than DateTime, which would read ambiguous
   * input like "10/13/2026" as a date.
   */
  const SENT_AT_PATTERN = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/';

  /**
   * Length of civicrm_activity.result, which holds the prefixed message ID.
   */
  const MAX_MESSAGE_ID_LENGTH = 255;

  protected $providerInfo = [];

  static private $_singleton;

  public function __construct(array $provider) {
    if (!empty($provider['provider_id'])) {
      $this->providerInfo = CRM_SMS_BAO_Provider::getProviderInfo($provider['provider_id']);
    }
  }

  public static function &singleton($providerParams = [], $force = FALSE) {
    if (!isset(self::$_singleton) || $force) {
      self::$_singleton = new self($providerParams);
    }
    return self::$_singleton;
  }

  /**
   * This provider only records messages, the agency sends them.
   *
   * @throws \CRM_Core_Exception
   */
  public function send($recipients, $header, $message, $dncID = NULL) {
    throw new CRM_Core_Exception(
      'The telemarketing SMS provider cannot send messages, it only records them'
    );
  }

  /**
   * CRM_SMS_Page_Callback routes requests with a "status" parameter here, so
   * respond instead of fataling on the undefined method.
   */
  public function callback() {
    CRM_Utils_System::setHttpHeader('Content-Type', 'application/json');
    $this->sendError(400, 'Delivery reports are not supported');
  }

  /**
   * Entry point for all requests, inbound and outbound ones alike.
   * CRM_SMS_Page_Callback only offers this method and callback().
   */
  public function inbound() {
    CRM_Utils_System::setHttpHeader('Content-Type', 'application/json');
    try {
      $request = $this->getRequest();
    }
    catch (Exception $e) {
      Civi::log()->error("[telemarketing] {$e->getMessage()}");
      $this->sendError(400, $e->getMessage());
      return;
    }
    $this->processInboundRequest($request);
  }

  /**
   * Authenticate and process an inbound message.
   *
   * @param array $request
   */
  public function processInboundRequest(array $request) {
    $key = $this->providerInfo['api_params']['key'] ?? NULL;
    if (empty($key)) {
      Civi::log()->error('[telemarketing] No authentication key configured on the SMS provider');
      $this->sendError(500, 'No authentication key configured');
      return;
    }
    $providedKey = $this->retrieve('key', 'String', FALSE);
    if (empty($providedKey)) {
      $this->sendError(401, 'Missing authentication parameter "key"');
      return;
    }
    if (!hash_equals($key, $providedKey)) {
      $this->sendError(401, 'Wrong authentication key');
      return;
    }

    try {
      $this->validateRequest($request);
      [$activityId, $isDuplicate] = $this->recordMessage($request);
    }
    catch (Exception $e) {
      $errorCode = $e instanceof UnexpectedValueException ? 400 : 500;
      Civi::log()->error("[telemarketing] {$e->getMessage()}", [
        'request' => $request,
      ]);
      $this->sendError($errorCode, $e->getMessage());
      return;
    }

    $this->sendResponse(200, [
      'activity_id' => $activityId,
      'duplicate'   => $isDuplicate,
    ]);
  }

  /**
   * Record the message, unless we already hold it
   *
   * @param array $request
   *
   * @return array [int $activityId, bool $isDuplicate]
   * @throws \CRM_Core_Exception
   */
  private function recordMessage(array $request) {
    $messageId = $this->getMessageId($request);
    if (is_null($messageId)) {
      // without a message ID there is nothing to deduplicate on
      return [$this->recordActivity($request, NULL), FALSE];
    }

    // guard check-and-create against a concurrent redelivery
    $lock = new CRM_Core_Lock('gpapi.telemarketing.message.' . $messageId, 60);
    $lock->acquire();
    if (!$lock->isAcquired()) {
      throw new RuntimeException("Could not acquire lock for message {$messageId}");
    }

    try {
      $activityId = $this->getActivityIdByMessageId($messageId);
      if (!empty($activityId)) {
        Civi::log()->info(
          "[telemarketing] Message {$messageId} is already recorded as activity {$activityId}"
        );
        return [(int) $activityId, TRUE];
      }

      return [$this->recordActivity($request, $messageId), FALSE];
    }
    finally {
      $lock->release();
    }
  }

  /**
   * Record the message as an activity between the sender and the receiver
   *
   * Not called createActivity(), which CRM_SMS_Provider declares publicly for
   * outbound messages sent through CiviCRM.
   *
   * @param array $request
   * @param string|null $messageId
   *
   * @return int ID of the created activity
   * @throws \CRM_Core_Exception
   */
  private function recordActivity(array $request, $messageId) {
    $senderNumber = $this->normalizePhone($request['sender_number']);
    $receiverNumber = $this->normalizePhone($request['receiver_number']);
    $message = (string) $request['message'];
    $sentAt = $this->getSentAt($request);
    $campaignId = $this->getCampaignId($request);

    if ($this->getDirection($request) === self::DIRECTION_OUTBOUND) {
      return $this->createOutboundActivity(
        $senderNumber, $receiverNumber, $message, $messageId, $sentAt, $campaignId
      );
    }

    return $this->createInboundActivity(
      $senderNumber, $receiverNumber, $message, $messageId, $sentAt, $campaignId
    );
  }

  /**
   * Record an inbound message through CRM_SMS_Provider, which fires
   * hook_civicrm_inboundSMS and resolves the sender's contact
   *
   * @return int ID of the created activity
   * @throws \CRM_Core_Exception
   */
  private function createInboundActivity($senderNumber, $receiverNumber, $message, $messageId, $sentAt, $campaignId) {
    // processInbound() looks the receiver's contact up but, unlike for the
    // sender, never creates one, and a NULL source contact makes
    // CRM_Activity_BAO_Activity::create() reject the message
    $this->getOrCreateContactIdByPhone($receiverNumber);
    $activity = parent::processInbound($senderNumber, $message, $receiverNumber, $messageId);

    // processInbound() takes neither: it always records the current time and
    // knows nothing about campaigns
    if (!is_null($sentAt) || !is_null($campaignId)) {
      $update = Activity::update(FALSE)->addWhere('id', '=', $activity->id);
      if (!is_null($sentAt)) {
        $update->addValue('activity_date_time', $sentAt);
      }
      if (!is_null($campaignId)) {
        $update->addValue('campaign_id', $campaignId);
      }
      $update->execute();
    }

    return (int) $activity->id;
  }

  /**
   * Record an outbound message, the way CRM_Activity_BAO_Activity::sendSMS()
   * records messages sent from CiviCRM
   *
   * CRM_SMS_Provider::createActivity() is no help here: it records an "SMS
   * delivery" rather than an "Outbound SMS" activity and takes its source
   * contact from the session, which a webhook request does not have.
   *
   * @return int ID of the created activity
   * @throws \CRM_Core_Exception
   */
  private function createOutboundActivity($senderNumber, $receiverNumber, $message, $messageId, $sentAt, $campaignId) {
    $receiverContactId = $this->getOrCreateContactIdByPhone($receiverNumber);
    $activityType = self::ACTIVITY_TYPES[self::DIRECTION_OUTBOUND];
    $activityParams = [
      'source_contact_id'  => $this->getOrCreateContactIdByPhone($senderNumber),
      'target_contact_id'  => $receiverContactId,
      'activity_type_id'   => CRM_Core_PseudoConstant::getKey(
        'CRM_Activity_BAO_Activity', 'activity_type_id', $activityType
      ),
      'status_id'          => CRM_Core_PseudoConstant::getKey(
        'CRM_Activity_BAO_Activity', 'activity_status_id', 'Completed'
      ),
      'activity_date_time' => $sentAt ?? date('YmdHis'),
      'details'            => $message,
      // core does not set this for outbound messages, but it keeps our
      // activities consistent across directions
      'phone_number'       => $receiverNumber,
    ];
    if (!is_null($messageId)) {
      $activityParams['result'] = $messageId;
    }
    if (!is_null($campaignId)) {
      $activityParams['campaign_id'] = $campaignId;
    }

    $activity = CRM_Activity_BAO_Activity::create($activityParams);
    Civi::log()->info("[telemarketing] Outbound SMS recorded for cid={$receiverContactId}.");

    return (int) $activity->id;
  }

  /**
   * The direction the request asks for, inbound unless stated otherwise
   *
   * @param array $request
   *
   * @return string
   */
  private function getDirection(array $request) {
    if (!isset($request['direction']) || !is_scalar($request['direction'])) {
      return self::DIRECTION_INBOUND;
    }

    return strtolower(trim((string) $request['direction']));
  }

  /**
   * The activity date, or NULL when the request does not provide one
   *
   * @return string|null
   * @throws \UnexpectedValueException
   */
  private function getSentAt(array $request) {
    if (!isset($request['sent_at'])) {
      return NULL;
    }

    return $this->parseSentAt($request['sent_at'])->format('YmdHis');
  }

  /**
   * The campaign to record the activity for, or NULL when the request does
   * not provide one
   *
   * @return int|null
   * @throws \UnexpectedValueException
   */
  private function getCampaignId(array $request) {
    if (!isset($request['campaign_id'])) {
      return NULL;
    }

    return $this->parseCampaignId($request['campaign_id']);
  }

  /**
   * Parse and check a "campaign_id" value
   *
   * An unknown ID would otherwise fail on the activity's foreign key, which
   * reads as a transient error to the sender.
   *
   * @return int
   * @throws \UnexpectedValueException
   */
  private function parseCampaignId($campaignId) {
    $campaignId = CRM_Utils_Type::validate($campaignId, 'Positive', FALSE);
    if (is_null($campaignId)) {
      throw new UnexpectedValueException('campaign_id must be the ID of a campaign');
    }

    $campaign = Campaign::get(FALSE)
      ->addSelect('id')
      ->addWhere('id', '=', $campaignId)
      ->execute()
      ->first();
    if (empty($campaign)) {
      throw new UnexpectedValueException("campaign_id {$campaignId} does not exist");
    }

    return $campaignId;
  }

  /**
   * Parse a "sent_at" value into the local time activities are stored in
   *
   * @return \DateTime
   * @throws \UnexpectedValueException
   */
  private function parseSentAt($sentAt) {
    if (!is_scalar($sentAt) || !preg_match(self::SENT_AT_PATTERN, trim((string) $sentAt))) {
      throw new UnexpectedValueException(
        'sent_at must be an ISO-8601 date and time, e.g. "2026-10-13T11:22:33+02:00"'
      );
    }

    try {
      $parsed = new DateTime(trim((string) $sentAt));
    }
    catch (Exception $e) {
      throw new UnexpectedValueException('sent_at is not a valid date');
    }
    $errors = DateTime::getLastErrors();
    if (is_array($errors) && ($errors['warning_count'] + $errors['error_count']) > 0) {
      // e.g. "2026-02-31", which DateTime rolls over into March
      throw new UnexpectedValueException('sent_at is not a valid date');
    }

    return $parsed->setTimezone(new DateTimeZone(date_default_timezone_get()));
  }

  /**
   * Compose the message ID stored in the activity's "result" field, prefixed
   * with the provider ID since message IDs are only unique per provider.
   *
   * @return string|null
   * @throws \UnexpectedValueException
   */
  private function getMessageId(array $request) {
    if (!isset($request['message_id'])) {
      return NULL;
    }

    $messageId = $this->providerInfo['id'] . ':' . trim((string) $request['message_id']);
    if (strlen($messageId) > self::MAX_MESSAGE_ID_LENGTH) {
      throw new UnexpectedValueException('message_id is too long');
    }

    return $messageId;
  }

  /**
   * Look up the activity a message was already recorded as. Trashed
   * activities count, so a deleted activity is not recreated, and both
   * directions are searched, since they share the message ID namespace.
   *
   * @return int|null
   */
  private function getActivityIdByMessageId($messageId) {
    $match = Activity::get(FALSE)
      ->addSelect('id')
      // activity_type_id is indexed, result is not
      ->addWhere('activity_type_id:name', 'IN', array_values(self::ACTIVITY_TYPES))
      ->addWhere('result', '=', $messageId)
      ->setLimit(1)
      ->execute()
      ->first();

    return $match['id'] ?? NULL;
  }

  /**
   * Respond with an error message
   *
   * @param int $httpCode
   * @param string $message
   */
  private function sendError($httpCode, $message) {
    $this->sendResponse($httpCode, ['error' => $message]);
  }

  /**
   * Send the response and terminate the request
   *
   * @param int $httpCode
   * @param array $body
   */
  private function sendResponse($httpCode, array $body) {
    http_response_code($httpCode);
    echo json_encode($body);
    CRM_Utils_System::civiExit();
  }

  /**
   * Parse and return request parameters
   *
   * @return array
   * @throws \Exception
   */
  private function getRequest() {
    $request = file_get_contents('php://input');
    if (empty($request)) {
      throw new Exception('Empty POST body provided');
    }

    // TODO: use JSON_THROW_ON_ERROR
    $request = json_decode($request, TRUE);
    if (!is_array($request)) {
      throw new Exception('Invalid JSON in POST body. Error was: ' . json_last_error_msg());
    }

    return $request;
  }

  /**
   * Validate request parameters
   *
   * @param array $request
   *
   * @throws \UnexpectedValueException
   */
  private function validateRequest(array $request) {
    $missingFields = [];
    foreach (self::REQUIRED_FIELDS as $field) {
      if (!isset($request[$field]) || !is_scalar($request[$field])) {
        $missingFields[] = $field;
      }
      elseif (in_array($field, self::NON_BLANK_FIELDS, TRUE) && trim((string) $request[$field]) === '') {
        $missingFields[] = $field;
      }
    }

    if (count($missingFields) > 0) {
      throw new UnexpectedValueException(
        'Required fields are missing: ' . implode(', ', $missingFields)
      );
    }

    if (isset($request['message_id'])
      && (!is_scalar($request['message_id']) || trim((string) $request['message_id']) === '')
    ) {
      throw new UnexpectedValueException('message_id must not be empty');
    }

    if (isset($request['direction'])
      && (!is_scalar($request['direction']) || !isset(self::ACTIVITY_TYPES[$this->getDirection($request)]))
    ) {
      throw new UnexpectedValueException(
        'direction must be one of: ' . implode(', ', array_keys(self::ACTIVITY_TYPES))
      );
    }

    if (isset($request['sent_at'])) {
      $this->parseSentAt($request['sent_at']);
    }

    if (isset($request['campaign_id'])) {
      $this->parseCampaignId($request['campaign_id']);
    }
  }

  /**
   * Resolve a number to a contact, creating one when the number is unknown
   *
   * Contacts are created with the number as their display name, matching what
   * at.greenpeace.uimods does for inbound messages.
   *
   * @param string $phone Normalized phone number
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  private function getOrCreateContactIdByPhone($phone) {
    $contactId = $this->getContactIdByPhone($phone);
    if (!empty($contactId)) {
      return (int) $contactId;
    }

    // no name available, so use the number itself
    $contact = Contact::create(FALSE)
      ->addValue('display_name', $phone)
      ->addChain('Phone', Phone::create(FALSE)
        ->addValue('contact_id', '$id')
        ->addValue('phone', $phone)
      )
      ->execute()
      ->first();
    Civi::log()->info("[telemarketing] Created contact {$contact['id']} for number {$phone}");

    return (int) $contact['id'];
  }

  /**
   * Look up a contact by phone number, mirroring the suffix match
   * processInbound() uses, so we only create a contact when it finds nothing.
   *
   * @return int|null
   */
  private function getContactIdByPhone($phone) {
    $match = Phone::get(FALSE)
      ->addSelect('contact_id')
      ->addWhere('phone', 'LIKE', '%' . $phone)
      ->addWhere('contact_id.is_deleted', '=', FALSE)
      ->setLimit(1)
      ->execute()
      ->first();

    return $match['contact_id'] ?? NULL;
  }

  /**
   * Normalize phone number using com.cividesk.normalize (if installed).
   * Numbers are expected in international format, with or without "+".
   *
   * @return string
   */
  private function normalizePhone($phone) {
    $phone = trim((string) $phone);
    if (!class_exists('CRM_Utils_Normalize')) {
      return $phone;
    }

    $candidate = $phone;
    if (preg_match('/^[1-9][0-9]+$/', $candidate)) {
      $candidate = '+' . $candidate;
    }
    $normalizedPhone = [
      'phone'         => $candidate,
      'phone_type_id' => NULL,
    ];
    if (CRM_Utils_Normalize::singleton()->normalize_phone($normalizedPhone)) {
      return $normalizedPhone['phone'];
    }

    return $phone;
  }

}
