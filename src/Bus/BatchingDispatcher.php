<?php

namespace Timmylindh\LaravelBatchDispatcher\Bus;

use Illuminate\Bus\Dispatcher as BaseDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Timmylindh\LaravelBatchDispatcher\BatchManager;

class BatchingDispatcher extends BaseDispatcher
{
    public function dispatchToQueue($command)
    {
        if ($command instanceof ShouldQueue) {
            $batchManager = app(BatchManager::class);

            // Do not buffer sync-connection jobs; they should run immediately
            $connection = property_exists($command, 'connection')
                ? $command->connection
                : null;

            if ($batchManager->isBatching() && $connection !== 'sync') {
                $batchManager->addJob($command);

                return $command;
            }
        }

        return parent::dispatchToQueue($command);
    }

    public function dispatch($command)
    {
        if ($command instanceof ShouldQueue) {
            $batchManager = app(BatchManager::class);
            if ($batchManager->isBatching()) {
                $batchManager->addJob($command);
                return $command;
            }
        }

        return parent::dispatch($command);
    }
}
