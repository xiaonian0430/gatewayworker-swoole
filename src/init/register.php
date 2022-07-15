<?php

use Swoole\Server as SWServer;
use Swoole\Server\Event as SWSEvent;
use Swoole\Timer as SWTimer;
use SwooleGateway\Protocol;
use SwooleGateway\Library\Config;
use SwooleGateway\Register;
use SwooleGateway\Service;

/**
 * @var Register $this
 */

$this->on('Connect', function (SWServer $server, SWSEvent $event) {
    SWTimer::after(3000, function () use ($server, $event) {
        if (
            $this->globals->isset('gateway_address_list.' . $event->fd) ||
            $this->globals->isset('worker_fd_list.' . $event->fd)
        ) {
            return;
        }
        if ($server->exist($event->fd)) {
            Service::debug("close timeout fd:{$event->fd}");
            $server->close($event->fd);
        }
    });
});

$this->on('Receive', function (SWServer $server, SWSEvent $event) {
    $data = unpack('Ccmd/A*load', Protocol::decode($event->data));
    switch ($data['cmd']) {
        case Protocol::GATEWAY_CONNECT:
            $load = unpack('Nlan_host/nlan_port', $data['load']);
            $load['register_secret'] = substr($data['load'], 6);
            if (Config::get('register_secret', '') && $load['register_secret'] !== Config::get('register_secret', '')) {
                Service::debug("GATEWAY_CONNECT failure. register_secret invalid~");
                $server->close($event->fd);
                return;
            }
            $this->globals->set('gateway_address_list.' . $event->fd, pack('Nn', $load['lan_host'], $load['lan_port']));
            $this->broadcastGatewayAddressList();
            break;

        case Protocol::WORKER_CONNECT:
            if (Config::get('register_secret', '') && ($data['load'] !== Config::get('register_secret', ''))) {
                Service::debug("WORKER_CONNECT failure. register_secret invalid~");
                $server->close($event->fd);
                return;
            }
            $this->globals->set('worker_fd_list.' . $event->fd, $event->fd);
            $this->broadcastGatewayAddressList($event->fd);
            break;

        case Protocol::PING:
            break;

        default:
            Service::debug("undefined cmd and closed by register");
            $server->close($event->fd);
            break;
    }
});

$this->on('Close', function (SWServer $server, SWSEvent $event) {
    if ($this->globals->isset('worker_fd_list.' . $event->fd)) {
        $this->globals->unset('worker_fd_list.' . $event->fd);
    }
    if ($this->globals->isset('gateway_address_list.' . $event->fd)) {
        $this->globals->unset('gateway_address_list.' . $event->fd);
        $this->broadcastGatewayAddressList();
    }
});
