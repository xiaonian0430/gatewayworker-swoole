<?php

declare(strict_types=1);

namespace SwooleGateway\Interfaces;

use Swoole\Server\PipeMessage as SWSPipeMessage;
use Swoole\Server\Task as SWSTask;

interface TaskEventInterface
{
    /**
     * worker start
     *
     * @return void
     */
    public function onWorkerStart();

    /**
     * worker stop
     *
     * @return void
     */
    public function onWorkerStop();

    /**
     * worker exit
     *
     * @return void
     */
    public function onWorkerExit();

    /**
     * pipe message
     *
     * @param SWSPipeMessage $pipeMessage
     * @return void
     */
    public function onPipeMessage(SWSPipeMessage $pipeMessage);

    /**
     * task
     *
     * @param SWSTask $task
     * @return void
     */
    public function onTask(SWSTask $task);
}
