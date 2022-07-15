<?php

declare(strict_types=1);

namespace SwooleGateway;

use Swoole\Coroutine as SWCoroutine;
use Swoole\Process as SWProcess;
use Swoole\Server as SWServer;
use Swoole\Timer as SWTimer;
use SwooleGateway\Cmd\Ping;
use SwooleGateway\Cmd\RegisterWorker;
use SwooleGateway\Library\Client;
use SwooleGateway\Library\Config;

class Worker extends Service
{
    public $register_host = '127.0.0.1';
    public $register_port = 9327;

    protected $process;
    protected $gateway_address_list = [];
    protected $gateway_conn_list = [];

    public function __construct()
    {
        $this->set([
            'task_worker_num' => swoole_cpu_num()
        ]);
        Config::set('init_file', __DIR__ . '/init/worker.php');
        parent::__construct();
    }

    protected function createServer(): SWServer
    {
        $server = new SWServer('127.0.0.1', 0, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        $this->process = new SWProcess(function ($process) {

            //连接注册中心
            $this->connectToRegister();
            $socket = $process->exportSocket();
            while (true) {
                $msg = $socket->recv();
                if (!$msg) {
                    continue;
                }
                $res = unserialize($msg);
                switch ($res['event']) {
                    case 'gateway_address_list':
                        $this->getServer()->sendMessage(serialize([
                            'event' => 'gateway_address_list',
                            'gateway_address_list' => $this->gateway_address_list,
                        ]), $res['worker_id']);
                        break;
                }
                SWCoroutine::sleep(1);
            }
        }, false, 2, true);
        $server->addProcess($this->process);
        $this->set([
            'task_enable_coroutine' => true,
        ]);
        return $server;
    }

    protected function sendToProcess($data)
    {
        $this->process->exportSocket()->send(serialize($data));
    }

    /**
     * 连接到注册中心
     */
    protected function connectToRegister()
    {
        Service::debug('start to connectToRegister');
        $client = new Client($this->register_host, $this->register_port);
        $client->onConnect = function () use ($client) {
            Service::debug("connect to register");
            $client->send(Protocol::encode(pack('C', Protocol::WORKER_CONNECT) . Config::get('register_secret', '')));

            //Worker 定时向注册中心发送心跳包，防止断线
            $ping_buffer = Protocol::encode(pack('C', Protocol::PING));
            $client->timerId = SWTimer::tick(3000, function () use ($client, $ping_buffer) {
                Service::debug("send ping to register");
                $client->send($ping_buffer);
            });
        };
        $client->onMessage = function (string $buffer) {
            Service::debug("receive message from register");
            $data = unpack('Ccmd/A*load', Protocol::decode($buffer));
            switch ($data['cmd']) {
                case Protocol::BROADCAST_GATEWAY_LIST:
                    $addresses = [];
                    if ($data['load'] && (strlen($data['load']) % 6 === 0)) {
                        foreach (str_split($data['load'], 6) as $value) {
                            $address = unpack('Nlan_host/nlan_port', $value);
                            $address['lan_host'] = long2ip($address['lan_host']);
                            $addresses[$address['lan_host'] . ':' . $address['lan_port']] = $address;
                        }
                    }
                    $this->globals->set('gateway_address_list', $addresses);
                    $this->gateway_address_list = $addresses;
                    for ($i = 0; $i < $this->getServer()->setting['worker_num'] + $this->getServer()->setting['task_worker_num']; $i++) {
                        $this->getServer()->sendMessage(serialize([
                            'event' => 'gateway_address_list',
                            'gateway_address_list' => $this->gateway_address_list,
                        ]), $i);
                    }
                    $this->refreshGatewayConn();
                    break;

                default:
                    break;
            }
        };
        $client->onClose = function () use ($client) {
            Service::debug("closed by register");
            if (isset($client->timer_id)) {
                SWTimer::clear($client->timer_id);
                unset($client->timer_id);
            }
            SWCoroutine::sleep(1);
            Service::debug("reconnect to register");
            $client->connect();
        };
        $client->start();
    }

    /**
     * 刷新网关连接
     */
    protected function refreshGatewayConn()
    {
        $new_address_list = array_diff_key($this->gateway_address_list, $this->gateway_conn_list);
        foreach ($new_address_list as $key => $address) {
            $client = new Client($address['lan_host'], $address['lan_port']);
            $client->onConnect = function () use ($client, $address) {
                Service::debug("connect to gateway {$address['lan_host']}:{$address['lan_port']} 成功");
                $client->send(Protocol::encode(RegisterWorker::encode(Config::get('tag_list', []))));

                $ping_buffer = Protocol::encode(pack('C', Ping::getCommandCode()));
                $client->timerId = SWTimer::tick(3000, function () use ($client, $ping_buffer, $address) {
                    Service::debug("send ping to gateway {$address['lan_host']}:{$address['lan_port']}");
                    $client->send($ping_buffer);
                });
            };
            $client->onMessage = function (string $buffer) use ($address) {
                $this->getServer()->sendMessage(serialize([
                    'event' => 'gateway_event',
                    'buffer' => $buffer,
                    'address' => $address,
                ]), $address['lan_port'] % $this->getServer()->setting['worker_num']);
            };
            $client->onClose = function () use ($client, $address) {
                if (isset($client->timerId)) {
                    SWTimer::clear($client->timerId);
                    unset($client->timerId);
                }
                SWCoroutine::sleep(1);
                Service::debug("reconnect to gateway {$address['lan_host']}:{$address['lan_port']}");
                $client->connect();
            };
            $client->start();
            $this->gateway_conn_list[$key] = $client;
        }

        $off_address_list = array_diff_key($this->gateway_conn_list, $this->gateway_address_list);
        foreach ($off_address_list as $key => $client) {
            $client->stop();
            unset($this->gateway_conn_list[$key]);
        }
    }

    protected function dispatch(string $event, ...$args)
    {
        Service::debug("dispatch {$event}");
        call_user_func([$this->event, $event], ...$args);
    }

    protected function onGatewayMessage($buffer, $address)
    {
        $data = unpack('Ccmd/Nfd/Nsession_len/A*data', Protocol::decode($buffer));

        $session = $data['session_len'] ? unserialize(substr($data['data'], 0, $data['session_len'])) : [];
        $extra = substr($data['data'], $data['session_len']);
        $client = bin2hex(pack('NnN', ip2long($address['lan_host']), $address['lan_port'], $data['fd']));
        switch ($data['cmd']) {

            case Protocol::EVENT_CONNECT:
                $this->dispatch('onConnect', $client, $session);
                break;

            case Protocol::EVENT_RECEIVE:
                $this->dispatch('onReceive', $client, $session, $extra);
                break;

            case Protocol::EVENT_CLOSE:
                $this->dispatch('onClose', $client, $session, unserialize($extra));
                break;

            case Protocol::EVENT_OPEN:
                $this->dispatch('onOpen', $client, $session, unserialize($extra));
                break;

            case Protocol::EVENT_MESSAGE:
                $frame = unpack('Copcode/Cflags', $extra);
                $frame['data'] = substr($extra, 2);
                $this->dispatch('onMessage', $client, $session, $frame);
                break;

            default:
                Service::debug("undefined cmd from gateway! cmdcode:{$data['cmd']}");
                break;
        }
    }
}
