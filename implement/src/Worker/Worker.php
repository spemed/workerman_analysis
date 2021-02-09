<?php

namespace YryWorkerman\Worker;
/**
 * Class Worker
 * @package YryWorkerman\Worker
 * worker实例
 */
class Worker
{
    /**
     * @var int
     * 每个worker组的进程默认为1
     */
    protected int $count = 1;

    /**
     * @var string
     * 套接字地址字符串
     * http://0.0.0.0:8234 or
     * tcp://0.0.0.0:8234
     */
    protected string $socketName = "";

    /**
     * @var string
     * 通过$socketName解析出来的应用层协议
     * 如果是自定义tcp或者udp服务器则该字段为空
     */
    protected string $scheme = "";

    /**
     * @var string
     * 通过$socketName解析出来的传输层协议
     * 默认为tcp
     */
    protected string $transportProtocol = "tcp";

    /**
     * Worker constructor.
     * @param string $socketName
     */
    public function __construct(string $socketName)
    {
        $this->socketName = $socketName;
        //todo 解析socketName

    }

    /**
     * @param int $count
     */
    public function setCount(int $count): void
    {
        $this->count = $count;
    }

    /**
     * @return int
     */
    public function getCount(): int
    {
        return $this->count;
    }

    public function run()
    {
        while (true);
    }
}