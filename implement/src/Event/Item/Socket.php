<?php
namespace YryWorkerman\Event\Item;

use Closure;
use YryWorkerman\Event\Flag;
use YryWorkerman\Exception\InputNull;
use YryWorkerman\Exception\InvalidInput;

class Socket extends Base
{
    /**
     * @var resource $fd 套接字描述符
     */
    protected $fd;

    protected int $flag;

    /**
     * Socket constructor.
     * @param int $fd
     * @param int $flag
     * @param Closure $handle
     * @param array $args
     * @throws InvalidInput
     * @throws InputNull
     */
    public function __construct(int $fd,int $flag,Closure $handle, array $args = [])
    {
        if ($flag != Flag::FD_READ || $flag != Flag::FD_WRITE || $flag != Flag::FD_EXCEPT) {
            throw new InvalidInput("flag should be Flag::FD_READ or Flag::FD_WRITE or Flag::FD_EXCEPT");
        }
        $this->fd = $fd;
        parent::__construct($handle, $args);
    }

    /**
     * @return int
     */
    public function isRead(): int
    {
        return $this->flag === FLAG::FD_READ;
    }

    /**
     * @return int
     */
    public function isWrite(): int
    {
        return $this->flag === FLAG::FD_WRITE;
    }

    /**
     * @return int
     */
    public function isExcept(): int
    {
        return $this->flag === FLAG::FD_EXCEPT;
    }
}