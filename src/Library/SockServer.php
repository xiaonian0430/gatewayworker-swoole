<?php

declare(strict_types=1);

namespace SwooleGateway\Library;

use Exception;
use Swoole\ConnectionPool as SWConnectionPool;
use Swoole\Coroutine as SWCoroutine;
use Swoole\Coroutine\Server\Connection as SWCSConnection;
use Swoole\Process as SWProcess;
use Swoole\Server as SWServer;
use Swoole\Coroutine\Server as SWCServer;
use Swoole\Coroutine\Client as SWCClient;
use SwooleGateway\Protocol;

class SockServer
{
    private $sock_file;
    private $callback;
    private $pool;

    public function __construct(callable $callback, string $sock_file = null)
    {
        $this->sock_file = $sock_file ?: ('/var/run/' . uniqid() . '.sock');
        $this->callback = $callback;
        $this->pool = new SWConnectionPool(function () {
            $client = new SWCClient(SWOOLE_UNIX_STREAM);
            $client->set([
                'open_length_check' => true,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 0,
            ]);
            connect:
            if (!$client->connect($this->sock_file)) {
                $client->close();
                SWCoroutine::sleep(0.001);
                goto connect;
            }
            return $client;
        });
    }

    public function mountTo(SWServer $server)
    {
        $server->addProcess(new SWProcess(function (SWProcess $process) {
            $this->startLanServer();
        }, false, 2, true));
    }

    public function getSockFile(): string
    {
        return $this->sock_file;
    }

    public function sendAndReceive($data)
    {
        $client = $this->pool->get();
        $client->send(Protocol::encode(serialize($data)));
        $res = unserialize(Protocol::decode($client->recv()));
        $this->pool->put($client);
        return $res;
    }

    public function streamWriteAndRead($data)
    {
        $fp = stream_socket_client("unix://{$this->sock_file}", $errno, $errstr);
        if (!$fp) {
            throw new Exception("$errstr", $errno);
        } else {
            fwrite($fp, Protocol::encode(serialize($data)));
            $res = unserialize(Protocol::decode(fread($fp, 40960)));
            fclose($fp);
            return $res;
        }
    }

    private function startLanServer()
    {
        $server = new SWCServer('unix:' . $this->sock_file);
        $server->set([
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 0,
        ]);
        $server->handle(function (SWCSConnection $conn) {
            while (true) {
                $buffer = $conn->recv(1);
                if ($buffer === '') {
                    $conn->close();
                    break;
                } elseif ($buffer === false) {
                    if (swoole_last_error() !== SOCKET_ETIMEDOUT) {
                        $conn->close();
                        break;
                    }
                } else {
                    $res = unserialize(Protocol::decode($buffer));
                    call_user_func($this->callback ?: function () {
                    }, $conn, $res);
                }
            }
        });
        $server->start();
    }

    public static function sendToConn(SWCSConnection $conn, $data)
    {
        $conn->send(Protocol::encode(serialize($data)));
    }
}
