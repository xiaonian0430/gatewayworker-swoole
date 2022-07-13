<?php

declare(strict_types=1);

namespace SwooleGateway\Helper;

use Swoole\Server\PipeMessage;
use Swoole\Server\TaskResult;
use SwooleGateway\Interfaces\WorkerEventInterface;
use SwooleGateway\Worker;

class WorkerEvent implements WorkerEventInterface
{
    public $worker;

    public function __construct(Worker $worker)
    {
        $this->worker = $worker;
    }

    public function onWorkerStart()
    {
    }

    public function onWorkerExit()
    {
    }

    public function onWorkerStop()
    {
    }

    public function onFinish(TaskResult $taskResult)
    {
    }

    public function onPipeMessage(PipeMessage $pipeMessage)
    {
    }

    public function onConnect(string $client, array $session)
    {
    }

    public function onReceive(string $client, array $session, string $data)
    {
    }

    public function onOpen(string $client, array $session, array $request)
    {
        $this->onConnect($client, $session);
    }

    public function onMessage(string $client, array $session, array $frame)
    {
        switch ($frame['opcode']) {
            case WEBSOCKET_OPCODE_TEXT:
            case WEBSOCKET_OPCODE_BINARY:
                $this->onReceive($client, $session, $frame['data']);
                break;

            default:
                break;
        }
    }

    public function onClose(string $client, array $session, array $bind)
    {
    }
}
