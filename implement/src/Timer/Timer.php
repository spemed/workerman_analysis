<?php

namespace YryWorkerman\Timer;
use Closure;
use YryWorkerman\Event\EventInterface;
use YryWorkerman\Event\Flag;

class Timer
{
    protected EventInterface $eventLoop;
    public function __construct(EventInterface $eventLoop)
    {
        $this->eventLoop = $eventLoop;
    }

    public function ticker(int $wait,Closure $handle,array $args = []) {
        $this->eventLoop->registerTimer($wait,Flag::FD_TIMER,$handle,$args);
    }

    public function once(int $wait,Closure $handle,array $args = []) {
        $this->eventLoop->registerTimer($wait,Flag::FD_TIMER_ONCE,$handle,$args);
    }
}