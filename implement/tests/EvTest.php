<?php


use PHPUnit\Framework\TestCase;
use YryWorkerman\Event\Flag;
use YryWorkerman\Exception\InputNull;
use YryWorkerman\Exception\InvalidInput;
use YryWorkerman\Signal\Signal;
use YryWorkerman\Timer\Timer;
use YryWorkerman\Event\Select;

class EvTest extends TestCase
{
    public function testEvent() {
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
        $errno = 0;
        $errStr = "";
        $listenSocket = stream_socket_server("tcp://0.0.0.0:8343",$errno,$errStr);
//创建失败报错
        if (!$listenSocket) {
            "failed to create socket,errno={$errno},errStr={$errStr}");
        }
        try {
            $eventLoop->registerSocket($listenSocket, Flag::FD_READ, function ($listenSocket) use ($eventLoop) {
                $connectSocket = stream_socket_accept($listenSocket);
                echo "new connection accept!\n";
                $eventLoop->registerSocket($connectSocket, Flag::FD_READ, function ($connectSocket) use($eventLoop) {
                    $receiveData = fread($connectSocket, 1000);
                    fwrite($connectSocket, $receiveData);
                    $eventLoop->delSocket($connectSocket,Flag::FD_READ);
                }, [$connectSocket]);
            }, [$listenSocket]);
        } catch (InputNull $e) {
            print_r($e->getMessage());
        } catch (InvalidInput $e) {
            print_r($e->getMessage());
        }
        $eventLoop->loop();
    }
}
