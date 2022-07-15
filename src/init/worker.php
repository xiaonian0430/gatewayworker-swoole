<?php

use function Composer\Autoload\includeFile;
use Swoole\Server as SWServer;
use Swoole\Server\PipeMessage as SWSPipeMessage;
use SwooleGateway\Api;
use SwooleGateway\Library\Config;
use SwooleGateway\Service;
use SwooleGateway\Worker;

/**
 * @var Worker $this
 */

$this->on('WorkerStart', function (SWServer $server, int $worker_id, ...$args) {
    $this->sendToProcess([
        'event' => 'gateway_address_list',
        'worker_id' => $worker_id,
    ]);
    Api::$address_list = &$this->gateway_address_list;
    if ($server->taskworker) {
        includeFile(Config::get('task_file', __DIR__ . '/event_task.php'));
        $this->event = new \TaskEvent($this);
    } else {
        includeFile(Config::get('worker_file', __DIR__ . '/event_worker.php'));
        $this->event = new \WorkerEvent($this);
    }
    $this->dispatch('onWorkerStart', $worker_id, ...$args);
});
$this->on('PipeMessage', function (SWServer $server, SWSPipeMessage $pipeMessage, ...$args) {
    if ($pipeMessage->worker_id >= $server->setting['worker_num'] + $server->setting['task_worker_num']) {
        $data = unserialize($pipeMessage->data);
        switch ($data['event']) {
            case 'gateway_address_list':
                $this->gateway_address_list = $data['gateway_address_list'];
                break;
            case 'gateway_event':
                $this->onGatewayMessage($data['buffer'], $data['address']);
                break;
            default:
                Service::debug("PipeMessage event not found~ data:{$pipeMessage->data}");
                break;
        }
    } else {
        $this->dispatch('onPipeMessage', $pipeMessage, ...$args);
    }
});

$this->on('WorkerExit', function (SWServer $server, ...$args) {
    $this->dispatch('onWorkerExit', ...$args);
});
$this->on('WorkerStop', function (SWServer $server, ...$args) {
    $this->dispatch('onWorkerStop', ...$args);
});
$this->on('Task', function (SWServer $server, ...$args) {
    $this->dispatch('onTask', ...$args);
});
$this->on('Finish', function (SWServer $server, ...$args) {
    $this->dispatch('onFinish', ...$args);
});
