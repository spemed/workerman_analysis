<?php
namespace YryWorkerman\Event\Item;

use Closure;
use YryWorkerman\Event\Flag;
use YryWorkerman\Exception\InputNull;
use YryWorkerman\Exception\InvalidInput;

class Timer extends Base
{
    protected int $timerId;
    protected int $wait;
    protected int $flag;

    /**
     * Timer constructor.
     * @param int $timerId
     * @param int $wait
     * @param int $flag
     * @param Closure $handle
     * @param array $args
     * @throws InvalidInput
     * @throws InputNull
     */
    public function __construct(int $timerId,int $wait,int $flag,Closure $handle, array $args = [])
    {
        $this->timerId = $timerId;
        $this->wait = $wait;
        if ($flag != Flag::FD_TIMER && $flag != Flag::FD_TIMER_ONCE) {
            throw new InvalidInput("timer flag should be Flag::FD_TIMER or Flag::FD_TIMER_ONCE");
        }
        $this->flag = $flag;
        parent::__construct($handle, $args);
    }

    /**
     * @return bool
     * 定时器是否只执行一遍
     */
    public function isOnce(): bool
    {
        return $this->flag === Flag::FD_TIMER_ONCE;
    }

    /**
     * @return int
     * 获取延时时间
     */
    public function getWait(): int
    {
        return $this->wait;
    }

    /**
     * @return int
     * 获取定时器的id
     */
    public function getTimerId(): int
    {
        return $this->timerId;
    }

    /**
     * @return int
     */
    public function getFlag(): int
    {
        return $this->flag;
    }

    /**
     * @param int $timerId
     * @param int $wait
     * @param int $flag
     * @param Closure $handle
     * @param array $args
     * @return array|Timer[]
     * @throws InputNull
     * @throws InvalidInput
     */
    public static function getPair(int $timerId,int $wait,int $flag,Closure $handle, array $args = []):array {
        return ["$timerId"=>new self($timerId,$wait,$flag,$handle,$args)];
    }
}