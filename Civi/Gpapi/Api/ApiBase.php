<?php

namespace Civi\Gpapi\ContractHelper;

abstract class ApiBase {

  protected array $params = [];

  public function __construct(array $params) {
    $this->params = $this->setParams($params);
  }

  protected function setParams($params): array {
    return $params;
  }

  abstract public function getResult(): array;

}
