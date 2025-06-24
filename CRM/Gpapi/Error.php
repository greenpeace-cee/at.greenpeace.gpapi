<?php

use Civi\Api4;

class CRM_Gpapi_Error {
  // @TODO: use a more generic name/type
  const IMPORT_ERROR_ACTIVITY_TYPE = 'streetimport_error';

  public static function create($endpoint, $error, $context) {
    $contact_id = $context['contact_id'] ?? NULL;

    if (empty($contact_id) && isset($context['hash'])) {
      $contact = Api4\Contact::get(FALSE)
        ->addSelect('id')
        ->addWhere('hash', '=', $context['hash'])
        ->execute()
        ->first();

      $contact_id = is_null($contact) ? NULL : $contact['id'];
    }

    if ($error instanceof Exception) {
      $message = $error->getMessage();
      $file = $error->getFile();
      $line = $error->getLine();
      $trace = $error->getTrace();

      $message = "$message in $file:$line";

      $context = [
        'parameters' => $context,
        'trace'      => $trace,
      ];
    } else {
      $message = $error;
    }

    Civi::log()->error("$endpoint: $message", $context);

    $context_dump = print_r($context, TRUE);

    civicrm_api3('Activity', 'create', [
      'activity_type_id' => self::IMPORT_ERROR_ACTIVITY_TYPE,
      'subject'          => "Custom API: $endpoint Error",
      'details'          => "<pre>$message\n\nContext:\n\n$context_dump</pre>",
      'status_id'        => 'Scheduled',
      'target_id'        => $contact_id,
    ]);

    return civicrm_api3_create_error($message);
  }
}
