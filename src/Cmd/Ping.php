<?php

declare(strict_types=1);

namespace SwooleGateway\Cmd;

use Swoole\Coroutine\Server\Connection;
use SwooleGateway\Interfaces\CmdInterface;
use SwooleGateway\Gateway;

class Ping implements CmdInterface
{
    public static function getCommandCode(): int
    {
        return 0;
    }

    public static function encode(): string
    {
        return pack('C', self::getCommandCode());
    }

    public static function execute(Gateway $gateway, Connection $conn, string $buffer)
    {
    }
}
