<?php


namespace YryWorkerman\Exception;


use Exception;
use Throwable;

class ProgressFault extends Exception
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, 00003, $previous);
    }
}