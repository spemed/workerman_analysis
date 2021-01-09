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
namespace Workerman;
require_once __DIR__ . '/Lib/Constants.php';

use Workerman\Events\EventInterface;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\UdpConnection;
use Workerman\Lib\Timer;
use Workerman\Events\Select;
use \Exception;

/**
 * Worker class
 * A container for listening ports
 */
class Worker
{
    /**
     * Version.
     *
     * @var string
     */
    const VERSION = '4.0.18';

    /**
     * Status starting.
     * worker正在启动中
     *
     * @var int
     */
    const STATUS_STARTING = 1;

    /**
     * Status running.
     * worker正在运行中
     *
     * @var int
     */
    const STATUS_RUNNING = 2;

    /**
     * Status shutdown.
     * worker 退出
     *
     * @var int
     */
    const STATUS_SHUTDOWN = 4;

    /**
     * Status reloading.
     * worker正重新加载中
     *
     * @var int
     */
    const STATUS_RELOADING = 8;

    /**
     * After sending the restart command to the child process KILL_WORKER_TIMER_TIME seconds,
     * if the process is still living then forced to kill.
     *
     * @var int
     */
    const KILL_WORKER_TIMER_TIME = 2;

    /**
     * Default backlog. Backlog is the maximum length of the queue of pending connections.
     * backlog 为等待完成三次握手和已经完成三次握手的连接所在的等待队列的长度,如果队满则不会新来的握手请求回应ACK
     *
     * @var int
     */
    const DEFAULT_BACKLOG = 102400;
    /**
     * Max udp package size.
     *
     * @var int
     */
    const MAX_UDP_PACKAGE_SIZE = 65535;

    /**
     * The safe distance for columns adjacent
     *
     * @var int
     */
    const UI_SAFE_LENGTH = 4;

    /**
     * Worker id.
     *
     * @var int
     */
    public $id = 0;

    /**
     * Name of the worker processes.
     *
     * @var string
     */
    public $name = 'none';

    /**
     * Number of worker processes.
     *
     * @var int
     */
    public $count = 1;

    /**
     * Unix user of processes, needs appropriate privileges (usually root).
     *
     * @var string
     */
    public $user = '';

    /**
     * Unix group of processes, needs appropriate privileges (usually root).
     *
     * @var string
     */
    public $group = '';

    /**
     * reloadable.
     *
     * @var bool
     */
    public $reloadable = true;

    /**
     * reuse port.
     *
     * @var bool
     */
    public $reusePort = false;

    /**
     * Emitted when worker processes start.
     *
     * @var callable
     */
    public $onWorkerStart = null;

    /**
     * Emitted when a socket connection is successfully established.
     *
     * @var callable
     */
    public $onConnect = null;

    /**
     * Emitted when data is received.
     *
     * @var callable
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var callable
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     *
     * @var callable
     */
    public $onError = null;

    /**
     * Emitted when the send buffer becomes full.
     *
     * @var callable
     */
    public $onBufferFull = null;

    /**
     * Emitted when the send buffer becomes empty.
     *
     * @var callable
     */
    public $onBufferDrain = null;

    /**
     * Emitted when worker processes stoped.
     *
     * @var callable
     */
    public $onWorkerStop = null;

    /**
     * Emitted when worker processes get reload signal.
     *
     * @var callable
     */
    public $onWorkerReload = null;

    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public $transport = 'tcp';

    /**
     * Store all connections of clients.
     *
     * @var array
     */
    public $connections = array();

    /**
     * Application layer protocol.
     * 应用层协议
     *
     * @var string
     */
    public $protocol = null;

    /**
     * Root path for autoload.
     * 自动加载的根路径
     *
     * @var string
     */
    protected $_autoloadRootPath = '';

    /**
     * Pause accept new connections or not.
     * 是否停止进行accept系统调用,如果是则会停止产生新连接
     * [可能用于重启之前设置true,重启之后设置false开始接收请求]
     *
     * @var bool
     */
    protected $_pauseAccept = true;

    /**
     * Is worker stopping ?
     * @var bool
     */
    public $stopping = false;

    /**
     * Daemonize.
     *
     * @var bool
     */
    public static $daemonize = false;

    /**
     * Stdout file.
     * 标准输出重定向
     * @var string
     */
    public static $stdoutFile = '/dev/null';

    /**
     * The file to store master process PID.
     * 存储master进程pid的文件路径
     * todo 学习到了,当脚本文件需要提供命令管理进程时,应该有一个文件存储进程的pid
     * @var string
     */
    public static $pidFile = '';

    /**
     * Log file.
     * 日志路径
     * @var mixed
     */
    public static $logFile = '';

    /**
     * Global event loop.
     * 全局的事件循环中心
     * @var EventInterface
     */
    public static $globalEvent = null;

    /**
     * Emitted when the master process get reload signal.
     *
     * @var callable
     */
    public static $onMasterReload = null;

    /**
     * Emitted when the master process terminated.
     *
     * @var callable
     */
    public static $onMasterStop = null;

    /**
     * EventLoopClass
     *
     * @var string
     */
    public static $eventLoopClass = '';

    /**
     * Process title
     *
     * @var string
     */
    public static $processTitle = 'WorkerMan';

    /**
     * The PID of master process.
     *
     * @var int
     */
    protected static $_masterPid = 0;

    /**
     * Listening socket.
     * 监听的socket,调用socket,bind和listen之后得到的被动套接字
     * @var resource
     */
    protected $_mainSocket = null;

    /**
     * Socket name. The format is like this http://0.0.0.0:80 .
     *
     * @var string
     */
    protected $_socketName = '';

    /** parse from _socketName avoid parse again in master or worker
     * LocalSocket The format is like tcp://0.0.0.0:8080
     * @var string
     */

    protected $_localSocket=null;

    /**
     * Context of socket.
     *
     * @var resource
     */
    protected $_context = null;

    /**
     * All worker instances.
     * 所有实例化的worker对象的集合
     * @var Worker[]
     */
    protected static $_workers = array();

    /**
     * All worker processes pid.
     * The format is like this [worker_id=>[pid=>pid, pid=>pid, ..], ..]
     * worker实例和对应worker进程的映射关系表
     * @var array
     */
    protected static $_pidMap = array();

    /**
     * All worker processes waiting for restart.
     * The format is like this [pid=>pid, pid=>pid].
     * 等待进行重启的进程列表[存储的是pid]
     * @var array
     */
    protected static $_pidsToRestart = array();

    /**
     * Mapping from PID to worker process ID.
     * The format is like this [worker_id=>[0=>$pid, 1=>$pid, ..], ..].
     *
     * @var array
     */
    protected static $_idMap = array();

    /**
     * Current status.
     * master进程当前的状态
     * 默认设置初始化为启动中
     * @var int
     */
    protected static $_status = self::STATUS_STARTING;

    /**
     * Maximum length of the worker names.
     *
     * @var int
     */
    protected static $_maxWorkerNameLength = 12;

    /**
     * Maximum length of the socket names.
     *
     * @var int
     */
    protected static $_maxSocketNameLength = 12;

    /**
     * Maximum length of the process user names.
     *
     * @var int
     */
    protected static $_maxUserNameLength = 12;

    /**
     * Maximum length of the Proto names.
     *
     * @var int
     */
    protected static $_maxProtoNameLength = 4;

    /**
     * Maximum length of the Processes names.
     *
     * @var int
     */
    protected static $_maxProcessesNameLength = 9;

    /**
     * Maximum length of the Status names.
     *
     * @var int
     */
    protected static $_maxStatusNameLength = 1;

    /**
     * The file to store status info of current worker process.
     * todo 学习到了,如果需要开发一个常驻内存的进程应用,需要记录进程的状态的时候
     * todo 可以把进程的状态[进程名称,pid,请求数,连接数等等]写入文件中
     * @var string
     */
    protected static $_statisticsFile = '';

    /**
     * Start file.
     *
     * @var string
     */
    protected static $_startFile = '';

    /**
     * OS.
     *
     * @var string
     */
    protected static $_OS = \OS_TYPE_LINUX;

    /**
     * Processes for windows.
     *
     * @var array
     */
    protected static $_processForWindows = array();

    /**
     * Status info of current worker process.
     *
     * @var array
     */
    protected static $_globalStatistics = array(
        'start_timestamp'  => 0,
        'worker_exit_info' => array()
    );

    /**
     * Available event loops.
     *
     * @var array
     */
    protected static $_availableEventLoops = array(
        'event'    => '\Workerman\Events\Event',
        'libevent' => '\Workerman\Events\Libevent'
    );

    /**
     * PHP built-in protocols.
     *
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix',
        'ssl'   => 'tcp'
    );

    /**
     * PHP built-in error types.
     *
     * @var array
     */
    protected static $_errorType = array(
        \E_ERROR             => 'E_ERROR',             // 1
        \E_WARNING           => 'E_WARNING',           // 2
        \E_PARSE             => 'E_PARSE',             // 4
        \E_NOTICE            => 'E_NOTICE',            // 8
        \E_CORE_ERROR        => 'E_CORE_ERROR',        // 16
        \E_CORE_WARNING      => 'E_CORE_WARNING',      // 32
        \E_COMPILE_ERROR     => 'E_COMPILE_ERROR',     // 64
        \E_COMPILE_WARNING   => 'E_COMPILE_WARNING',   // 128
        \E_USER_ERROR        => 'E_USER_ERROR',        // 256
        \E_USER_WARNING      => 'E_USER_WARNING',      // 512
        \E_USER_NOTICE       => 'E_USER_NOTICE',       // 1024
        \E_STRICT            => 'E_STRICT',            // 2048
        \E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', // 4096
        \E_DEPRECATED        => 'E_DEPRECATED',        // 8192
        \E_USER_DEPRECATED   => 'E_USER_DEPRECATED'   // 16384
    );

    /**
     * Graceful stop or not.
     * 是否优雅退出
     * @var bool
     */
    protected static $_gracefulStop = false;

    /**
     * Standard output stream
     * @var resource
     */
    protected static $_outputStream = null;

    /**
     * If $outputStream support decorated
     * @var bool
     */
    protected static $_outputDecorated = null;

    /**
     * Run all worker instances.
     * 程序启动的入口
     * @return void
     */
    public static function runAll()
    {
        //sapi的环境监测,必须在cli环境下运行
        //同时通过检测路径分隔符,确定运行的操作系统是否为windos环境
        static::checkSapiEnv();
        //初始化操作
        static::init();
        //命令行参数解析
        static::parseCommand();
        //进程守护化
        static::daemonize();
        //初始化worker实例下的进程组
        static::initWorkers();
        //安装信号回调
        static::installSignal();
        //保存master进程的pid
        static::saveMasterPid();
        //命令行文本ui渲染
        static::displayUI();
        //开始fork worker实例中的进程
        static::forkWorkers();
        //标准输入输出,错误重定向
        static::resetStd();
        //master转为monitor监控worker进程
        static::monitorWorkers();
    }

    /**
     * Check sapi.
     *
     * @return void
     */
    protected static function checkSapiEnv()
    {
        // Only for cli.
        if (\PHP_SAPI !== 'cli') {
            exit("Only run in command line mode \n");
        }
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::$_OS = \OS_TYPE_WINDOWS;
        }
    }

    /**
     * Init.
     *
     * @return void
     */
    protected static function init()
    {
        //设置默认的错误处理函数
        \set_error_handler(function($code, $msg, $file, $line){
            Worker::safeEcho("$msg in file $file on line $line\n");
        });

        // Start file.
        $backtrace        = \debug_backtrace();
        //获取正在执行runAll方法的文件名称
        //这里为 /Users/bytedance/php/testWorkerman/index.php
        //然后为静态变量$_startFile赋值,这里实际上是保存了程序的入口文件[执行了Worker::runAll()的文件]
        static::$_startFile = $backtrace[\count($backtrace) - 1]['file'];

        //将入口文件中的/替换为_,得到_Users_bytedance_php_testWorkerman_index.php
        //利用文件在文件系统中的唯一性,得到了一个不可能重复的前缀
        $unique_prefix = \str_replace('/', '_', static::$_startFile);


        // Pid file
        //如果没有设置pid文件的存储路径,则以Workerman同级目录拼接$unique_prefix.pid字符串,形成pid文件的存储路径
        //在这里为 /Users/bytedance/php/testWorkerman/Workerman/../_Users_bytedance_php_testWorkerman_index.php.pid%
        //然后为static::$pidFile 赋值
        if (empty(static::$pidFile)) {
            static::$pidFile = __DIR__ . "/../$unique_prefix.pid";
        }

        // Log file.
        // 初始化静态变量$logFile为目标路径
        // 并在目标路径[workerman文件夹的外层]创建workerman.log的日志
        if (empty(static::$logFile)) {
            static::$logFile = __DIR__ . '/../workerman.log';
        }


        //如果日志文件不存在,则创建之并授予0622权限
        $log_file = (string)static::$logFile;
        if (!\is_file($log_file)) {
            \touch($log_file);
            \chmod($log_file, 0622);
        }

        // State.
        // master进程的状态初始化为starting(正在启动中)
        static::$_status = static::STATUS_STARTING;

        // For statistics.
        //全局的static变量,记录启动时的unix时间戳
        static::$_globalStatistics['start_timestamp'] = \time();
        //产生临时目录,用于存放$unique_prefix.status文件,该文件主要用于记录进程的信息
        static::$_statisticsFile                      = \sys_get_temp_dir() . "/$unique_prefix.status";

        // Process title.
        // 设置进程名称,默认为WorkerMan
        static::setProcessTitle(static::$processTitle . ': master process  start_file=' . static::$_startFile);

        // Init data for worker id.
        // 为每个worker实例初始化$_idMap[每个worker是一个进程组,提供特定服务[http,tcp,udp等],每组可以有启动一个及一个以上的进程]
        static::initId();

        // Timer init.
        // 初始化定时器
        Timer::init();
    }

    /**
     * Lock.
     *
     * @return void
     */
    protected static function lock()
    {
        $fd = \fopen(static::$_startFile, 'r');
        if ($fd && !flock($fd, LOCK_EX)) {
            static::log('Workerman['.static::$_startFile.'] already running.');
            exit;
        }
    }

    /**
     * Unlock.
     *
     * @return void
     */
    protected static function unlock()
    {
        $fd = \fopen(static::$_startFile, 'r');
        $fd && flock($fd, \LOCK_UN);
    }

    /**
     * Init All worker instances.
     *
     * @return void
     */
    protected static function initWorkers()
    {
        if (static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }
        foreach (static::$_workers as $worker) {
            // Worker name.
            if (empty($worker->name)) {
                $worker->name = 'none';
            }

            // Get unix user of the worker process.
            if (empty($worker->user)) {
                $worker->user = static::getCurrentUser();
            } else {
                if (\posix_getuid() !== 0 && $worker->user !== static::getCurrentUser()) {
                    static::log('Warning: You must have the root privileges to change uid and gid.');
                }
            }

            // Socket name.
            $worker->socket = $worker->getSocketName();

            // Status name.
            $worker->status = '<g> [OK] </g>';

            // Get column mapping for UI
            foreach(static::getUiColumns() as $column_name => $prop){
                !isset($worker->{$prop}) && $worker->{$prop} = 'NNNN';
                $prop_length = \strlen($worker->{$prop});
                $key = '_max' . \ucfirst(\strtolower($column_name)) . 'NameLength';
                static::$$key = \max(static::$$key, $prop_length);
            }

            // Listen.
            if (!$worker->reusePort) {
                $worker->listen();
            }
        }
    }

    /**
     * Reload all worker instances.
     *
     * @return void
     */
    public static function reloadAllWorkers()
    {
        static::init();
        static::initWorkers();
        static::displayUI();
        static::$_status = static::STATUS_RELOADING;
    }

    /**
     * Get all worker instances.
     *
     * @return array
     */
    public static function getAllWorkers()
    {
        return static::$_workers;
    }

    /**
     * Get global event-loop instance.
     *
     * @return EventInterface
     */
    public static function getEventLoop()
    {
        return static::$globalEvent;
    }

    /**
     * Get main socket resource
     * @return resource
     */
    public function getMainSocket(){
        return $this->_mainSocket;
    }

    /**
     * Init idMap.
     * return void
     */
    protected static function initId()
    {
        //每个worker实例其实是一个提供了服务的进程组
        //worker->count 代表着该组中进程的数目
        //这里其实是为每个组初始化了组中count个进程的pid为0[如果均尚未被初始化]
        foreach (static::$_workers as $worker_id => $worker) {
            $new_id_map = array();
            $worker->count = $worker->count < 1 ? 1 : $worker->count;
            for($key = 0; $key < $worker->count; $key++) {
                $new_id_map[$key] = isset(static::$_idMap[$worker_id][$key]) ? static::$_idMap[$worker_id][$key] : 0;
            }
            static::$_idMap[$worker_id] = $new_id_map;
        }
    }

    /**
     * Get unix user of current porcess.
     *
     * @return string
     */
    protected static function getCurrentUser()
    {
        $user_info = \posix_getpwuid(\posix_getuid());
        return $user_info['name'];
    }

    /**
     * Display staring UI.
     *
     * @return void
     */
    protected static function displayUI()
    {
        global $argv;
        if (\in_array('-q', $argv)) {
            return;
        }
        if (static::$_OS !== \OS_TYPE_LINUX) {
            static::safeEcho("----------------------- WORKERMAN -----------------------------\r\n");
            static::safeEcho('Workerman version:'. static::VERSION. '          PHP version:'. \PHP_VERSION. "\r\n");
            static::safeEcho("------------------------ WORKERS -------------------------------\r\n");
            static::safeEcho("worker               listen                              processes status\r\n");
            return;
        }

        //show version
        $line_version = 'Workerman version:' . static::VERSION . \str_pad('PHP version:', 22, ' ', \STR_PAD_LEFT) . \PHP_VERSION . \PHP_EOL;
        !\defined('LINE_VERSIOIN_LENGTH') && \define('LINE_VERSIOIN_LENGTH', \strlen($line_version));
        $total_length = static::getSingleLineTotalLength();
        $line_one = '<n>' . \str_pad('<w> WORKERMAN </w>', $total_length + \strlen('<w></w>'), '-', \STR_PAD_BOTH) . '</n>'. \PHP_EOL;
        $line_two = \str_pad('<w> WORKERS </w>' , $total_length  + \strlen('<w></w>'), '-', \STR_PAD_BOTH) . \PHP_EOL;
        static::safeEcho($line_one . $line_version . $line_two);

        //Show title
        $title = '';
        foreach(static::getUiColumns() as $column_name => $prop){
            $key = '_max' . \ucfirst(\strtolower($column_name)) . 'NameLength';
            //just keep compatible with listen name
            $column_name === 'socket' && $column_name = 'listen';
            $title.= "<w>{$column_name}</w>"  .  \str_pad('', static::$$key + static::UI_SAFE_LENGTH - \strlen($column_name));
        }
        $title && static::safeEcho($title . \PHP_EOL);

        //Show content
        foreach (static::$_workers as $worker) {
            $content = '';
            foreach(static::getUiColumns() as $column_name => $prop){
                $key = '_max' . \ucfirst(\strtolower($column_name)) . 'NameLength';
                \preg_match_all("/(<n>|<\/n>|<w>|<\/w>|<g>|<\/g>)/is", $worker->{$prop}, $matches);
                $place_holder_length = !empty($matches) ? \strlen(\implode('', $matches[0])) : 0;
                $content .= \str_pad($worker->{$prop}, static::$$key + static::UI_SAFE_LENGTH + $place_holder_length);
            }
            $content && static::safeEcho($content . \PHP_EOL);
        }

        //Show last line
        $line_last = \str_pad('', static::getSingleLineTotalLength(), '-') . \PHP_EOL;
        !empty($content) && static::safeEcho($line_last);

        if (static::$daemonize) {
            foreach ($argv as $index => $value) {
                if ($value == '-d') {
                    unset($argv[$index]);
                } elseif ($value == 'start' || $value == 'restart') {
                    $argv[$index] = 'stop';
                }
            }
            static::safeEcho("Input \"php ".implode(' ', $argv)."\" to stop. Start success.\n\n");
        } else {
            static::safeEcho("Press Ctrl+C to stop. Start success.\n");
        }
    }

    /**
     * Get UI columns to be shown in terminal
     *
     * 1. $column_map: array('ui_column_name' => 'clas_property_name')
     * 2. Consider move into configuration in future
     *
     * @return array
     */
    public static function getUiColumns()
    {
        return array(
            'proto'     =>  'transport',
            'user'      =>  'user',
            'worker'    =>  'name',
            'socket'    =>  'socket',
            'processes' =>  'count',
            'status'    =>  'status',
        );
    }

    /**
     * Get single line total length for ui
     *
     * @return int
     */
    public static function getSingleLineTotalLength()
    {
        $total_length = 0;

        foreach(static::getUiColumns() as $column_name => $prop){
            $key = '_max' . \ucfirst(\strtolower($column_name)) . 'NameLength';
            $total_length += static::$$key + static::UI_SAFE_LENGTH;
        }

        //keep beauty when show less colums
        !\defined('LINE_VERSIOIN_LENGTH') && \define('LINE_VERSIOIN_LENGTH', 0);
        $total_length <= LINE_VERSIOIN_LENGTH && $total_length = LINE_VERSIOIN_LENGTH;

        return $total_length;
    }

    /**
     * Parse command.
     *
     * @return void
     */
    protected static function parseCommand()
    {
        //只有linux系统才需要解析命令行参数
        if (static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }
        //sapi为cli,入参会保存在$argv
        //以执行命令为 php index.php start 为例子
        //$argv为
        /**
            Array
            (
            [0] => index.php
            [1] => start
            )

         */
        global $argv;
        // Check argv;
        //取得启动文件为 index.php
        $start_file = $argv[0];
        //todo 使用$start_file取代yourfile,优化提示
        //$usage = "Usage: php yourfile <command> [mode]\nCommands: \nstart\t\tStart worker in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop worker.\n\t\tUse mode -g to stop gracefully.\nrestart\t\tRestart workers.\n\t\tUse mode -d to start in DAEMON mode.\n\t\tUse mode -g to stop gracefully.\nreload\t\tReload codes.\n\t\tUse mode -g to reload gracefully.\nstatus\t\tGet worker status.\n\t\tUse mode -d to show live status.\nconnections\tGet worker connections.\n";
        $usage = "Usage: php $start_file <command> [mode]\nCommands: \nstart\t\tStart worker in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop worker.\n\t\tUse mode -g to stop gracefully.\nrestart\t\tRestart workers.\n\t\tUse mode -d to start in DAEMON mode.\n\t\tUse mode -g to stop gracefully.\nreload\t\tReload codes.\n\t\tUse mode -g to reload gracefully.\nstatus\t\tGet worker status.\n\t\tUse mode -d to show live status.\nconnections\tGet worker connections.\n";
        $available_commands = array(
            'start',
            'stop',
            'restart',
            'reload',
            'status',
            'connections',
        );
        $available_mode = array(
            '-d',
            '-g'
        );
        $command = $mode = '';
        //逐个解析参数
        //如果数组元素在$available_commands中,则赋值变量给$available_commands
        //如果数组元素在$available_mode中,则赋值变量给$mode
        foreach ($argv as $value) {
            if (\in_array($value, $available_commands)) {
                $command = $value;
            } elseif (\in_array($value, $available_mode)) {
                $mode = $value;
            }
        }

        //没有匹配到哪怕一个command则退出,并输出usage
        if (!$command) {
            exit($usage);
        }

        // Start command.
        $mode_str = '';
        //如果是启动命令,根据模式选择是守护进程还是debug模式
        if ($command === 'start') {
            if ($mode === '-d' || static::$daemonize) {
                $mode_str = 'in DAEMON mode';
            } else {
                $mode_str = 'in DEBUG mode';
            }
        }
        //打印模式
        static::log("Workerman[$start_file] $command $mode_str");

        // Get master process PID.
        //判断存储master进程pid的文件是否存在,存在则读取赋值,不存在则初始化为0
        $master_pid      = \is_file(static::$pidFile) ? \file_get_contents(static::$pidFile) : 0;

        //如果已经存在了 $master_pid[非0]。
        //首先根据 $master_pid 杀死老进程[防止重复启动]

        //只有同时满足三个条件才认为master进程still alive
        //1.master_pid存在[已经生成了master_pid文件]
        //2.posix_kill($master_pid,0),探测当前进程的状态,该进程仍存在内存中则返回true,否则返回false
        //3.当前进程的pid不等于$master_pid进程[老进程]
        $master_is_alive = $master_pid && \posix_kill($master_pid, 0) && \posix_getpid() !== $master_pid;

        // Master is still alive?
        if ($master_is_alive) {
            //如果进程已经启动,则不允许重复启动
            if ($command === 'start') {
                static::log("Workerman[$start_file] already running");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            //如果进程没有启动,那么只能执行start和restart的command
            static::log("Workerman[$start_file] not run");
            exit;
        }

        // execute command.
        //开始执行command
        switch ($command) {
            //启动命令
            case 'start':
                //如果mode为-d,静态变量守护标志位标注为true
                if ($mode === '-d') {
                    static::$daemonize = true;
                }
                break;
            case 'status':
                //如果为查看状态的命令
                while (1) {
                    //如果记录进程状态的文件已经存在,则删除之
                    //和connection命令共享一个日志文件
                    //所以需要判存在+预删除
                    if (\is_file(static::$_statisticsFile)) {
                        @\unlink(static::$_statisticsFile);
                    }
                    // Master process will send SIGUSR2 signal to all child processes.
                    // 向master进程发送SIGUSR2信号[是否会转发给其子进程]
                    \posix_kill($master_pid, SIGUSR2);
                    // Sleep 1 second.
                    // 等待子进程处理
                    \sleep(1);
                    // Clear terminal.
                    if ($mode === '-d') {
                        static::safeEcho("\33[H\33[2J\33(B\33[m", true);
                    }
                    // Echo status data.
                    /**
                     * 格式化打印进程当前的状态信息
                     * ----------------------------------------------GLOBAL STATUS----------------------------------------------------
                        Workerman version:4.0.18          PHP version:7.4.11
                        start time:2021-01-09 07:39:28   run 0 days 0 hours
                        load average: 0.81, 2, 3         event-loop:\Workerman\Events\Select
                        1 workers       4 processes
                        worker_name  exit_status      exit_count
                        none         0                0
                        ----------------------------------------------PROCESS STATUS---------------------------------------------------
                        pid	memory  listening           worker_name  connections send_fail timers  total_request qps    status
                        54598	4M      http://0.0.0.0:2345 none         0           0         0       0             0      [idle]
                        54600	4M      http://0.0.0.0:2345 none         0           0         0       0             0      [idle]
                        54601	4M      http://0.0.0.0:2345 none         0           0         0       0             0      [idle]
                        54602	4M      http://0.0.0.0:2345 none         0           0         0       0             0      [idle]
                        ----------------------------------------------PROCESS STATUS---------------------------------------------------
                        Summary	16M     -                   -            0           0         0       0             0      [Summary]
                     */
                    static::safeEcho(static::formatStatusData());
                    if ($mode !== '-d') {
                        exit(0);
                    }
                    static::safeEcho("\nPress Ctrl+C to quit.\n\n");
                }
                exit(0);
            case 'connections':
                //该命令为查看workerman应用维护的所有连接信息
                /**
                 * --------------------------------------------------------------------- WORKERMAN CONNECTION STATUS --------------------------------------------------------------------------------
                    PID      Worker          CID       Trans   Protocol        ipv4   ipv6   Recv-Q       Send-Q       Bytes-R      Bytes-W       Status         Local Address          Foreign Address
                    54600    none            2         tcp     Http             1      0     0B           0B           9KB          528B          ESTABLISHED    127.0.0.1:2345         127.0.0.1:57239
                    54602    none            1         tcp     Http             1      0     0B           0B           420B         132B          ESTABLISHED    127.0.0.1:2345         127.0.0.1:57502
                    54602    none            2         tcp     Http             1      0     0B           0B           374B         132B          ESTABLISHED    127.0.0.1:2345         127.0.0.1:57504
                 */
                //如果存在static::$_statisticsFile这个临时文件且可写,则先清理这个文件
                //和status命令共享一个日志文件
                //所以需要判存在+预删除
                if (\is_file(static::$_statisticsFile) && \is_writable(static::$_statisticsFile)) {
                    \unlink(static::$_statisticsFile);
                }
                // Master process will send SIGIO signal to all child processes.
                //给master进程发送SIGIO信号,那么子进程也会收到SIGIO信号
                \posix_kill($master_pid, SIGIO);
                // Waiting amoment.
                // 等500000微秒,即是0.5s
                // 此处和上边status命令的等待一样,都是为了给子进程足够的时间写入文件
                \usleep(500000);
                // Display statisitcs data from a disk file.
                //因为此处记录的都是普通文本,固直接打开即可
                if(\is_readable(static::$_statisticsFile)) {
                    \readfile(static::$_statisticsFile);
                }
                exit(0);
            //解析restart命令
            case 'restart':
            //解析stop命令
            case 'stop':
                //如果当前运行mode为-g 即是指定了优雅退出模式
                if ($mode === '-g') {
                    //设置静态全局变量 $_gracefulStop = true;
                    static::$_gracefulStop = true;
                    //设置即将发送的信号为SIGHUP
                    $sig = \SIGHUP;
                    //优雅退出
                    static::log("Workerman[$start_file] is gracefully stopping ...");
                } else {
                    static::$_gracefulStop = false;
                    //设置即将发送的信号为SIGINT
                    $sig = \SIGINT;
                    static::log("Workerman[$start_file] is stopping ...");
                }
                // Send stop signal to master process.
                // 如果$master_pid存在,则向master发送信号,子进程也会接受到相同的信号
                $master_pid && \posix_kill($master_pid, $sig);
                // Timeout.
                $timeout    = 5;
                $start_time = \time();
                // Check master process is still alive?
                // 循环检测master进程是否仍然存在
                while (1) {
                    $master_is_alive = $master_pid && \posix_kill($master_pid, 0);
                    if ($master_is_alive) {
                        // Timeout?
                        // 如果不是优雅退出模式,而且当前时间减去开始执行退出命令的时间大于$timeout
                        // 说明退出的过程中出现了异常,打印日志后退出本次脚本的执行
                        if (!static::$_gracefulStop && \time() - $start_time >= $timeout) {
                            static::log("Workerman[$start_file] stop fail");
                            exit;
                        }
                        // Waiting amoment.
                        // 等10000微秒后重新进入循环
                        \usleep(10000);
                        continue;
                    }
                    // Stop success.
                    // 如果master_pid进程已经被杀死,则说明退出成功
                    static::log("Workerman[$start_file] stop success");
                    //如果当前命令是stop直接退出当前脚本[执行php index.php脚本也会产生一个cli进程]
                    //todo 这里就是restart命令和stop命令的分歧点,如果是stop命令会直接使得脚本进程退出
                    //todo 如果是restart命令,则脚本进程会直接执行 worker::runAll()中parseCommand后续的其他方法
                    if ($command === 'stop') {
                        exit(0);
                    }
                    //如果指定的模式为-d则设置静态变量$daemonize为true
                    if ($mode === '-d') {
                        static::$daemonize = true;
                    }
                    break;
                }
                break;
            case 'reload':
                //根据是否是优雅重启模式区分要发送给master进程的信号
                if($mode === '-g'){
                    $sig = \SIGQUIT;
                }else{
                    $sig = \SIGUSR1;
                }
                \posix_kill($master_pid, $sig);
                exit;
            default :
                //不支持的命令打印错误后退出
                if (isset($command)) {
                    static::safeEcho('Unknown command: ' . $command . "\n");
                }
                //打印$usage
                exit($usage);
        }
    }

    /**
     * Format status data.
     *
     * @return string
     */
    protected static function formatStatusData()
    {
        //todo 未知其作用
        static $total_request_cache = array();
        //如果存放当前进程状态的文件不可读,返回空
        if (!\is_readable(static::$_statisticsFile)) {
            return '';
        }
        //按EOL换行符分割文本内容成数组
        $info = \file(static::$_statisticsFile, \FILE_IGNORE_NEW_LINES);
        if (!$info) {
            return '';
        }
        $status_str = '';
        //所有进程当前总请求数的计数map
        //todo 未知其作用
        $current_total_request = array();
        //反序列化
        /**
         * Array
        (
            [54598] => Array
            (
                [name] => none
                [listen] => http://0.0.0.0:2345
            )

            [54600] => Array
            (
                [name] => none
                [listen] => http://0.0.0.0:2345
            )

            [54601] => Array
            (
                [name] => none
                [listen] => http://0.0.0.0:2345
            )

            [54602] => Array
            (
                [name] => none
                [listen] => http://0.0.0.0:2345
            )
        )
         */
        $worker_info = \unserialize($info[0]);
        //按照pid从小到大,对$worker_info数组按照key进行升序排序
        \ksort($worker_info, SORT_NUMERIC);
        //把第一个元素从info数组中删除
        unset($info[0]);
        $data_waiting_sort = array();
        $read_process_status = false;
        $total_requests = 0;
        $total_qps = 0;
        $total_connections = 0;
        $total_fails = 0;
        $total_memory = 0;
        $total_timers = 0;
        $maxLen1 = static::$_maxSocketNameLength;
        $maxLen2 = static::$_maxWorkerNameLength;
        foreach($info as $key => $value) {
            if (!$read_process_status) {
                $status_str .= $value . "\n";
                //匹配到包含 ^pid memory  listening 样式的文本,说明下边的元素都是进程相关的信息
                if (\preg_match('/^pid.*?memory.*?listening/', $value)) {
                    $read_process_status = true;
                }
                continue;
            }
            //开始匹配进程相关的文本行
            ///^[0-9]+/ 以数字开头,必须出现一个或者多个数字(至少一个)
            if(\preg_match('/^[0-9]+/', $value, $pid_math)) {
                //成功匹配到了pid
                $pid = $pid_math[0];
                //把当前遍历到的文本行以$pid为key建立map
                $data_waiting_sort[$pid] = $value;
                //匹配所有前后带[一个或者多个]空格的文本行
                if(\preg_match('/^\S+?\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?/', $value, $match)) {
                    /**
                     * Array
                        (
                            [0] => 54600	4M      http://0.0.0.0:2345 none         0           0         0       0
                            [1] => 4M
                            [2] => http://0.0.0.0:2345
                            [3] => none
                            [4] => 0
                            [5] => 0
                            [6] => 0
                            [7] => 0
                        )
                     */
                    //记录使用的总内存量,替换掉M字符后累加数字
                    $total_memory += \intval(\str_ireplace('M','',$match[1]));
                    //返回当前长度和设置的socket文本字符串描述的长度static::$_maxSocketNameLength的较大值
                    $maxLen1 = \max($maxLen1,\strlen($match[2]));
                    //返回当前长度和设置的worker名称字符串描述的长度static::$_maxWorkerNameLength的较大值
                    $maxLen2 = \max($maxLen2,\strlen($match[3]));
                    //累加每个进程当前的总连接数
                    $total_connections += \intval($match[4]);
                    //累加每个进程的总失败数
                    $total_fails += \intval($match[5]);
                    //累加每个进程的定时器数目
                    $total_timers += \intval($match[6]);
                    //累加每个进程当前正在处理的请求数
                    $current_total_request[$pid] = $match[7];
                    //累加每个进程处理过的请求数
                    $total_requests += \intval($match[7]);
                }
            }
        }

        //遍历已经反序列化的worker_info数组
        foreach($worker_info as $pid => $info) {
            //如果该pid不存在于$data_waiting_sort中
            if (!isset($data_waiting_sort[$pid])) {
                //则为其填充默认配置
                $status_str .= "$pid\t" . \str_pad('N/A', 7) . " "
                    . \str_pad($info['listen'], static::$_maxSocketNameLength) . " "
                    . \str_pad($info['name'], static::$_maxWorkerNameLength) . " "
                    . \str_pad('N/A', 11) . " " . \str_pad('N/A', 9) . " "
                    . \str_pad('N/A', 7) . " " . \str_pad('N/A', 13) . " N/A    [busy] \n";
                continue;
            }
            //$qps = isset($total_request_cache[$pid]) ? $current_total_request[$pid]
            if (!isset($total_request_cache[$pid]) || !isset($current_total_request[$pid])) {
                $qps = 0;
            } else {
                $qps = $current_total_request[$pid] - $total_request_cache[$pid];
                $total_qps += $qps;
            }
            $status_str .= $data_waiting_sort[$pid]. " " . \str_pad($qps, 6) ." [idle]\n";
        }
        //打印所有进程统计起来的信息
        $total_request_cache = $current_total_request;
        $status_str .= "----------------------------------------------PROCESS STATUS---------------------------------------------------\n";
        $status_str .= "Summary\t" . \str_pad($total_memory.'M', 7) . " "
            . \str_pad('-', $maxLen1) . " "
            . \str_pad('-', $maxLen2) . " "
            . \str_pad($total_connections, 11) . " " . \str_pad($total_fails, 9) . " "
            . \str_pad($total_timers, 7) . " " . \str_pad($total_requests, 13) . " "
            . \str_pad($total_qps,6)." [Summary] \n";
        return $status_str;
    }


    /**
     * Install signal handler.
     *
     * @return void
     */
    protected static function installSignal()
    {
        if (static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }
        $signalHandler = '\Workerman\Worker::signalHandler';
        // stop
        \pcntl_signal(\SIGINT, $signalHandler, false);
        // stop
        \pcntl_signal(\SIGTERM, $signalHandler, false);
        // graceful stop
        \pcntl_signal(\SIGHUP, $signalHandler, false);
        // reload
        \pcntl_signal(\SIGUSR1, $signalHandler, false);
        // graceful reload
        \pcntl_signal(\SIGQUIT, $signalHandler, false);
        // status
        \pcntl_signal(\SIGUSR2, $signalHandler, false);
        // connection status
        \pcntl_signal(\SIGIO, $signalHandler, false);
        // ignore
        \pcntl_signal(\SIGPIPE, \SIG_IGN, false);
    }

    /**
     * Reinstall signal handler.
     *
     * @return void
     */
    protected static function reinstallSignal()
    {
        if (static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }
        $signalHandler = '\Workerman\Worker::signalHandler';
        // uninstall stop signal handler
        \pcntl_signal(\SIGINT, \SIG_IGN, false);
        // uninstall stop signal handler
        \pcntl_signal(\SIGTERM, \SIG_IGN, false);
        // uninstall graceful stop signal handler
        \pcntl_signal(\SIGHUP, \SIG_IGN, false);
        // uninstall reload signal handler
        \pcntl_signal(\SIGUSR1, \SIG_IGN, false);
        // uninstall graceful reload signal handler
        \pcntl_signal(\SIGQUIT, \SIG_IGN, false);
        // uninstall status signal handler
        \pcntl_signal(\SIGUSR2, \SIG_IGN, false);
        // uninstall connections status signal handler
        \pcntl_signal(\SIGIO, \SIG_IGN, false);
        // reinstall stop signal handler
        static::$globalEvent->add(\SIGINT, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall graceful stop signal handler
        static::$globalEvent->add(\SIGHUP, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall reload signal handler
        static::$globalEvent->add(\SIGUSR1, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall graceful reload signal handler
        static::$globalEvent->add(\SIGQUIT, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall status signal handler
        static::$globalEvent->add(\SIGUSR2, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall connection status signal handler
        static::$globalEvent->add(\SIGIO, EventInterface::EV_SIGNAL, $signalHandler);
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     */
    public static function signalHandler($signal)
    {
        switch ($signal) {
            // Stop.
            case \SIGINT:
            case \SIGTERM:
                static::$_gracefulStop = false;
                static::stopAll();
                break;
            // Graceful stop.
            case \SIGHUP:
                static::$_gracefulStop = true;
                static::stopAll();
                break;
            // Reload.
            case \SIGQUIT:
            case \SIGUSR1:
                static::$_gracefulStop = $signal === \SIGQUIT;
                static::$_pidsToRestart = static::getAllWorkerPids();
                static::reload();
                break;
            // Show status.
            case \SIGUSR2:
                static::writeStatisticsToStatusFile();
                break;
            // Show connection status.
            case \SIGIO:
                static::writeConnectionsStatisticsToStatusFile();
                break;
        }
    }

    /**
     * Run as deamon mode.
     *
     * @throws Exception
     */
    protected static function daemonize()
    {
        if (!static::$daemonize || static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }
        \umask(0);
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new Exception('Fork fail');
        } elseif ($pid > 0) {
            exit(0);
        }
        if (-1 === \posix_setsid()) {
            throw new Exception("Setsid fail");
        }
        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new Exception("Fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }
    }

    /**
     * Redirect standard input and output.
     *
     * @throws Exception
     */
    public static function resetStd()
    {
        if (!static::$daemonize || static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }
        global $STDOUT, $STDERR;
        $handle = \fopen(static::$stdoutFile, "a");
        if ($handle) {
            unset($handle);
            \set_error_handler(function(){});
            if ($STDOUT) {
                \fclose($STDOUT);
            }
            if ($STDERR) {
                \fclose($STDERR);
            }
            \fclose(\STDOUT);
            \fclose(\STDERR);
            $STDOUT = \fopen(static::$stdoutFile, "a");
            $STDERR = \fopen(static::$stdoutFile, "a");
            // change output stream
            static::$_outputStream = null;
            static::outputStream($STDOUT);
            \restore_error_handler();
            return;
        }

        throw new Exception('Can not open stdoutFile ' . static::$stdoutFile);
    }

    /**
     * Save pid.
     *
     * @throws Exception
     */
    protected static function saveMasterPid()
    {
        if (static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }

        static::$_masterPid = \posix_getpid();
        if (false === \file_put_contents(static::$pidFile, static::$_masterPid)) {
            throw new Exception('can not save pid to ' . static::$pidFile);
        }
    }

    /**
     * Get event loop name.
     *
     * @return string
     */
    protected static function getEventLoopName()
    {
        if (static::$eventLoopClass) {
            return static::$eventLoopClass;
        }

        if (!\class_exists('\Swoole\Event', false)) {
            unset(static::$_availableEventLoops['swoole']);
        }

        $loop_name = '';
        foreach (static::$_availableEventLoops as $name=>$class) {
            if (\extension_loaded($name)) {
                $loop_name = $name;
                break;
            }
        }

        if ($loop_name) {
            if (\interface_exists('\React\EventLoop\LoopInterface')) {
                switch ($loop_name) {
                    case 'libevent':
                        static::$eventLoopClass = '\Workerman\Events\React\ExtLibEventLoop';
                        break;
                    case 'event':
                        static::$eventLoopClass = '\Workerman\Events\React\ExtEventLoop';
                        break;
                    default :
                        static::$eventLoopClass = '\Workerman\Events\React\StreamSelectLoop';
                        break;
                }
            } else {
                static::$eventLoopClass = static::$_availableEventLoops[$loop_name];
            }
        } else {
            static::$eventLoopClass = \interface_exists('\React\EventLoop\LoopInterface') ? '\Workerman\Events\React\StreamSelectLoop' : '\Workerman\Events\Select';
        }
        return static::$eventLoopClass;
    }

    /**
     * Get all pids of worker processes.
     *
     * @return array
     */
    protected static function getAllWorkerPids()
    {
        $pid_array = array();
        foreach (static::$_pidMap as $worker_pid_array) {
            foreach ($worker_pid_array as $worker_pid) {
                $pid_array[$worker_pid] = $worker_pid;
            }
        }
        return $pid_array;
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     */
    protected static function forkWorkers()
    {
        if (static::$_OS === \OS_TYPE_LINUX) {
            static::forkWorkersForLinux();
        } else {
            static::forkWorkersForWindows();
        }
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     */
    protected static function forkWorkersForLinux()
    {

        foreach (static::$_workers as $worker) {
            if (static::$_status === static::STATUS_STARTING) {
                if (empty($worker->name)) {
                    $worker->name = $worker->getSocketName();
                }
                $worker_name_length = \strlen($worker->name);
                if (static::$_maxWorkerNameLength < $worker_name_length) {
                    static::$_maxWorkerNameLength = $worker_name_length;
                }
            }

            while (\count(static::$_pidMap[$worker->workerId]) < $worker->count) {
                static::forkOneWorkerForLinux($worker);
            }
        }
    }

    /**
     * Fork some worker processes.
     *
     * @return void
     */
    protected static function forkWorkersForWindows()
    {
        $files = static::getStartFilesForWindows();
        global $argv;
        if(\in_array('-q', $argv) || \count($files) === 1)
        {
            if(\count(static::$_workers) > 1)
            {
                static::safeEcho("@@@ Error: multi workers init in one php file are not support @@@\r\n");
                static::safeEcho("@@@ See http://doc.workerman.net/faq/multi-woker-for-windows.html @@@\r\n");
            }
            elseif(\count(static::$_workers) <= 0)
            {
                exit("@@@no worker inited@@@\r\n\r\n");
            }

            \reset(static::$_workers);
            /** @var Worker $worker */
            $worker = current(static::$_workers);

            // Display UI.
            static::safeEcho(\str_pad($worker->name, 21) . \str_pad($worker->getSocketName(), 36) . \str_pad($worker->count, 10) . "[ok]\n");
            $worker->listen();
            $worker->run();
            exit("@@@child exit@@@\r\n");
        }
        else
        {
            static::$globalEvent = new \Workerman\Events\Select();
            Timer::init(static::$globalEvent);
            foreach($files as $start_file)
            {
                static::forkOneWorkerForWindows($start_file);
            }
        }
    }

    /**
     * Get start files for windows.
     *
     * @return array
     */
    public static function getStartFilesForWindows() {
        global $argv;
        $files = array();
        foreach($argv as $file)
        {
            if(\is_file($file))
            {
                $files[$file] = $file;
            }
        }
        return $files;
    }

    /**
     * Fork one worker process.
     *
     * @param string $start_file
     */
    public static function forkOneWorkerForWindows($start_file)
    {
        $start_file = \realpath($start_file);
        $std_file = \sys_get_temp_dir() . '/'.\str_replace(array('/', "\\", ':'), '_', $start_file).'.out.txt';

        $descriptorspec = array(
            0 => array('pipe', 'a'), // stdin
            1 => array('file', $std_file, 'w'), // stdout
            2 => array('file', $std_file, 'w') // stderr
        );


        $pipes       = array();
        $process     = \proc_open("php \"$start_file\" -q", $descriptorspec, $pipes);
        $std_handler = \fopen($std_file, 'a+');
        \stream_set_blocking($std_handler, false);

        if (empty(static::$globalEvent)) {
            static::$globalEvent = new Select();
            Timer::init(static::$globalEvent);
        }
        $timer_id = Timer::add(0.1, function()use($std_handler)
        {
            Worker::safeEcho(\fread($std_handler, 65535));
        });

        // 保存子进程句柄
        static::$_processForWindows[$start_file] = array($process, $start_file, $timer_id);
    }

    /**
     * check worker status for windows.
     * @return void
     */
    public static function checkWorkerStatusForWindows()
    {
        foreach(static::$_processForWindows as $process_data)
        {
            $process = $process_data[0];
            $start_file = $process_data[1];
            $timer_id = $process_data[2];
            $status = \proc_get_status($process);
            if(isset($status['running']))
            {
                if(!$status['running'])
                {
                    static::safeEcho("process $start_file terminated and try to restart\n");
                    Timer::del($timer_id);
                    \proc_close($process);
                    static::forkOneWorkerForWindows($start_file);
                }
            }
            else
            {
                static::safeEcho("proc_get_status fail\n");
            }
        }
    }


    /**
     * Fork one worker process.
     *
     * @param self $worker
     * @throws Exception
     */
    protected static function forkOneWorkerForLinux(self $worker)
    {
        // Get available worker id.
        $id = static::getId($worker->workerId, 0);
        if ($id === false) {
            return;
        }
        $pid = \pcntl_fork();
        // For master process.
        if ($pid > 0) {
            static::$_pidMap[$worker->workerId][$pid] = $pid;
            static::$_idMap[$worker->workerId][$id]   = $pid;
        } // For child processes.
        elseif (0 === $pid) {
            \srand();
            \mt_srand();
            if ($worker->reusePort) {
                $worker->listen();
            }
            if (static::$_status === static::STATUS_STARTING) {
                static::resetStd();
            }
            static::$_pidMap  = array();
            // Remove other listener.
            foreach(static::$_workers as $key => $one_worker) {
                if ($one_worker->workerId !== $worker->workerId) {
                    $one_worker->unlisten();
                    unset(static::$_workers[$key]);
                }
            }
            Timer::delAll();
            static::setProcessTitle(self::$processTitle . ': worker process  ' . $worker->name . ' ' . $worker->getSocketName());
            $worker->setUserAndGroup();
            $worker->id = $id;
            $worker->run();
            if (strpos(static::$eventLoopClass, 'Workerman\Events\Swoole') !== false) {
                exit(0);
            }
            $err = new Exception('event-loop exited');
            static::log($err);
            exit(250);
        } else {
            throw new Exception("forkOneWorker fail");
        }
    }

    /**
     * Get worker id.
     *
     * @param int $worker_id
     * @param int $pid
     *
     * @return integer
     */
    protected static function getId($worker_id, $pid)
    {
        return \array_search($pid, static::$_idMap[$worker_id]);
    }

    /**
     * Set unix user and group for current process.
     *
     * @return void
     */
    public function setUserAndGroup()
    {
        // Get uid.
        $user_info = \posix_getpwnam($this->user);
        if (!$user_info) {
            static::log("Warning: User {$this->user} not exsits");
            return;
        }
        $uid = $user_info['uid'];
        // Get gid.
        if ($this->group) {
            $group_info = \posix_getgrnam($this->group);
            if (!$group_info) {
                static::log("Warning: Group {$this->group} not exsits");
                return;
            }
            $gid = $group_info['gid'];
        } else {
            $gid = $user_info['gid'];
        }

        // Set uid and gid.
        if ($uid !== \posix_getuid() || $gid !== \posix_getgid()) {
            if (!\posix_setgid($gid) || !\posix_initgroups($user_info['name'], $gid) || !\posix_setuid($uid)) {
                static::log("Warning: change gid or uid fail.");
            }
        }
    }

    /**
     * Set process name.
     *
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle($title)
    {
        //解释一下,为什么在调用processTitle的时候需要先关闭最开始定义的错误处理器
        //并不是所有的os都支持修改进程名称,比如OS X。这个时候cli_set_process会返回false
        //同时返回如下错误
        //cli_set_process_title(): cli_set_process_title had an error: Not initialized correctly in file /Users/bytedance/php/testWorkerman/Workerman/Worker.php on line
        //改变进程名称在这里算是一件锦上添花的事情(使用ps命令可读性更高,也更容易grep)
        //但是并不是一件必须要完成的事情
        //所以我们希望他能完成就完成,不能完成就按照启动command作为进程名称,也不要报错,降低用户对程序的信任度和安全感
        //得到结论,非决定性错误或者外层需要根据错误情况修改执行流程的,都可以在函数内部自己消化,这样可以增加对调用方的透明度
        \set_error_handler(function(){});
        // >=php 5.5
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (\extension_loaded('proctitle') && \function_exists('setproctitle')) {
            \setproctitle($title);
        }
        //恢复自定义错我处理器
        \restore_error_handler();
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorWorkers()
    {
        if (static::$_OS === \OS_TYPE_LINUX) {
            static::monitorWorkersForLinux();
        } else {
            static::monitorWorkersForWindows();
        }
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorWorkersForLinux()
    {
        static::$_status = static::STATUS_RUNNING;
        while (1) {
            // Calls signal handlers for pending signals.
            \pcntl_signal_dispatch();
            // Suspends execution of the current process until a child has exited, or until a signal is delivered
            $status = 0;
            $pid    = \pcntl_wait($status, \WUNTRACED);
            // Calls signal handlers for pending signals again.
            \pcntl_signal_dispatch();
            // If a child has already exited.
            if ($pid > 0) {
                // Find out which worker process exited.
                foreach (static::$_pidMap as $worker_id => $worker_pid_array) {
                    if (isset($worker_pid_array[$pid])) {
                        $worker = static::$_workers[$worker_id];
                        // Exit status.
                        if ($status !== 0) {
                            static::log("worker[" . $worker->name . ":$pid] exit with status $status");
                        }

                        // For Statistics.
                        if (!isset(static::$_globalStatistics['worker_exit_info'][$worker_id][$status])) {
                            static::$_globalStatistics['worker_exit_info'][$worker_id][$status] = 0;
                        }
                        ++static::$_globalStatistics['worker_exit_info'][$worker_id][$status];

                        // Clear process data.
                        unset(static::$_pidMap[$worker_id][$pid]);

                        // Mark id is available.
                        $id                              = static::getId($worker_id, $pid);
                        static::$_idMap[$worker_id][$id] = 0;

                        break;
                    }
                }
                // Is still running state then fork a new worker process.
                if (static::$_status !== static::STATUS_SHUTDOWN) {
                    static::forkWorkers();
                    // If reloading continue.
                    if (isset(static::$_pidsToRestart[$pid])) {
                        unset(static::$_pidsToRestart[$pid]);
                        static::reload();
                    }
                }
            }

            // If shutdown state and all child processes exited then master process exit.
            if (static::$_status === static::STATUS_SHUTDOWN && !static::getAllWorkerPids()) {
                static::exitAndClearAll();
            }
        }
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorWorkersForWindows()
    {
        Timer::add(1, "\\Workerman\\Worker::checkWorkerStatusForWindows");

        static::$globalEvent->loop();
    }

    /**
     * Exit current process.
     *
     * @return void
     */
    protected static function exitAndClearAll()
    {
        foreach (static::$_workers as $worker) {
            $socket_name = $worker->getSocketName();
            if ($worker->transport === 'unix' && $socket_name) {
                list(, $address) = \explode(':', $socket_name, 2);
                @\unlink($address);
            }
        }
        @\unlink(static::$pidFile);
        static::log("Workerman[" . \basename(static::$_startFile) . "] has been stopped");
        if (static::$onMasterStop) {
            \call_user_func(static::$onMasterStop);
        }
        exit(0);
    }

    /**
     * Execute reload.
     *
     * @return void
     */
    protected static function reload()
    {
        // For master process.
        if (static::$_masterPid === \posix_getpid()) {
            // Set reloading state.
            if (static::$_status !== static::STATUS_RELOADING && static::$_status !== static::STATUS_SHUTDOWN) {
                static::log("Workerman[" . \basename(static::$_startFile) . "] reloading");
                static::$_status = static::STATUS_RELOADING;
                // Try to emit onMasterReload callback.
                if (static::$onMasterReload) {
                    try {
                        \call_user_func(static::$onMasterReload);
                    } catch (\Exception $e) {
                        static::log($e);
                        exit(250);
                    } catch (\Error $e) {
                        static::log($e);
                        exit(250);
                    }
                    static::initId();
                }
            }

            if (static::$_gracefulStop) {
                $sig = \SIGQUIT;
            } else {
                $sig = \SIGUSR1;
            }

            // Send reload signal to all child processes.
            $reloadable_pid_array = array();
            foreach (static::$_pidMap as $worker_id => $worker_pid_array) {
                $worker = static::$_workers[$worker_id];
                if ($worker->reloadable) {
                    foreach ($worker_pid_array as $pid) {
                        $reloadable_pid_array[$pid] = $pid;
                    }
                } else {
                    foreach ($worker_pid_array as $pid) {
                        // Send reload signal to a worker process which reloadable is false.
                        \posix_kill($pid, $sig);
                    }
                }
            }

            // Get all pids that are waiting reload.
            static::$_pidsToRestart = \array_intersect(static::$_pidsToRestart, $reloadable_pid_array);

            // Reload complete.
            if (empty(static::$_pidsToRestart)) {
                if (static::$_status !== static::STATUS_SHUTDOWN) {
                    static::$_status = static::STATUS_RUNNING;
                }
                return;
            }
            // Continue reload.
            $one_worker_pid = \current(static::$_pidsToRestart);
            // Send reload signal to a worker process.
            \posix_kill($one_worker_pid, $sig);
            // If the process does not exit after static::KILL_WORKER_TIMER_TIME seconds try to kill it.
            if(!static::$_gracefulStop){
                Timer::add(static::KILL_WORKER_TIMER_TIME, '\posix_kill', array($one_worker_pid, \SIGKILL), false);
            }
        } // For child processes.
        else {
            \reset(static::$_workers);
            $worker = \current(static::$_workers);
            // Try to emit onWorkerReload callback.
            if ($worker->onWorkerReload) {
                try {
                    \call_user_func($worker->onWorkerReload, $worker);
                } catch (\Exception $e) {
                    static::log($e);
                    exit(250);
                } catch (\Error $e) {
                    static::log($e);
                    exit(250);
                }
            }

            if ($worker->reloadable) {
                static::stopAll();
            }
        }
    }

    /**
     * Stop.
     *
     * @return void
     */
    public static function stopAll()
    {
        static::$_status = static::STATUS_SHUTDOWN;
        // For master process.
        if (static::$_masterPid === \posix_getpid()) {
            static::log("Workerman[" . \basename(static::$_startFile) . "] stopping ...");
            $worker_pid_array = static::getAllWorkerPids();
            // Send stop signal to all child processes.
            if (static::$_gracefulStop) {
                $sig = \SIGHUP;
            } else {
                $sig = \SIGINT;
            }
            foreach ($worker_pid_array as $worker_pid) {
                \posix_kill($worker_pid, $sig);
                if(!static::$_gracefulStop){
                    Timer::add(static::KILL_WORKER_TIMER_TIME, '\posix_kill', array($worker_pid, \SIGKILL), false);
                }
            }
            Timer::add(1, "\\Workerman\\Worker::checkIfChildRunning");
            // Remove statistics file.
            if (\is_file(static::$_statisticsFile)) {
                @\unlink(static::$_statisticsFile);
            }
        } // For child processes.
        else {
            // Execute exit.
            foreach (static::$_workers as $worker) {
                if(!$worker->stopping){
                    $worker->stop();
                    $worker->stopping = true;
                }
            }
            if (!static::$_gracefulStop || ConnectionInterface::$statistics['connection_count'] <= 0) {
                static::$_workers = array();
                if (static::$globalEvent) {
                    static::$globalEvent->destroy();
                }

                try {
                    exit(0);
                } catch (Exception $e) {

                }
            }
        }
    }

    /**
     * check if child processes is really running
     */
    public static function checkIfChildRunning()
    {
        foreach (static::$_pidMap as $worker_id => $worker_pid_array) {
            foreach ($worker_pid_array as $pid => $worker_pid) {
                if (!\posix_kill($pid, 0)) {
                    unset(static::$_pidMap[$worker_id][$pid]);
                }
            }
        }
    }

    /**
     * Get process status.
     *
     * @return number
     */
    public static function getStatus()
    {
        return static::$_status;
    }

    /**
     * If stop gracefully.
     *
     * @return bool
     */
    public static function getGracefulStop()
    {
        return static::$_gracefulStop;
    }

    /**
     * Write statistics data to disk.
     *
     * @return void
     */
    protected static function writeStatisticsToStatusFile()
    {
        // For master process.
        if (static::$_masterPid === \posix_getpid()) {
            $all_worker_info = array();
            foreach(static::$_pidMap as $worker_id => $pid_array) {
                /** @var /Workerman/Worker $worker */
                $worker = static::$_workers[$worker_id];
                foreach($pid_array as $pid) {
                    $all_worker_info[$pid] = array('name' => $worker->name, 'listen' => $worker->getSocketName());
                }
            }

            \file_put_contents(static::$_statisticsFile, \serialize($all_worker_info)."\n", \FILE_APPEND);
            $loadavg = \function_exists('sys_getloadavg') ? \array_map('round', \sys_getloadavg(), array(2)) : array('-', '-', '-');
            \file_put_contents(static::$_statisticsFile,
                "----------------------------------------------GLOBAL STATUS----------------------------------------------------\n", \FILE_APPEND);
            \file_put_contents(static::$_statisticsFile,
                'Workerman version:' . static::VERSION . "          PHP version:" . \PHP_VERSION . "\n", \FILE_APPEND);
            \file_put_contents(static::$_statisticsFile, 'start time:' . \date('Y-m-d H:i:s',
                    static::$_globalStatistics['start_timestamp']) . '   run ' . \floor((\time() - static::$_globalStatistics['start_timestamp']) / (24 * 60 * 60)) . ' days ' . \floor(((\time() - static::$_globalStatistics['start_timestamp']) % (24 * 60 * 60)) / (60 * 60)) . " hours   \n",
                FILE_APPEND);
            $load_str = 'load average: ' . \implode(", ", $loadavg);
            \file_put_contents(static::$_statisticsFile,
                \str_pad($load_str, 33) . 'event-loop:' . static::getEventLoopName() . "\n", \FILE_APPEND);
            \file_put_contents(static::$_statisticsFile,
                \count(static::$_pidMap) . ' workers       ' . \count(static::getAllWorkerPids()) . " processes\n",
                \FILE_APPEND);
            \file_put_contents(static::$_statisticsFile,
                \str_pad('worker_name', static::$_maxWorkerNameLength) . " exit_status      exit_count\n", \FILE_APPEND);
            foreach (static::$_pidMap as $worker_id => $worker_pid_array) {
                $worker = static::$_workers[$worker_id];
                if (isset(static::$_globalStatistics['worker_exit_info'][$worker_id])) {
                    foreach (static::$_globalStatistics['worker_exit_info'][$worker_id] as $worker_exit_status => $worker_exit_count) {
                        \file_put_contents(static::$_statisticsFile,
                            \str_pad($worker->name, static::$_maxWorkerNameLength) . " " . \str_pad($worker_exit_status,
                                16) . " $worker_exit_count\n", \FILE_APPEND);
                    }
                } else {
                    \file_put_contents(static::$_statisticsFile,
                        \str_pad($worker->name, static::$_maxWorkerNameLength) . " " . \str_pad(0, 16) . " 0\n",
                        \FILE_APPEND);
                }
            }
            \file_put_contents(static::$_statisticsFile,
                "----------------------------------------------PROCESS STATUS---------------------------------------------------\n",
                \FILE_APPEND);
            \file_put_contents(static::$_statisticsFile,
                "pid\tmemory  " . \str_pad('listening', static::$_maxSocketNameLength) . " " . \str_pad('worker_name',
                    static::$_maxWorkerNameLength) . " connections " . \str_pad('send_fail', 9) . " "
                . \str_pad('timers', 8) . \str_pad('total_request', 13) ." qps    status\n", \FILE_APPEND);

            \chmod(static::$_statisticsFile, 0722);

            foreach (static::getAllWorkerPids() as $worker_pid) {
                \posix_kill($worker_pid, \SIGUSR2);
            }
            return;
        }

        // For child processes.
        \reset(static::$_workers);
        /** @var \Workerman\Worker $worker */
        $worker            = current(static::$_workers);
        $worker_status_str = \posix_getpid() . "\t" . \str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 7)
            . " " . \str_pad($worker->getSocketName(), static::$_maxSocketNameLength) . " "
            . \str_pad(($worker->name === $worker->getSocketName() ? 'none' : $worker->name), static::$_maxWorkerNameLength)
            . " ";
        $worker_status_str .= \str_pad(ConnectionInterface::$statistics['connection_count'], 11)
            . " " .  \str_pad(ConnectionInterface::$statistics['send_fail'], 9)
            . " " . \str_pad(static::$globalEvent->getTimerCount(), 7)
            . " " . \str_pad(ConnectionInterface::$statistics['total_request'], 13) . "\n";
        \file_put_contents(static::$_statisticsFile, $worker_status_str, \FILE_APPEND);
    }

    /**
     * Write statistics data to disk.
     *
     * @return void
     */
    protected static function writeConnectionsStatisticsToStatusFile()
    {
        // For master process.
        if (static::$_masterPid === \posix_getpid()) {
            \file_put_contents(static::$_statisticsFile, "--------------------------------------------------------------------- WORKERMAN CONNECTION STATUS --------------------------------------------------------------------------------\n", \FILE_APPEND);
            \file_put_contents(static::$_statisticsFile, "PID      Worker          CID       Trans   Protocol        ipv4   ipv6   Recv-Q       Send-Q       Bytes-R      Bytes-W       Status         Local Address          Foreign Address\n", \FILE_APPEND);
            \chmod(static::$_statisticsFile, 0722);
            foreach (static::getAllWorkerPids() as $worker_pid) {
                \posix_kill($worker_pid, \SIGIO);
            }
            return;
        }

        // For child processes.
        $bytes_format = function($bytes)
        {
            if($bytes > 1024*1024*1024*1024) {
                return round($bytes/(1024*1024*1024*1024), 1)."TB";
            }
            if($bytes > 1024*1024*1024) {
                return round($bytes/(1024*1024*1024), 1)."GB";
            }
            if($bytes > 1024*1024) {
                return round($bytes/(1024*1024), 1)."MB";
            }
            if($bytes > 1024) {
                return round($bytes/(1024), 1)."KB";
            }
            return $bytes."B";
        };

        $pid = \posix_getpid();
        $str = '';
        \reset(static::$_workers);
        $current_worker = current(static::$_workers);
        $default_worker_name = $current_worker->name;

        /** @var \Workerman\Worker $worker */
        foreach(TcpConnection::$connections as $connection) {
            /** @var \Workerman\Connection\TcpConnection $connection */
            $transport      = $connection->transport;
            $ipv4           = $connection->isIpV4() ? ' 1' : ' 0';
            $ipv6           = $connection->isIpV6() ? ' 1' : ' 0';
            $recv_q         = $bytes_format($connection->getRecvBufferQueueSize());
            $send_q         = $bytes_format($connection->getSendBufferQueueSize());
            $local_address  = \trim($connection->getLocalAddress());
            $remote_address = \trim($connection->getRemoteAddress());
            $state          = $connection->getStatus(false);
            $bytes_read     = $bytes_format($connection->bytesRead);
            $bytes_written  = $bytes_format($connection->bytesWritten);
            $id             = $connection->id;
            $protocol       = $connection->protocol ? $connection->protocol : $connection->transport;
            $pos            = \strrpos($protocol, '\\');
            if ($pos) {
                $protocol = \substr($protocol, $pos+1);
            }
            if (\strlen($protocol) > 15) {
                $protocol = \substr($protocol, 0, 13) . '..';
            }
            $worker_name = isset($connection->worker) ? $connection->worker->name : $default_worker_name;
            if (\strlen($worker_name) > 14) {
                $worker_name = \substr($worker_name, 0, 12) . '..';
            }
            $str .= \str_pad($pid, 9) . \str_pad($worker_name, 16) .  \str_pad($id, 10) . \str_pad($transport, 8)
                . \str_pad($protocol, 16) . \str_pad($ipv4, 7) . \str_pad($ipv6, 7) . \str_pad($recv_q, 13)
                . \str_pad($send_q, 13) . \str_pad($bytes_read, 13) . \str_pad($bytes_written, 13) . ' '
                . \str_pad($state, 14) . ' ' . \str_pad($local_address, 22) . ' ' . \str_pad($remote_address, 22) ."\n";
        }
        if ($str) {
            \file_put_contents(static::$_statisticsFile, $str, \FILE_APPEND);
        }
    }

    /**
     * Check errors when current process exited.
     *
     * @return void
     */
    public static function checkErrors()
    {
        if (static::STATUS_SHUTDOWN !== static::$_status) {
            $error_msg = static::$_OS === \OS_TYPE_LINUX ? 'Worker['. \posix_getpid() .'] process terminated' : 'Worker process terminated';
            $errors    = error_get_last();
            if ($errors && ($errors['type'] === \E_ERROR ||
                    $errors['type'] === \E_PARSE ||
                    $errors['type'] === \E_CORE_ERROR ||
                    $errors['type'] === \E_COMPILE_ERROR ||
                    $errors['type'] === \E_RECOVERABLE_ERROR)
            ) {
                $error_msg .= ' with ERROR: ' . static::getErrorType($errors['type']) . " \"{$errors['message']} in {$errors['file']} on line {$errors['line']}\"";
            }
            static::log($error_msg);
        }
    }

    /**
     * Get error message by error code.
     *
     * @param integer $type
     * @return string
     */
    protected static function getErrorType($type)
    {
        if(isset(self::$_errorType[$type])) {
            return self::$_errorType[$type];
        }

        return '';
    }

    /**
     * Log.
     *
     * @param string $msg
     * @return void
     */
    public static function log($msg)
    {
        $msg = $msg . "\n";
        if (!static::$daemonize) {
            static::safeEcho($msg);
        }
        \file_put_contents((string)static::$logFile, \date('Y-m-d H:i:s') . ' ' . 'pid:'
            . (static::$_OS === \OS_TYPE_LINUX ? \posix_getpid() : 1) . ' ' . $msg, \FILE_APPEND | \LOCK_EX);
    }

    /**
     * Safe Echo.
     * @param string $msg
     * @param bool   $decorated
     * @return bool
     */
    public static function safeEcho($msg, $decorated = false)
    {
        $stream = static::outputStream();
        if (!$stream) {
            return false;
        }
        if (!$decorated) {
            $line = $white = $green = $end = '';
            if (static::$_outputDecorated) {
                $line = "\033[1A\n\033[K";
                $white = "\033[47;30m";
                $green = "\033[32;40m";
                $end = "\033[0m";
            }
            $msg = \str_replace(array('<n>', '<w>', '<g>'), array($line, $white, $green), $msg);
            $msg = \str_replace(array('</n>', '</w>', '</g>'), $end, $msg);
        } elseif (!static::$_outputDecorated) {
            return false;
        }
        \fwrite($stream, $msg);
        \fflush($stream);
        return true;
    }

    /**
     * @param null $stream
     * @return bool|resource
     */
    private static function outputStream($stream = null)
    {
        if (!$stream) {
            $stream = static::$_outputStream ? static::$_outputStream : \STDOUT;
        }
        if (!$stream || !\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            return false;
        }
        $stat = \fstat($stream);
        if (!$stat) {
            return false;
        }
        if (($stat['mode'] & 0170000) === 0100000) {
            // file
            static::$_outputDecorated = false;
        } else {
            static::$_outputDecorated =
                static::$_OS === \OS_TYPE_LINUX &&
                \function_exists('posix_isatty') &&
                \posix_isatty($stream);
        }
        return static::$_outputStream = $stream;
    }

    /**
     * Construct.
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct($socket_name = '', array $context_option = array())
    {
        // Save all worker instances.
        $this->workerId                    = \spl_object_hash($this);
        static::$_workers[$this->workerId] = $this;
        static::$_pidMap[$this->workerId]  = array();

        // Get autoload root path.
        $backtrace               = \debug_backtrace();
        $this->_autoloadRootPath = \dirname($backtrace[0]['file']);
        Autoloader::setRootPath($this->_autoloadRootPath);

        // Context for socket.
        if ($socket_name) {
            $this->_socketName = $socket_name;
            if (!isset($context_option['socket']['backlog'])) {
                $context_option['socket']['backlog'] = static::DEFAULT_BACKLOG;
            }
            $this->_context = \stream_context_create($context_option);
        }

        // Turn reusePort on.
        if (static::$_OS === \OS_TYPE_LINUX  // if linux
            && \version_compare(\PHP_VERSION,'7.0.0', 'ge') // if php >= 7.0.0
            && \strtolower(\php_uname('s')) !== 'darwin' // if not Mac OS
            && strpos($socket_name,'unix') !== 0) { // if not unix socket

            $this->reusePort = true;
        }
    }


    /**
     * Listen.
     *
     * @throws Exception
     */
    public function listen()
    {
        if (!$this->_socketName) {
            return;
        }

        // Autoload.
        Autoloader::setRootPath($this->_autoloadRootPath);

        if (!$this->_mainSocket) {

            $local_socket = $this->parseSocketAddress();

            // Flag.
            $flags = $this->transport === 'udp' ? \STREAM_SERVER_BIND : \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN;
            $errno = 0;
            $errmsg = '';
            // SO_REUSEPORT.
            if ($this->reusePort) {
                \stream_context_set_option($this->_context, 'socket', 'so_reuseport', 1);
            }

            // Create an Internet or Unix domain server socket.
            $this->_mainSocket = \stream_socket_server($local_socket, $errno, $errmsg, $flags, $this->_context);
            if (!$this->_mainSocket) {
                throw new Exception($errmsg);
            }

            if ($this->transport === 'ssl') {
                \stream_socket_enable_crypto($this->_mainSocket, false);
            } elseif ($this->transport === 'unix') {
                $socket_file = \substr($local_socket, 7);
                if ($this->user) {
                    \chown($socket_file, $this->user);
                }
                if ($this->group) {
                    \chgrp($socket_file, $this->group);
                }
            }

            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (\function_exists('socket_import_stream') && static::$_builtinTransports[$this->transport] === 'tcp') {
                \set_error_handler(function(){});
                $socket = \socket_import_stream($this->_mainSocket);
                \socket_set_option($socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                \socket_set_option($socket, \SOL_TCP, \TCP_NODELAY, 1);
                \restore_error_handler();
            }

            // Non blocking.
            \stream_set_blocking($this->_mainSocket, false);
        }

        $this->resumeAccept();
    }

    /**
     * Unlisten.
     *
     * @return void
     */
    public function unlisten() {
        $this->pauseAccept();
        if ($this->_mainSocket) {
            \set_error_handler(function(){});
            \fclose($this->_mainSocket);
            \restore_error_handler();
            $this->_mainSocket = null;
        }
    }

    /**
     * Parse local socket address.
     *
     * @throws Exception
     */
    protected function parseSocketAddress() {
        if (!$this->_socketName) {
            return;
        }
        // Get the application layer communication protocol and listening address.
        list($scheme, $address) = \explode(':', $this->_socketName, 2);
        // Check application layer protocol class.
        if (!isset(static::$_builtinTransports[$scheme])) {
            $scheme         = \ucfirst($scheme);
            $this->protocol = \substr($scheme,0,1)==='\\' ? $scheme : 'Protocols\\' . $scheme;
            if (!\class_exists($this->protocol)) {
                $this->protocol = "Workerman\\Protocols\\$scheme";
                if (!\class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }

            if (!isset(static::$_builtinTransports[$this->transport])) {
                throw new Exception('Bad worker->transport ' . \var_export($this->transport, true));
            }
        } else {
            $this->transport = $scheme;
        }
        //local socket
        return static::$_builtinTransports[$this->transport] . ":" . $address;
    }

    /**
     * Pause accept new connections.
     *
     * @return void
     */
    public function pauseAccept()
    {
        if (static::$globalEvent && false === $this->_pauseAccept && $this->_mainSocket) {
            static::$globalEvent->del($this->_mainSocket, EventInterface::EV_READ);
            $this->_pauseAccept = true;
        }
    }

    /**
     * Resume accept new connections.
     *
     * @return void
     */
    public function resumeAccept()
    {
        // Register a listener to be notified when server socket is ready to read.
        if (static::$globalEvent && true === $this->_pauseAccept && $this->_mainSocket) {
            if ($this->transport !== 'udp') {
                static::$globalEvent->add($this->_mainSocket, EventInterface::EV_READ, array($this, 'acceptConnection'));
            } else {
                static::$globalEvent->add($this->_mainSocket, EventInterface::EV_READ, array($this, 'acceptUdpConnection'));
            }
            $this->_pauseAccept = false;
        }
    }

    /**
     * Get socket name.
     *
     * @return string
     */
    public function getSocketName()
    {
        return $this->_socketName ? \lcfirst($this->_socketName) : 'none';
    }

    /**
     * Run worker instance.
     *
     * @return void
     */
    public function run()
    {
        //Update process state.
        static::$_status = static::STATUS_RUNNING;

        // Register shutdown function for checking errors.
        \register_shutdown_function(array("\\Workerman\\Worker", 'checkErrors'));

        // Set autoload root path.
        Autoloader::setRootPath($this->_autoloadRootPath);

        // Create a global event loop.
        if (!static::$globalEvent) {
            $event_loop_class = static::getEventLoopName();
            static::$globalEvent = new $event_loop_class;
            $this->resumeAccept();
        }

        // Reinstall signal.
        static::reinstallSignal();

        // Init Timer.
        Timer::init(static::$globalEvent);

        // Set an empty onMessage callback.
        if (empty($this->onMessage)) {
            $this->onMessage = function () {};
        }

        \restore_error_handler();

        // Try to emit onWorkerStart callback.
        if ($this->onWorkerStart) {
            try {
                \call_user_func($this->onWorkerStart, $this);
            } catch (\Exception $e) {
                static::log($e);
                // Avoid rapid infinite loop exit.
                sleep(1);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                // Avoid rapid infinite loop exit.
                sleep(1);
                exit(250);
            }
        }

        // Main loop.
        static::$globalEvent->loop();
    }

    /**
     * Stop current worker instance.
     *
     * @return void
     */
    public function stop()
    {
        // Try to emit onWorkerStop callback.
        if ($this->onWorkerStop) {
            try {
                \call_user_func($this->onWorkerStop, $this);
            } catch (\Exception $e) {
                static::log($e);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                exit(250);
            }
        }
        // Remove listener for server socket.
        $this->unlisten();
        // Close all connections for the worker.
        if (!static::$_gracefulStop) {
            foreach ($this->connections as $connection) {
                $connection->close();
            }
        }
        // Clear callback.
        $this->onMessage = $this->onClose = $this->onError = $this->onBufferDrain = $this->onBufferFull = null;
    }

    /**
     * Accept a connection.
     *
     * @param resource $socket
     * @return void
     */
    public function acceptConnection($socket)
    {
        // Accept a connection on server socket.
        \set_error_handler(function(){});
        $new_socket = \stream_socket_accept($socket, 0, $remote_address);
        \restore_error_handler();

        // Thundering herd.
        if (!$new_socket) {
            return;
        }

        // TcpConnection.
        $connection                         = new TcpConnection($new_socket, $remote_address);
        $this->connections[$connection->id] = $connection;
        $connection->worker                 = $this;
        $connection->protocol               = $this->protocol;
        $connection->transport              = $this->transport;
        $connection->onMessage              = $this->onMessage;
        $connection->onClose                = $this->onClose;
        $connection->onError                = $this->onError;
        $connection->onBufferDrain          = $this->onBufferDrain;
        $connection->onBufferFull           = $this->onBufferFull;

        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                \call_user_func($this->onConnect, $connection);
            } catch (\Exception $e) {
                static::log($e);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                exit(250);
            }
        }
    }

    /**
     * For udp package.
     *
     * @param resource $socket
     * @return bool
     */
    public function acceptUdpConnection($socket)
    {
        \set_error_handler(function(){});
        $recv_buffer = \stream_socket_recvfrom($socket, static::MAX_UDP_PACKAGE_SIZE, 0, $remote_address);
        \restore_error_handler();
        if (false === $recv_buffer || empty($remote_address)) {
            return false;
        }
        // UdpConnection.
        $connection           = new UdpConnection($socket, $remote_address);
        $connection->protocol = $this->protocol;
        if ($this->onMessage) {
            try {
                if ($this->protocol !== null) {
                    /** @var \Workerman\Protocols\ProtocolInterface $parser */
                    $parser = $this->protocol;
                    if ($parser && \method_exists($parser, 'input')) {
                        while ($recv_buffer !== '') {
                            $len = $parser::input($recv_buffer, $connection);
                            if ($len === 0)
                                return true;
                            $package = \substr($recv_buffer, 0, $len);
                            $recv_buffer = \substr($recv_buffer, $len);
                            $data = $parser::decode($package, $connection);
                            if ($data === false)
                                continue;
                            \call_user_func($this->onMessage, $connection, $data);
                        }
                    } else {
                        $data = $parser::decode($recv_buffer, $connection);
                        // Discard bad packets.
                        if ($data === false)
                            return true;
                        \call_user_func($this->onMessage, $connection, $data);
                    }
                } else {
                    \call_user_func($this->onMessage, $connection, $recv_buffer);
                }
                ++ConnectionInterface::$statistics['total_request'];
            } catch (\Exception $e) {
                static::log($e);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                exit(250);
            }
        }
        return true;
    }
}
