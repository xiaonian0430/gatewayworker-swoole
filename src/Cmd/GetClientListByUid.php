<?php

declare(strict_types=1);

namespace SwooleGateway\Cmd;

use Swoole\Coroutine\Server\Connection;
use SwooleGateway\Interfaces\CmdInterface;
use SwooleGateway\Gateway;
use SwooleGateway\Protocol;

class GetClientListByUid implements CmdInterface
{
    public static function getCommandCode(): int
    {
        return 10;
    }

    public static function encode(string $uid): string
    {
        return pack('C', self::getCommandCode()) . $uid;
    }

    public static function decode(string $buffer): array
    {
        return [
            'uid' => $buffer,
        ];
    }

    public static function execute(Gateway $gateway, Connection $conn, string $buffer)
    {
        $data = self::decode($buffer);
        $fd_list = $gateway->uid_list[$data['uid']] ?? [];
        $conn->send(Protocol::encode(pack('N*', ...$fd_list)));
    }
}
