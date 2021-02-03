<?php
declare(ticks = 1);

function sig_handler($signo) {
    switch ($signo) {
        case SIGTERM:
            echo 'SIGTERM' . PHP_EOL;
            break;
        default:
    }
}

pcntl_signal(SIGTERM, 'sig_handler');
echo posix_getpid()."\n";
$pid = pcntl_fork();
$status = -1;
if ($pid == -1) {
    die('could not fork');
}
else if ($pid) {
    echo 'parent' . PHP_EOL;
    sleep(30);
//    pcntl_wait($status);
//    echo $status;\
    echo 111;
}
else {
    echo 'child' . PHP_EOL;
    sleep(30);
}