<?php

class CRM_Gpapi_Error {
  // @TODO: use a more generic name/type
  const IMPORT_ERROR_ACTIVITY_TYPE = 'streetimport_error';

  public static function create($endpoint, $message, $context) {
    if ($message instanceof Exception) {
      $exception = $message;
      $message = $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine();
      $context = ['parameters' => $context];
      $context['trace'] = $exception->getTrace();
    }
    Civi::log()->error("{$endpoint}: {$message}", $context);
    $params = [
      'activity_type_id'   => self::IMPORT_ERROR_ACTIVITY_TYPE,
      'subject'            => 'Custom API: ' . $endpoint . ' Error',
      'status_id'          => 'Scheduled',
      'details'            => '<pre>' . $message . "\n\nContext:\n\n" . print_r($context, true) . '</pre>',
    ];
    civicrm_api3('Activity', 'create', $params);
    return civicrm_api3_create_error($message);
  }
}
