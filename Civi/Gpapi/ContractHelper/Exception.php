<?php

namespace Civi\Gpapi\ContractHelper;

class Exception extends \Exception {
  const PAYMENT_METHOD_INVALID = 10;
  const PAYMENT_INSTRUMENT_UNSUPPORTED = 20;

}
