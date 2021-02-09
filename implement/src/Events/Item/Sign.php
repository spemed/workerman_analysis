<?php
namespace YryWorkerman\Event\Item;

use Closure;
use YryWorkerman\Exception\InputNull;
use YryWorkerman\Exception\ProgressFault;

class Sign extends Base
{
    protected int $signo;
    /**
     * Sign constructor.
     * @param int $signo
     * @param Closure $handle
     * @throws InputNull
     */
    public function __construct(int $signo,Closure $handle)
    {
        $this->signo = $signo;
        parent::__construct($handle);
    }

    public function execute()
    {
        call_user_func($this->handle,$this->signo);
    }
}