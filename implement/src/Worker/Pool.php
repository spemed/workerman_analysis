<?php


namespace YryWorkerman\Worker;

/**
 * Class Pool
 * @package YryWorkerman\Worker
 * worker池
 */
class Pool
{
    /**
     * @var array Worker
     * worker实例的集合
     */
    protected static array $workers;

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
    protected static int $masterPid;

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


    /**
     * 执行worker的启动
     */
    public static function runAll()
    {
        self::checkSapiEnv();
        self::initStaticProperty();
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
        }

        //在启动文件的同级目录下设置存储master进程pid的文件
        //此处不着急创建,可以在设置masterPid的时候才创建
        static::$masterPidPath = sprintf("%s/%s.pid",static::$startFilePath,static::$uniquePrefix);

        //配合sys_get_temp_dir创建临时目录,用于存储进程输出的统计信息
        //进程关闭时需要将其清理
        //每次调用sys_get_temp_dir都会产生临时文件夹
        static::$staticsFilePath = sprintf("%s/%s.statics",sys_get_temp_dir(),static::$uniquePrefix);
    }
}