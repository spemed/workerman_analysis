<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Events;

/**
 * select eventloop
 */
class Select implements EventInterface
{
    /**
     * All listeners for read/write event.
     *
     * @var array
     */
    public $_allEvents = array();

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    public $_signalEvents = array();

    /**
     * Fds waiting for read event.
     *
     * @var array
     */
    protected $_readFds = array();

    /**
     * Fds waiting for write event.
     *
     * @var array
     */
    protected $_writeFds = array();

    /**
     * Fds waiting for except event.
     *
     * @var array
     */
    protected $_exceptFds = array();

    /**
     * Timer scheduler.
     * {['data':timer_id, 'priority':run_timestamp], ..}
     *
     * @var \SplPriorityQueue
     * spl优先级队列
     */
    protected ?\SplPriorityQueue $_scheduler = null;

    /**
     * All timer event listeners.
     * [[func, args, flag, timer_interval], ..]
     *
     * @var array
     */
    protected $_eventTimer = array();

    /**
     * Timer id.
     *
     * @var int
     */
    protected $_timerId = 1;

    /**
     * Select timeout.
     *
     * @var int
     */
    protected $_selectTimeout = 100000000;

    /**
     * Paired socket channels
     *
     * @var array
     */
    protected $channel = array();

    /**
     * Construct.
     */
    public function __construct()
    {
        // Init SplPriorityQueue.
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * {@inheritdoc}
     */
    public function add($fd, $flag, $func, $args = array())
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $count = $flag === self::EV_READ ? \count($this->_readFds) : \count($this->_writeFds);
                //select可维护的最大连接数 FD_SETSIZE 为 1024
                //所以任一读写事件的socket集合不得超过1024
                if ($count >= 1024) {
                    echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
                } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {//非linux系统可能存在 FD_SETSIZE 为256的限制
                    echo "Warning: system call select exceeded the maximum number of connections 256.\n";
                }
                $fd_key                           = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                if ($flag === self::EV_READ) {
                    $this->_readFds[$fd_key] = $fd;
                } else {
                    $this->_writeFds[$fd_key] = $fd;
                }
                break;
            case self::EV_EXCEPT:
                $fd_key = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_exceptFds[$fd_key] = $fd;
                break;
            case self::EV_SIGNAL:
                // Windows not support signal.
                if(\DIRECTORY_SEPARATOR !== '/') {
                    return false;
                }
                //此处fd为信号名称
                $fd_key                              = (int)$fd;
                $this->_signalEvents[$fd_key][$flag] = array($func, $fd);
                \pcntl_signal($fd, array($this, 'signalHandler'));
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $timer_id = $this->_timerId++;
                //提供了微秒级定时器
                //$fd是延时执行的微秒数
                $run_time = \microtime(true) + $fd;
                //对runtime取反,延时越长的定时事件 -$run_time越小,优先级越低
                //实际上是一个索引堆,堆中只存储了数据的索引,数据本身存储在$this->_eventTimer数组中
                $this->_scheduler->insert($timer_id, -$run_time);
                $this->_eventTimer[$timer_id] = array($func, (array)$args, $flag, $fd);
                //如果增加了定时事件,则需要更新selectTimeout的阻塞时间
                //意思是当eventLoop只阻塞于文件事件[write/read]的时候,如果没有返回可写/可读/异常事件
                //则阻塞到离当前时间最近的一个定时事件到达时返回
                //换算成微秒
                $select_timeout = ($run_time - \microtime(true)) * 1000000;
                //不断更新至最小值
                if( $this->_selectTimeout > $select_timeout ){ 
                    $this->_selectTimeout = $select_timeout;   
                }  
                return $timer_id;
        }

        return true;
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     */
    public function  signalHandler($signal)
    {
        \call_user_func_array($this->_signalEvents[$signal][self::EV_SIGNAL][0], array($signal));
    }

    /**
     * {@inheritdoc}
     */
    public function del($fd, $flag)
    {
        $fd_key = (int)$fd;
        switch ($flag) {
            case self::EV_READ:
                unset($this->_allEvents[$fd_key][$flag], $this->_readFds[$fd_key]);
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_WRITE:
                unset($this->_allEvents[$fd_key][$flag], $this->_writeFds[$fd_key]);
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_EXCEPT:
                unset($this->_allEvents[$fd_key][$flag], $this->_exceptFds[$fd_key]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_SIGNAL:
                if(\DIRECTORY_SEPARATOR !== '/') {
                    return false;
                }
                unset($this->_signalEvents[$fd_key]);
                //进程忽略该信号
                \pcntl_signal($fd, SIG_IGN);
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE;
                //直接删除该任务
                //$fd_key为timerId
                unset($this->_eventTimer[$fd_key]);
                return true;
        }
        return false;
    }

    /**
     * Tick for timer.
     * 依托优先级队列实现的定时器执行逻辑
     * @return void
     */
    protected function tick()
    {
        //调度队列非空
        while (!$this->_scheduler->isEmpty()) {
            //取出堆顶的元素[优先级最高,即是离当前最近的时间事件]
            $scheduler_data       = $this->_scheduler->top();
            $timer_id             = $scheduler_data['data'];
            $next_run_time        = -$scheduler_data['priority'];
            $time_now             = \microtime(true);
            $this->_selectTimeout = ($next_run_time - $time_now) * 1000000;
            //$this->_selectTimeout <= 0 说明应当执行当前定时事件了
            if ($this->_selectTimeout <= 0) {
                //从堆中弹出一个元素
                $this->_scheduler->extract();
                //如果该时间事件已经被删除则跳过
                if (!isset($this->_eventTimer[$timer_id])) {
                    continue;
                }

                // [func, args, flag, timer_interval]
                $task_data = $this->_eventTimer[$timer_id];
                //如果是持续的时间事件则更新下次执行时间并将其重新放入优先级队列中
                if ($task_data[2] === self::EV_TIMER) {
                    $next_run_time = $time_now + $task_data[3];
                    $this->_scheduler->insert($timer_id, -$next_run_time);
                }
                //执行回调
                \call_user_func_array($task_data[0], $task_data[1]);
                //只执行一次的定时事件从事件中心删除
                if (isset($this->_eventTimer[$timer_id]) && $task_data[2] === self::EV_TIMER_ONCE) {
                    $this->del($timer_id, self::EV_TIMER_ONCE);
                }
                continue;
            }
            return;
        }
        //如果调度队列为空,无任何定时任务则重置 $this->_selectTimeout
        $this->_selectTimeout = 100000000;
    }

    /**
     * {@inheritdoc}
     */
    public function clearAllTimer()
    {
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->_eventTimer = array();
    }

    /**
     * {@inheritdoc}
     */
    public function loop()
    {
        //事件循环
        while (1) {
            if(\DIRECTORY_SEPARATOR === '/') {
                // Calls signal handlers for pending signals
                // todo [其实我也不知道php为什么注册信号回调这么蹩脚]
                //每次loop都轮询一遍信号是否产生
                \pcntl_signal_dispatch();
            }

            $read  = $this->_readFds;
            $write = $this->_writeFds;
            $except = $this->_exceptFds;

            $ret = false;
            if ($read || $write || $except) {
                // Waiting read/write/signal/timeout events.
                try {
                    //阻塞到可读/可写/异常事件发生或者$this->_selectTimeout微秒
                    //可读 -- 1.fin包 2.发生了错误[rst] 3.收到正常数据包
                    //可写 -- 1.写入数据大于缓冲区最低水位 2.发生了错误 3.非阻塞套接字connect返回
                    //异常 -- todo 应该是发生了错误,不确定,可以试一下
                    $ret = @stream_select($read, $write, $except, 0, $this->_selectTimeout);
                } catch (\Exception $e) {}

            } else {
                //没有注册文件事件则空睡 $this->_selectTimeout
                usleep($this->_selectTimeout);
            }

            //弹出一个时间事件
            //todo 研究一下优先级队列的api
            if (!$this->_scheduler->isEmpty()) {
                $this->tick();
            }

            if (!$ret) {
                continue;
            }

            //存在注册的读事件则顺序执行其回调
            if ($read) {
                foreach ($read as $fd) {
                    $fd_key = (int)$fd;
                    if (isset($this->_allEvents[$fd_key][self::EV_READ])) {
                        \call_user_func_array($this->_allEvents[$fd_key][self::EV_READ][0],
                            array($this->_allEvents[$fd_key][self::EV_READ][1]));
                    }
                }
            }

            //存在注册的写事件则顺序执行其回调
            if ($write) {
                foreach ($write as $fd) {
                    $fd_key = (int)$fd;
                    if (isset($this->_allEvents[$fd_key][self::EV_WRITE])) {
                        \call_user_func_array($this->_allEvents[$fd_key][self::EV_WRITE][0],
                            array($this->_allEvents[$fd_key][self::EV_WRITE][1]));
                    }
                }
            }

            //存在注册的异常事件则顺序执行其回调
            if($except) {
                foreach($except as $fd) {
                    $fd_key = (int) $fd;
                    if(isset($this->_allEvents[$fd_key][self::EV_EXCEPT])) {
                        \call_user_func_array($this->_allEvents[$fd_key][self::EV_EXCEPT][0],
                            array($this->_allEvents[$fd_key][self::EV_EXCEPT][1]));
                    }
                }
            }
        }
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function destroy()
    {

    }

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return \count($this->_eventTimer);
    }
}
