<?php
require "./vendor/autoload.php";

use YryWorkerman\Worker\Worker;
use YryWorkerman\Worker\Pool;

$worker = new Worker("tcp://0.0.0.0:80");
$worker->setCount(4);

Pool::register($worker);
Pool::runAll();



