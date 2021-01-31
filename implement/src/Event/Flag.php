<?php


namespace YryWorkerman\Event;


class Flag
{
    /**
     * 套接字读事件标志
     */
    const FD_READ =  1;
    /**
     *  套接字写事件标志
     */
    const FD_WRITE =  2;
    /**
     * 套接字异常事件标志
     */
    const FD_EXCEPT = 3;
    /**
     *  信号标志
     */
    const FD_SIGNAL = 4;
    /**
     * 定时事件标志
     */
    const FD_TIMER = 8;
    /**
     * 只执行一次的延时事件标志
     */
    const FD_TIMER_ONCE = 16;
}