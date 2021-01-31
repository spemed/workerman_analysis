<?php

namespace YryWorkerman\Exception;

use Exception;
use Throwable;

class InputNull extends Exception
{
    public function __construct($param = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct("input param {$param} should not be null", 00001, $previous);
    }
}