<?php

/**
 * This class merely serves to reset the static variable $singleton of class
 * CRM_Identitytracker_Configuration. Not resetting this variable will lead to
 * errors during repeated installations of the extension
 * de.systopia.identitytracker
 */

class CRM_Gpapi_Identitytracker_Configuration
  extends CRM_Identitytracker_Configuration {

  public static function resetInstance() {
    self::$singleton = NULL;
  }

}

?>
