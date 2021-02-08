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
    }

    /**
     *  检测sapi和运行环境
     */
    protected static function checkSapiEnv() {
        //非cli环境直接报错
        if (php_sapi_name() !== PHP_SAPI) {
            "workerman must start in php-cli");
        }
        if (DIRECTORY_SEPARATOR === "\\") {
            self::$os = Constants::OS_TYPE_WINDOWS;
            return;
        }
        self::$os = Constants::OS_TYPE_LINUX;
    }
}