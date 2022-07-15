<?php

declare(strict_types=1);

namespace SwooleGateway;

use Swoole\Coroutine as SWCoroutine;
use Swoole\Coroutine\Server\Connection as SWCSConnection;
use Swoole\Process as SWProcess;
use Swoole\Server as SWServer;
use Swoole\Timer as SWTimer;
use Swoole\WebSocket\Server as SWWSServer;
use SwooleGateway\Library\Client;
use SwooleGateway\Library\Config;
use SwooleGateway\Library\Server;

class Gateway extends Service
{
    public $register_host = '127.0.0.1';
    public $register_port = 9327;

    public $lan_host = '127.0.0.1';
    public $lan_port = 9108;

    protected $process;
    protected $command_list = [];

    public $worker_list = [];

    public $fd_list = [];
    public $uid_list = [];
    public $group_list = [];

    protected $listen_list = [];

    public function __construct()
    {
        Config::set('init_file', __DIR__ . '/init/gateway.php');
        Config::set('router', function (int $fd, int $cmd, array $worker_list) {
            if ($worker_list) {
                return $worker_list[array_keys($worker_list)[$fd % count($worker_list)]];
            }
        });
        parent::__construct();
    }

    protected function createServer(): SWServer
    {
        $server = new SWWSServer('127.0.0.1', 0, SWOOLE_PROCESS);

        foreach ($this->listen_list as $listen) {
            $port = $server->addListener($listen['host'], $listen['port'], $listen['sockType']);
            $port->set($listen['options']);
            if (isset($listen['options']['open_websocket_protocol']) && $listen['options']['open_websocket_protocol']) {
                $port->on('Connect', function () {});
                $port->on('Request', function ($request, $response) {
                    $response->status(403);
                    $response->end("Not Supported~\n");
                });
            }
        }
        $this->process = new SWProcess(function ($process) use ($server) {

            //连接到注册中心
            $this->connectToRegister();

            //启动内部服务
            $this->startLanServer();
            Coroutine::create(function () use ($process) {
                $socket = $process->exportSocket();
                $socket->setProtocol([
                    'open_length_check' => true,
                    'package_length_type' => 'N',
                    'package_length_offset' => 0,
                    'package_body_offset' => 0,
                ]);
                while (true) {
                    $buffer = $socket->recv();
                    if (!$buffer) {
                        continue;
                    }
                    $res = unserialize($buffer);
                    switch ($res['event']) {
                        case 'Connect':
                            list($event) = $res['args'];
                            $this->fd_list[$event['fd']] = [
                                'uid' => '',
                                'session' => [],
                                'group_list' => [],
                                'ws' => 0,
                            ];
                            $session_string = '';
                            $load = pack('CNN', Protocol::EVENT_CONNECT, $event['fd'], strlen($session_string)) . $session_string;
                            $this->sendToWorker(Protocol::EVENT_CONNECT, $event['fd'], $load);
                            break;

                        case 'Receive':
                            list($event) = $res['args'];
                            $bind = $this->fd_list[$event['fd']];
                            $session_string = $bind['session'] ? serialize($bind['session']) : '';
                            $load = pack('CNN', Protocol::EVENT_RECEIVE, $event['fd'], strlen($session_string)) . $session_string . $event['data'];
                            $this->sendToWorker(Protocol::EVENT_RECEIVE, $event['fd'], $load);
                            break;

                        case 'Close':
                            list($event) = $res['args'];
                            if (!isset($this->fd_list[$event['fd']])) {
                                break;
                            }
                            $bind = $this->fd_list[$event['fd']];
                            $bind['group_list'] = array_values($bind['group_list']);
                            $session_string = $bind['session'] ? serialize($bind['session']) : '';
                            unset($bind['session']);
                            $load = pack('CNN', Protocol::EVENT_CLOSE, $event['fd'], strlen($session_string)) . $session_string . serialize($bind);
                            $this->sendToWorker(Protocol::EVENT_CLOSE, $event['fd'], $load);

                            if ($bind_uid = $this->fd_list[$event['fd']]['uid']) {
                                unset($this->uid_list[$bind_uid][$event['fd']]);
                                if (!$this->uid_list[$bind_uid]) {
                                    unset($this->uid_list[$bind_uid]);
                                }
                            }

                            foreach ($this->fd_list[$event['fd']]['group_list'] as $bind_group) {
                                unset($this->group_list[$bind_group][$event['fd']]);
                                if (!$this->group_list[$bind_group]) {
                                    unset($this->group_list[$bind_group]);
                                }
                            }

                            unset($this->fd_list[$event['fd']]);
                            break;

                        case 'Open':
                            list($request) = $res['args'];
                            $this->fd_list[$request['fd']] = [
                                'uid' => '',
                                'session' => [],
                                'group_list' => [],
                                'ws' => 1,
                            ];
                            $session_string = '';
                            $load = pack('CNN', Protocol::EVENT_OPEN, $request['fd'], strlen($session_string)) . $session_string . serialize($request);
                            $this->sendToWorker(Protocol::EVENT_OPEN, $request['fd'], $load);
                            break;

                        case 'Message':
                            list($frame) = $res['args'];
                            $bind = $this->fd_list[$frame['fd']];
                            $session_string = $bind['session'] ? serialize($bind['session']) : '';
                            $load = pack('CNN', Protocol::EVENT_MESSAGE, $frame['fd'], strlen($session_string)) . $session_string . pack('CC', $frame['opcode'], $frame['flags']) . $frame['data'];
                            $this->sendToWorker(Protocol::EVENT_MESSAGE, $frame['fd'], $load);
                            break;

                        default:
                            Service::debug("undefined event. buffer:{$buffer}");
                            break;
                    }
                }
            });
        }, false, 2, true);
        $server->addProcess($this->process);
        return $server;
    }

    public function listen(string $host, int $port, array $options = [], int $sockType = SWOOLE_SOCK_TCP)
    {
        $this->listen_list[$host . ':' . $port] = [
            'host' => $host,
            'port' => $port,
            'sockType' => $sockType,
            'options' => $options,
        ];
    }

    protected function sendToProcess($data)
    {
        $this->process->exportSocket()->send(serialize($data));
    }

    public function sendToClient(int $fd, string $message)
    {
        if (isset($this->fd_list[$fd]['ws']) && $this->fd_list[$fd]['ws']) {
            $this->getServer()->send($fd, SWWSServer::pack($message));
        } else {
            $this->getServer()->send($fd, $message);
        }
    }

    protected function sendToWorker(int $cmd, int $fd, string $load)
    {
        if ($worker = call_user_func(Config::get('router'), $fd, $cmd, $this->worker_list)) {
            $pool = $worker['pool'];
            $conn = $pool->get();
            $conn->send(Protocol::encode($load));
            $pool->put($conn);

            $buff = bin2hex(Protocol::encode($load));
            Service::debug("send to worker:{$buff}");
        } else {
            Service::debug("worker not found");
        }
    }

    /**
     * 启动内部服务
     */
    protected function startLanServer()
    {
        Service::debug('start to startLanServer');
        $server = new Server($this->lan_host, $this->lan_port);
        $server->onConnect = function (SWCSConnection $conn) {
            $conn->peername = $conn->exportSocket()->getpeername();
        };
        $server->onMessage = function (SWCSConnection $conn, string $buffer) {
            $load = Protocol::decode($buffer);
            $data = unpack("Ccmd", $load);
            if (isset($this->command_list[$data['cmd']])) {
                call_user_func([$this->command_list[$data['cmd']], 'execute'], $this, $conn, substr($load, 1));
            } else {
                $hex_buffer = bin2hex($buffer);
                Service::debug("cmd:{$data['cmd']} not surport! buffer:{$hex_buffer}");
            }
        };
        $server->onClose = function (SWCSConnection $conn) {
            $address = implode(':', $conn->peername);
            if (isset($this->worker_list[$address])) {
                Service::debug("close worker client {$address}");
                $pool = $this->worker_list[$address]['pool'];
                $conn = $pool->get();
                $conn->close();
                $pool->close();
                unset($this->worker_list[$address]);
            } else {
                Service::debug("close worker connect {$address}");
            }
        };
        $server->start();
    }

    /**
     * 连接到注册中心
     */
    protected function connectToRegister()
    {
        Service::debug('start to connectToRegister');
        $client = new Client($this->register_host, $this->register_port);
        $client->onConnect = function () use ($client) {
            Service::debug('connect to register');
            $client->send(Protocol::encode(pack('CNn', Protocol::GATEWAY_CONNECT, ip2long($this->lan_host), $this->lan_port) . Config::get('register_secret', '')));

            //发送心跳
            $ping_buffer = Protocol::encode(pack('C', Protocol::PING));
            $client->timerId = SWTimer::tick(3000, function () use ($client, $ping_buffer) {
                Service::debug("send ping to register");
                $client->send($ping_buffer);
            });
        };
        $client->onClose = function () use ($client) {
            Service::debug("closed by register");
            if ($client->timerId) {
                SWTimer::clear($client->timerId);
                unset($client->timerId);
            }
            SWCoroutine::sleep(1);
            Service::debug("reconnect to register");
            $client->connect();
        };
        $client->start();
    }
}
