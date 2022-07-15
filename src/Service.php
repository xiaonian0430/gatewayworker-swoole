<?php

declare(strict_types=1);

namespace SwooleGateway;
use Swoole\Process as SWProcess;
use Swoole\Server as SWServer;
use SwooleGateway\Library\Config;
use SwooleGateway\Library\Globals;

/**
 * @property Globals $globals
 */
abstract class Service
{
    public $config_file;

    public $pid_file;
    protected $daemonize = false;

    protected $server;
    protected $globals;

    protected $events;

    protected $config = [];

    public function __construct()
    {

    }

    /**
     * 开启服务
     */
    public function start()
    {
        $server = $this->createServer();
        $this->globals = new Globals();
        $this->globals->mountTo($server);

        $server->on('WorkerStart', function (...$args) {
            Config::load($this->config_file);
            if (Config::isset('init_file')) {
                include Config::get('init_file');
            }
            $this->emit('WorkerStart', ...$args);
        });

        foreach (['WorkerExit', 'WorkerStop', 'PipeMessage', 'Task', 'Finish', 'Connect', 'Receive', 'Close', 'Open', 'Message', 'Request', 'Packet'] as $event) {
            $server->on($event, function (...$args) use ($event) {
                $this->emit($event, ...$args);
            });
        }

        $server->set(array_merge($this->config, [
            'pid_file' => $this->pid_file,
            'daemonize' => $this->daemonize,
            'event_object' => true,
            'task_object' => true,
            'reload_async' => true,
            'max_wait_time' => 60,
            'enable_coroutine' => true,
            'task_enable_coroutine' => true,
        ]));
        $this->server = $server;
        $server->start();
    }

    abstract protected function createServer(): SWServer;

    public function set(array $config = [])
    {
        $this->config = array_merge($this->config, $config);
    }

    protected function emit(string $event, ...$args)
    {
        $event = strtolower('on' . $event);
        Service::debug("emit {$event}");
        call_user_func($this->events[$event] ?? function () {
        }, ...$args);
    }

    protected function on(string $event, callable $callback)
    {
        $event = strtolower('on' . $event);
        $this->events[$event] = $callback;
    }

    public function getServer(): SWServer
    {
        return $this->server;
    }

    /**
     * @deprecated Please use redis or other. The next version will be deprecated
     */
    public function getGlobals(): Globals
    {
        return $this->globals;
    }

    public static function debug(string $info)
    {
        if (Config::get('debug', false)) {
            fwrite(STDOUT, '[' . date(DATE_ISO8601) . ']' . " {$info}\n");
        }
    }
}
