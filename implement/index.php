<?php
require "./vendor/autoload.php";


use YryWorkerman\Signal\Signal;
use YryWorkerman\Timer\Timer;
use YryWorkerman\Event\Select;

$eventLoop = new Select();
$timer = new Timer($eventLoop);
$timer->ticker(1,function () {
    echo "hello world\n";
});
$timer->once(5,function () {
    echo "i am die\n";
});
$sign = new Signal($eventLoop);
$sign->add(SIGTERM,function ($signo) {
    echo "receive signal={$signo}\n";
});
//todo 补充文件事件的监听
$eventLoop->loop();
