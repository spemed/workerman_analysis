<?php

namespace YryWorkerman\Signal;
use Closure;
use YryWorkerman\Event\EventInterface;

class Signal
{
    protected EventInterface $event;
    public function __construct(EventInterface $event)
    {
        $this->event = $event;
    }

    public function add(int $signo,Closure $handle) {
        $this->event->registerSignal($signo,$handle);
    }

    public function del(int $signo) {
        $this->event->delSign($signo);
    }
}