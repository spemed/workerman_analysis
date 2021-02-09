<?php


namespace YryWorkerman\Worker;


use YryWorkerman\Exception\InvalidInput;

class CommandFactory
{
    /**
     * @param string $command
     * @return CommandInterface
     * @throws InvalidInput
     */
    public static function create(string $command):CommandInterface
    {
        switch ($command) {
            case "start":return new Start();break;
            case "restart":return new Restart();break;
            case "stop":return new Stop();break;
            case "reload":return new Reload();break;
            case "connections":return new Connections();break;
            case "status":return new Status();break;
            default:throw new InvalidInput("command $command is invalid");
        }
    }
}