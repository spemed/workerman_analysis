<?php


namespace YryWorkerman\Worker;

use SebastianBergmann\CodeCoverage\Report\PHP;
use YryWorkerman\Exception\ProgressFault;

/**
 * Class Pool
 * @package YryWorkerman\Worker
 * worker池
 */
class Pool
{
    /**
     * pool正启动中
     */
    const STATUS_STARTING = 1;

    /**
     * pool已经完全启动
     */
    const STATUS_RUNNING = 2;

    /**
     * @var int
     * 当前pool的状态
     */
    protected static int $status;

    protected static bool $daemon = false;
    protected static bool $graceful = false;


    /**
     * @var Worker[] Worker
     * worker实例的集合
     *
     */
    protected static array $workers;

    /**
     * @var array
     * ['worker_id1'=>[pid1,pid2,pid3,pid4],'worker_id2'=>[pid5,pid6,pid7,pid8]]
     * 维持每个worker的pid list
     */
    protected static array $pidList;

    /**
     * @var array
     * ['worker_id1'=>["pid1"=>"pid1","pid2"=>"pid2"],'worker_id2'=>["pid3"=>"pid3","pid4"=>"pid4"]
     * 维持每个worker 进程的pid map
     */
    protected static array $pidMap;

    /**
     * @var string
     * 日志文件的地址
     */
    protected static string $logFile;

    /**
     * @var string
     * 启动文件所在路径,结尾不包含/
     */
    protected static string $startFilePath;

    /**
     * @var string
     * 唯一前缀
     */
    protected static string $uniquePrefix;

    /**
     * @var string
     * 存储master进程pid的文件路径
     */
    protected static string $masterPidPath;


    const DEFAULT_LOG_NAME = "workerman.log";
    /**
     * @var string
     * 日志名称
     */
    public static string $logName = self::DEFAULT_LOG_NAME;

    /**
     * @var int
     * master进程的pid
     */
    protected static int $masterPid = 0;

    /**
     * @var string
     * 存储进程统计信息的文件路径
     */
    protected static string $staticsFilePath;

    /**
     * 运行环境
     */
    protected static string $os = Constants::OS_TYPE_LINUX;

    /**
     * @param Worker $worker
     * 注册worker
     */
    public static function register(Worker $worker)
    {
        //取得每个worker实例的hashcode(进程内唯一)
        $workerId = spl_object_hash($worker);
        self::$workers[$workerId] = $worker;
    }

    //初始化每个worker的$pidList
    protected static function initPidList() {
        foreach (static::$workers as $workerId => $worker) {
            static::$pidList[$workerId] = static::$pidList[$workerId] ?? [];
            //每个worker设置的进程数目
            $processCount = $worker->getCount();
            for ($i=0;$i<$processCount;$i++){
                static::$pidList[$workerId][$i] = static::$pidList[$workerId][$i] ?? 0;
            }
        }
    }

    //初始化每个worker的$pidMap
    protected static function initPidMap() {
        foreach (static::$workers as $workerId => $worker) {
            static::$pidMap[$workerId] = static::$pidMap[$workerId] ?? [];
        }
    }

    /**
     * 执行worker的启动
     * todo 需要研究输入输出流和safeEcho
     */
    public static function runAll()
    {
        try {
            self::checkSapiEnv();
            self::initStaticProperty();
            self::parseCommand();
            self::daemonize();
            //todo initWorker进程,为每个worker设置监听套接字,并且加入事件中心
            //todo installSignal安装信号
            //保存master进程的id
            self::saveMasterId();
            //fork每个worker的进程
            self::forkWorkers();
            //todo 重定向标准输入输出
            //todo 将master进程转为monitor进程,监听子进程退出的事件,根据状态判断是否应该重新拉起子进程
            self::monitor();

        } catch (ProgressFault $exception) {
            exit($exception->getMessage());
        }
    }

    /**
     *  检测sapi和运行环境
     */
    protected static function checkSapiEnv() {
        //非cli环境直接报错
        if (php_sapi_name() !== PHP_SAPI) {
            exit("workerman must start in php-cli");
        }
        if (DIRECTORY_SEPARATOR === "\\") {
            self::$os = Constants::OS_TYPE_WINDOWS;
            return;
        }
        self::$os = Constants::OS_TYPE_LINUX;
    }

    /**
     * 初始化静态属性
     */
    protected static function initStaticProperty() {

        $stack = debug_backtrace();
        //取得执行runAll方法的文件路径
        $file = $stack[count($stack)-1]["file"];
        $fileItemArr = explode(DIRECTORY_SEPARATOR,$file);
        //弹出数组最后一个元素
        array_pop($fileItemArr);

        static::$startFilePath = implode(DIRECTORY_SEPARATOR,$fileItemArr);
        static::$uniquePrefix = implode("_",$fileItemArr);

        static::$logFile = sprintf("%s/%s",static::$startFilePath,static::$logName);
        //不存在文件夹则创建之
        //todo 考虑读写权限
        if (!is_file(static::$logFile)) {
            touch(static::$logFile);
            chmod(static::$logFile, 0622);
        }

        //在启动文件的同级目录下设置存储master进程pid的文件
        //此处不着急创建,可以在设置masterPid的时候才创建
        static::$masterPidPath = sprintf("%s/%s.pid",static::$startFilePath,static::$uniquePrefix);

        //配合sys_get_temp_dir创建临时目录,用于存储进程输出的统计信息
        //进程关闭时需要将其清理
        //每次调用sys_get_temp_dir都会产生临时文件夹
        static::$staticsFilePath = sprintf("%s/%s.statics",sys_get_temp_dir(),static::$uniquePrefix);

        //把worker pool的工作状态标志位启动中
        static::$status = static::STATUS_STARTING;
        //init pid list
        static::initPidList();
        // init pid map
        static::initPidMap();
        //todo 原实现中不了解的timerInit
    }

    /**
     * 命令行解析
     * php index <command> [mode]
     * 命令行至少需要传递三个参数
     * @throws ProgressFault
     */
    protected static function parseCommand() {
        global $argv,$argc;
        $start_file = $argv[0];
        $usage = $usage = "Usage: php {$start_file} <command> [mode]\nCommands: \nstart\t\tStart worker in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop worker.\n\t\tUse mode -g to stop gracefully.\nrestart\t\tRestart workers.\n\t\tUse mode -d to start in DAEMON mode.\n\t\tUse mode -g to stop gracefully.\nreload\t\tReload codes.\n\t\tUse mode -g to reload gracefully.\nstatus\t\tGet worker status.\n\t\tUse mode -d to show live status.\nconnections\tGet worker connections.\n";
        if ($argc < 2) {
            throw new ProgressFault($usage);
        }
        //command map
        $commandSet = [
            "start"=>"",
            "restart"=>"",
            "reload"=>"",
            "stop"=>"",
            "status"=>"",
            "connections"=>"",
        ];
        //mode map
        $modeSet = [
            "-g"=>"",
            "-d"=>""
        ];

        $command = "";
        $mode = "";

        //解析命令行参数中的command和mode
        foreach ($argv as $argvItem) {
            if (isset($commandSet[$argvItem])) {
                $command = $argvItem;
            }
            if (isset($modeSet[$argvItem])) {
                $mode = $argvItem;
            }
        }

        //匹配不到支持的命令
        if (!$command) {
            throw new ProgressFault($usage);
        }

        //如果存在pid文件
        if (is_file(static::$masterPidPath)) {
            static::$masterPid = file_get_contents(static::$masterPidPath);
        }

        //从文件中读取出来的static::$masterPid需要大于0
        //进程需要存在于os中
        //os中不可能出现两个相同pid的进程
        $master_is_alive = static::$masterPid > 0 && static::isProcessAlive(static::$masterPid) && posix_getpid() != static::$masterPid;

        //根据进程的启动状态过滤不合时宜的命令
        if ($master_is_alive) {
            //进程已经启动则拒绝所有start命令
            if ($command == "start") {
                throw new ProgressFault("workerman process already start...");
            }
        } else {
            if ($command !== "start" && $command !== "restart") {
                throw new ProgressFault("workerman process no running");
            }
        }

        //mode处理,开启对应标志位
        if ($mode == "-d") {
            //进程守护模式标志位
            self::$daemon = true;
        } else if ($mode == "-g") {
            //进程平滑重启标志位
            self::$graceful = true;
        }

        //todo 完善命令解析处理
    }


    /**
     * @throws ProgressFault
     * 进程守护化
     */
    protected static function daemonize() {

        //非linux系统或者未启动守护化mode退出
        if (static::$os !== Constants::OS_TYPE_LINUX || static::$daemon === false) {
            return;
        }
        //fork后台进程
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new ProgressFault("failed to fork backend process");
        }
        //父进程从此处退出
        if ($pid > 0) {
            exit();
        }
        //后台进程开始守护化设置
        //该进程创建的文件默认权限为777 - 000 = 777
        umask(0);

        //子进程脱离当前终端运行,成为守护进程
        if (-1 === \posix_setsid()) {
            throw new ProgressFault("set sessionId failed");
        }

        // Fork again avoid SVR4 system regain the control of terminal.
        // todo 这里的背景知识不太了解,但是按照作者的注释,似乎是兼容SVR4系统进程守护化时的特殊行为
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new ProgressFault("Fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }
    }

    /**
     * @param int $pid 进程id
     * @return bool
     * 进程是否仍在操作系统中
     */
    protected static function isProcessAlive(int $pid):bool {
        return posix_kill($pid,0);
    }

    protected static function saveMasterId() {
        if (static::$os !== Constants::OS_TYPE_LINUX) {
            return;
        }
        //获取当前master进程的pid,此步骤需要在fork之前完成
        static::$masterPid = posix_getpid();
        file_put_contents(static::$masterPidPath,static::$masterPid);
    }

    protected static function forkWorkers()
    {
        //遍历worker池子
        foreach (static::$workers as $workerId => $worker) {
            for ($i=0;$i<$worker->getCount();$i++) {
                //取得某个worker的pidList
                $pidList = static::$pidList[$workerId];
                //寻找到pid为0[尚未创建进程用于替代的占位符]
                $pidIndex = array_search(0,$pidList);
                //找到一个位置就fork一个进程
                if ($pidIndex !== false) {
                    $pid = static::forkWorker($worker);
                    if ($pid < 0) {
                        echo "failed to fork number {$pidIndex} worker progress";
                        continue;
                    }
                    //fork出的进程pid用于设置pidList
                    static::$pidList[$workerId][$pidIndex] = $pid;
                    //fork出的进程pid用于设置pidMap
                    static::$pidMap[$workerId][$pid] = $pid;
                }
            }
        }
        //程序已经启动了
        static::$status = static::STATUS_RUNNING;
    }

    /**
     * @param Worker $worker
     * @return int
     */
    protected static function forkWorker(Worker $worker):int {
        //todo 待实现清理套接字和事件中心
        //监听是跨进程共享的
        //去除其他worker的干扰,从static::workers里面删除其他worker的实例,并且注销事件中心中对应的监听套接字的事件,并释放套接字
        $pid = pcntl_fork();
        //fork失败
        if ($pid < 0) {
            return -1;
        }
        if ($pid > 0) {
            return $pid;
        }
        //todo 子进程开始阻塞于事件中心
        $worker->run();
        return 0;
    }

    /**
     * master进程转化为monitor进程
     * 阻塞于pcntl_wait(有子进程退出)
     * 根据status判断是否要拉起死去的进程
     */
    public static function monitor()
    {
        while (true) {
            pcntl_signal_dispatch();
            $status = 0;
            //阻塞直到子进程退出,得到退出子进程的pid
            //如果所有子进程都退出了,则pcntl_wait将会马上返回
            $pid = pcntl_wait($status,WUNTRACED);
            pcntl_signal_dispatch();

            //等待到了子进程
            if ($pid > 0) {
                echo $pid.PHP_EOL;
                //说明是子进程异常退出,打印错误
                if ($status < 0) {
                    echo "worker progress pid={$pid} exit excepted";
                }
                //如果当前master进程的status仍然是STATUS_RUNNING,则唤醒子进程
                if (static::$status === static::STATUS_RUNNING) {
                    foreach (static::$workers as $workerId=>$worker) {
                        //old pid map删除
                        if (isset(static::$pidMap[$workerId][$pid])) unset(static::$pidMap[$workerId][$pid]);
                        $index = array_search($pid,static::$pidList[$workerId]);
                        if ($index === false) {
                            continue;
                        }
                        //重新fork子进程并让其投入工作
                        $pidNew = static::forkWorker($worker);
                        static::$pidList[$workerId][$index] = $pidNew;
                    }
                }
            }
        }
    }
}