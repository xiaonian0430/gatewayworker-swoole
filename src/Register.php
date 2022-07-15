<?php
/**
 * 注册中心
 */
declare(strict_types=1);

namespace SwooleGateway;

use Swoole\Server as SWServer;
use SwooleGateway\Library\Config;

class Register extends Service
{
    protected $register_host;
    protected $register_port;

    public function __construct(string $register_host = '127.0.0.1', int $register_port = 9327)
    {
        parent::__construct();
        Config::set('init_file', __DIR__ . '/init/register.php');
        $this->register_host = $register_host;
        $this->register_port = $register_port;
    }

    protected function createServer(): SWServer
    {
        $server = new SWServer($this->register_host, $this->register_port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        $this->set([
            'heartbeat_idle_time' => 60,
            'heartbeat_check_interval' => 3,
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 0,
        ]);
        return $server;
    }

    protected function broadcastGatewayAddressList(int $fd = null)
    {
        $load = pack('C', Protocol::BROADCAST_GATEWAY_LIST) . implode('', $this->globals->get('gateway_address_list', []));
        $buffer = Protocol::encode($load);
        if ($fd) {
            $this->getServer()->send($fd, $buffer);
        } else {
            foreach ($this->globals->get('worker_fd_list', []) as $fd => $info) {
                $this->getServer()->send($fd, $buffer);
            }
        }

        $addresses = [];
        foreach ($this->globals->get('gateway_address_list', []) as $value) {
            $tmp = unpack('Nhost/nport', $value);
            $tmp['host'] = long2ip($tmp['host']);
            $addresses[] = $tmp;
        }
        $addresses = json_encode($addresses);
        if ($fd) {
            Service::debug("broadcastGatewayAddressList fd:{$fd} addresses:{$addresses}");
        } else {
            Service::debug("broadcastGatewayAddressList addresses:{$addresses}");
        }
    }
}
