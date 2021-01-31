<?php
namespace YryWorkerman\Event;

use Closure;

interface EventInterface
{
    /**
     * 注册socket事件
     * @param resource $fd 套接字文件描述符
     * @param int $flag FD_READ | FD_WRITE | FD_EXCEPT
     * @param Closure $func 回调函数
     * @param array $argv 回调函数的执行参数
     * @return bool 注册结果
     */
    public function registerSocket($fd,int $flag, Closure $func, array $argv = []):bool;

    /**
     * 注册信号事件
     * @param int $signo 信号名称
     * @param Closure $func 回调函数
     * @return bool 注册结果
     */
    public function registerSignal(int $signo, Closure $func):bool;

    /**
     * 注册定时器事件
     * @param int $wait 微秒级延时时间
     * @param int $flag FD_TIMER | FD_TIMER_ONCE
     * @param Closure $func 回调函数
     * @param array $argv 回调函数的执行参数
     * @return int 注册的定时器id
     */
    public function registerTimer(int $wait,int $flag,Closure $func, array $argv = []):int;

    /**
     * @return mixed 事件循环中心
     */
    public function loop():void;

    /**
     * @param $fd
     * @param int $flag
     * @return mixed 删除socket事件
     */
    public function delSocket($fd,int $flag):void;

    /**
     * @param int $signo
     * @return mixed 删除信号事件
     */
    public function delSign(int $signo):void;

    /**
     * @param int $timerId
     * 删除timer事件
     */
    public function delTimer(int $timerId):void;

    /**
     * 清理所有的定时器
     */
    public function clearTimer():void;
}