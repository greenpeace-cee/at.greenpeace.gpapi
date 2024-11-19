<?php

namespace Civi\Gpapi;

use Civi\Api4\Email;
use Civi\Api4\Group;
use Civi\Api4\OptionValue;

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

  public static function getGroupContactTitle($groupContactId): string {
    $groups = Group::get(FALSE)
      ->addSelect('id', 'title')
      ->addWhere('id', '=', $groupContactId)
      ->setLimit(1)
      ->execute();
    foreach ($groups as $group) {
      return "Opt-Out from \"{$group['title']}\" via Engagement Tool";
    }

    return '';
  }

  public static function getOptionValueLabel($optionGroupName, $optionValueName): string {
    $optionValues = OptionValue::get(FALSE)
      ->addSelect('label')
      ->addWhere('option_group_id:name', '=', $optionGroupName)
      ->addWhere('name', '=', $optionValueName)
      ->setLimit(1)
      ->execute();
    foreach ($optionValues as $optionValue) {
      return 'Added "' . $optionValue['label'] . '" via Engagement Tool';
    }

    return '';
  }

}
