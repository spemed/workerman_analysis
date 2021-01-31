<?php


namespace YryWorkerman\Event;


use Closure;
use SplPriorityQueue;
use YryWorkerman\Event\Item\Sign;
use YryWorkerman\Event\Item\Socket;
use YryWorkerman\Event\Item\Timer;
use YryWorkerman\Exception\InputNull;
use YryWorkerman\Exception\InvalidInput;
use YryWorkerman\Exception\ProgressFault;


/**
 * Class Select
 * @package YryWorkerman\Event
 * 仅在worker中发挥作用
 */
class Select extends AbsEvent
{
    //从0开始的timerId
    protected int $timerId = 0;

    protected SplPriorityQueue $scheduler;

    //默认睡1s
    protected int $selectTimeout = self::defaultSelectTimeout;

    private const defaultSelectTimeout = 100000000;


    protected array $readFds = [];
    protected array $writeFds = [];
    protected array $exceptFds = [];

    public function __construct()
    {
        // Init SplPriorityQueue.
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * @param resource $fd
     * @param int $flag
     * @param Closure $func
     * @param array $argv
     * @return bool
     * @throws InputNull
     * @throws InvalidInput
     */
    public function registerSocket($fd, int $flag, Closure $func, array $argv = []): bool
    {
        $this->socketEvents[(int)$fd][$flag] = new Socket((int)$fd,$flag,$func,$argv);
        return true;
    }

    /**
     * @param int $signo
     * @param Closure $func
     * @return bool
     * @throws InputNull
     */
    public function registerSignal(int $signo, Closure $func): bool
    {
        $this->signEvents[$signo] = new Sign($signo,$func);
        pcntl_signal($signo,[$this,'signalHandle']);
        return true;
    }

    /**
     * @param $signo
     * @throws ProgressFault
     */
    public function signalHandle($signo) {
        $signalHandle = $this->signEvents[$signo] ?? null;
        //尚未注册信号处理器
        if (is_null($signalHandle)) {
            throw new ProgressFault("signo={$signo} not register");
        }
        $signalHandle->execute();
    }

    /**
     * @param int $wait
     * @param int $flag
     * @param Closure $func
     * @param array $argv
     * @return int
     * @throws InputNull
     * @throws InvalidInput
     */
    public function registerTimer(int $wait, int $flag, Closure $func, array $argv = []): int
    {
        $nextRunTime = $wait+microtime(true);
        $timerId = $this->timerId++;
        $this->timerEvents[$timerId] = new Timer($timerId,$wait,$flag,$func,$argv);
        //对延时时间戳取反,延时时间越长则优先级越小
        $this->scheduler->insert($timerId,-$nextRunTime);
        //取得应该阻塞的微秒数
        $selectTimeOut = ($nextRunTime - microtime(true) ) * 1000000;
        if ($this->selectTimeout > $selectTimeOut) {
            $this->selectTimeout = $selectTimeOut;
        }
        return true;
    }

    public function loop(): void
    {
        while (true) {
            //使用pcntl_signal函数之后
            //需要主动使用 pcntl_signal_dispatch触发信号的处理器
            //这样在收到每一个信号的时候才会执行对应的回调！！
            //相当是一个install的操作,而不是一个轮询的操作,之前理解错了
            //调用pcntl_signal_dispatch时为了安装信号回调,而不是轮询触发信号
            if (!empty($this->signEvents)) {
                pcntl_signal_dispatch();
            }
            //开始注册文件事件
            //todo 应该统计套接字的数目,select需要观察1024个及以上套接字时应该发出警告
            $read = [];
            $write = [];
            $except = [];
            $result = 0;

            $needWatch = !empty($this->readFds) || !empty($this->writeFds) || !empty($this->exceptFds);
            if ($needWatch) {
                $read = $this->readFds;
                $write = $this->writeFds;
                $except = $this->exceptFds;
                //直接微秒级时间戳
                $result = @stream_select($this->readFds,$this->writeFds,$this->exceptFds,0,$this->selectTimeout);
            } else {
                //没有文件事件则空睡
                //如果收到信号,慢阻塞系统调用会被信号打断
                usleep($this->selectTimeout);
            }

            //开始执行时间事件
            if (!empty($this->timerEvents)) {
                $this->tick();
            }

            //文件事件非空开始执行文件事件
            if (!$result) {
                foreach ($read as $v) {
                    $fileEvent = $this->socketEvents[(int)$v][Flag::FD_READ];
                    $fileEvent->execute();
                }
                foreach ($write as $v) {
                    $fileEvent = $this->socketEvents[(int)$v][Flag::FD_WRITE];
                    $fileEvent->execute();
                }
                foreach ($except as $v) {
                    $fileEvent = $this->socketEvents[(int)$v][Flag::FD_EXCEPT];
                    $fileEvent->execute();
                }
            }
        }

    }

    public function delSocket($fd, int $flag): void
    {
        //取得socketItem
        $socketItem = $this->socketEvents[$fd][$flag] ?? null;
        //已经是null则说明已经删除成功
        if (!is_null(null)) {
            return;
        }
        if ($socketItem->isRead()) {
            if (isset($this->readFds[(int)$fd])) {
                unset($this->readFds[(int)$fd]);
            }
        }
        if ($socketItem->isWrite()) {
            if (isset($this->writeFds[(int)$fd])) {
                unset($this->writeFds[(int)$fd]);
            }
        }
        if ($socketItem->isExcept()) {
            if (isset($this->exceptFds[(int)$fd])) {
                unset($this->exceptFds[(int)$fd]);
            }
        }
        return;
    }

    public function delSign(int $signo): void
    {
        if (isset($this->signEvents[$signo])) {
            unset($this->signEvents[$signo]);
        }
        //忽略该信号
        pcntl_signal($signo,SIG_IGN);
    }

    public function delTimer(int $timerId): void
    {
        if (isset($this->timerEvents[$timerId])) {
            unset($this->timerEvents[$timerId]);
        }
    }

    public function clearTimer(): void
    {
        $this->timerEvents = [];
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }

    //开始执行时间事件
    protected function tick() {
        //优先级队列非空持续运行
        while (!$this->scheduler->isEmpty()) {
            $top = $this->scheduler->top();
            //取得timerId
            $timerId = $top["data"];
            //取反获得应该延时的目标时间
            $nextRunTime = -$top["priority"];
            $timerEvent = $this->timerEvents[$timerId] ?? null;
            //过滤被删除的时间时间
            if (is_null($timerEvent)) {
                continue;
            }
            //如果$execute_time小于等于0,则说明应该执行当前事件了
            //如果select检测到文件事件发生提前返回,则此处会更新$this->selectTimeout的阻塞时间
            $this->selectTimeout = ($nextRunTime - microtime(true)) * 1000000;
            if ($this->selectTimeout  <= 0 ) {
                //从优先级队列中弹出该值
                $this->scheduler->extract();
                //开始执行回调
                $timerEvent->execute();
                //如果不是只执行一次的定期时间则将其重新加入调度优先级队列
                if (!$timerEvent->isOnce()) {
                    $this->scheduler->insert($timerId,-(microtime(true) + $timerEvent->getWait()));
                    continue;
                }
                //从时间任务表中删除
                unset($this->timerEvents[$this->timerId]);
            }
            return;
        }
        //如果没有可以执行的定时事件则重新设置 $this->selectTimeout
        $this->selectTimeout = self::defaultSelectTimeout;
        return;
    }
}