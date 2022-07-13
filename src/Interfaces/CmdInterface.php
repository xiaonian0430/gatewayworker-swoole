<?php

declare(strict_types=1);

namespace SwooleGateway\Interfaces;

use Swoole\Coroutine\Server\Connection;
use SwooleGateway\Gateway;

interface CmdInterface
{
    /**
     * get command code
     *
     * @return integer
     */
    public static function getCommandCode(): int;

    /**
     * execute command
     *
     * @param Gateway $gateway
     * @param Connection $connection
     * @param string $buffer
     * @return void
     */
    public static function execute(Gateway $gateway, Connection $connection, string $buffer);
}
