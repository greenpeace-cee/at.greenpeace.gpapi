<?php

namespace Civi\Gpapi;

use Civi\Api4\Email;
use Civi\Api4\Group;

class GpApiUtils {

  public static function getContactPrimaryEmail($contactId): string {
    $emails = Email::get(FALSE)
      ->addSelect('email')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('is_primary', '=', TRUE)
      ->setLimit(1)
      ->execute();
    foreach ($emails as $email) {
      return $email['email'];
    }

    return '';
  }

  public static function findGroupContactTitle($groupContactId): string {
    $groups = Group::get(FALSE)
      ->addSelect('id', 'title')
      ->addWhere('id', '=', $groupContactId)
      ->setLimit(1)
      ->execute();
    foreach ($groups as $group) {
      return $group['title'];
    }

    return '';
  }

  public static function getOptionValueValue($optionGroupName, $optionValueName): string {
    return civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'value',
      'option_group_id' => $optionGroupName,
      'name' => $optionValueName,
    ]);
  }

}
