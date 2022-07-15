<?php

declare(strict_types=1);

namespace SwooleGateway\Helper;

use Swoole\Server\PipeMessage as SWSPipeMessage;
use Swoole\Server\Task as SWSTask;
use SwooleGateway\Interfaces\TaskEventInterface;
use SwooleGateway\Worker;

class TaskEvent implements TaskEventInterface
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

    public function onTask(SWSTask $task)
    {
    }

    public function onPipeMessage(SWSPipeMessage $pipeMessage)
    {
    }
}
