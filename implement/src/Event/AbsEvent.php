<?php


namespace YryWorkerman\Event;


abstract class AbsEvent implements EventInterface
{
    protected array $socketEvents = [];
    protected array $signEvents = [];
    protected array $timerEvents = [];
}