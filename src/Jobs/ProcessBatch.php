<?php

namespace Timmylindh\LaravelBatchDispatcher\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBatch implements ShouldQueue
{
    use Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels,
        Batchable;

    public $tries = 1;

    /** @var array<int, array{0: object, 1: array{connection: string|null, queue: string|null, delay: mixed}}> */
    public array $jobs;

    /** @var array<int, array{0: class-string, 1: string, 2: array}> */
    public array $listenerCalls;

    /**
     * @param array<int, array{0: object, 1: array{connection: string|null, queue: string|null, delay: mixed}}> $jobs
     * @param array<int, array{0: class-string, 1: string, 2: array}> $listenerCalls
     */
    public function __construct(array $jobs, array $listenerCalls = [])
    {
        $this->jobs = $jobs;
        $this->listenerCalls = $listenerCalls;
    }

    public function handle(): void
    {
        $bus = app(BusDispatcherContract::class);
        $queues = app(QueueManager::class);

        if (config('batch-dispatcher.synchronous_testing')) {
            foreach ($this->jobs as [$job, $opts]) {
                if (method_exists($job, 'handle')) {
                    $job->handle();
                } else {
                    $bus->dispatchSync($job);
                }
            }

            foreach ($this->listenerCalls as [$class, $method, $arguments]) {
                app($class)->{$method}(...$arguments);
            }
        } else {
            // Queue all buffered jobs
            foreach ($this->jobs as [$job, $opts]) {
                if ($opts['connection']) {
                    $job->onConnection($opts['connection']);
                }
                if ($opts['queue']) {
                    $job->onQueue($opts['queue']);
                }
                if ($opts['delay']) {
                    $job->delay($opts['delay']);
                }
                $bus->dispatch($job);
            }

            // Queue all buffered event listeners from captured listener calls
            foreach ($this->listenerCalls as [$class, $method, $arguments]) {
                $listenerInstance = (new \ReflectionClass(
                    $class,
                ))->newInstanceWithoutConstructor();

                $connectionName = method_exists(
                    $listenerInstance,
                    'viaConnection',
                )
                    ? (isset($arguments[0])
                        ? $listenerInstance->viaConnection($arguments[0])
                        : $listenerInstance->viaConnection())
                    : $listenerInstance->connection ?? null;

                $queueName = method_exists($listenerInstance, 'viaQueue')
                    ? (isset($arguments[0])
                        ? $listenerInstance->viaQueue($arguments[0])
                        : $listenerInstance->viaQueue())
                    : $listenerInstance->queue ?? null;

                $delay = method_exists($listenerInstance, 'withDelay')
                    ? (isset($arguments[0])
                        ? $listenerInstance->withDelay($arguments[0])
                        : $listenerInstance->withDelay())
                    : $listenerInstance->delay ?? null;

                $job = new CallQueuedListener($class, $method, $arguments);

                $connection = $queues->connection($connectionName);

                is_null($delay)
                    ? $connection->pushOn($queueName, $job)
                    : $connection->laterOn($queueName, $delay, $job);
            }
        }
    }
}
