<?php

namespace YryWorkerman\Event\Item;
use Closure;
use YryWorkerman\Exception\InputNull;

abstract class Base
{
    //回调函数
    protected Closure $handle;
    //回调函数执行参数
    protected array $args;

    /**
     * Base constructor.
     * @param Closure $handle
     * @param array $args
     * @throws InputNull
     */
    public function __construct(Closure $handle,array $args = [])
    {
        if (is_null($handle)) {
            throw new InputNull("handle");
        }
        $this->handle = $handle;
        $this->args = $args;
    }

    /**
     * 重写父类的方法
     */
    public function execute()
    {
        call_user_func_array($this->handle,$this->args);
    }
}