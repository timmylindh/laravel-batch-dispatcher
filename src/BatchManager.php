<?php

namespace Timmylindh\LaravelBatchDispatcher;

use Closure;
use Illuminate\Support\Facades\Bus;
use Timmylindh\LaravelBatchDispatcher\Jobs\ProcessBatch;

class BatchManager
{
    /**
     * Indicates whether we are currently batching dispatches.
     */
    protected int $nestingLevel = 0;

    /**
     * If true, temporarily disables interception to avoid recursion when
     * executing the batch contents.
     */
    protected bool $suspended = false;

    /** @var array<int, array{0: object, 1: array{connection: string|null, queue: string|null, delay: mixed}}> */
    protected array $queuedJobs = [];

    /** @var array<int, array{0: class-string, 1: string, 2: array}> */
    protected array $queuedEventListeners = [];

    /** @var array{connection: string|null, queue: string|null, delay: mixed}|null */
    protected ?array $options = null;

    public function isBatching(): bool
    {
        return $this->nestingLevel > 0 && !$this->suspended;
    }

    public function begin(array $options = []): void
    {
        $this->nestingLevel++;
        // Only set options for the outermost batch
        if ($this->nestingLevel === 1) {
            $this->options = [
                'connection' => $options['connection'] ?? null,
                'queue' => $options['queue'] ?? null,
                'delay' => $options['delay'] ?? null,
            ];
        }
    }

    public function commit(): void
    {
        if ($this->nestingLevel === 0) {
            return;
        }

        $this->nestingLevel--;

        if ($this->nestingLevel === 0) {
            $this->flush();
        }
    }

    public function run(callable $callback, array $options = []): mixed
    {
        $this->begin($options);

        try {
            return $callback();
        } finally {
            $this->commit();
        }
    }

    public function addJob(object $job): void
    {
        $this->queuedJobs[] = [
            $job,
            [
                'connection' => property_exists($job, 'connection')
                    ? $job->connection
                    : null,
                'queue' => property_exists($job, 'queue') ? $job->queue : null,
                'delay' => property_exists($job, 'delay') ? $job->delay : null,
            ],
        ];
    }

    /**
     * @param class-string $class
     * @param string $method
     * @param array $arguments
     */
    public function addEventListener(
        string $class,
        string $method,
        array $arguments,
    ): void {
        $this->queuedEventListeners[] = [$class, $method, $arguments];
    }

    public function runWithoutBatching(Closure $callback): mixed
    {
        $this->suspended = true;
        try {
            return $callback();
        } finally {
            $this->suspended = false;
        }
    }

    protected function flush(): void
    {
        if ($this->hasNoQueuedItems()) {
            $this->reset();
            return;
        }

        $max = (int) config('batch-dispatcher.max_batch_size');

        // Fast-path: if exactly one item overall, dispatch it directly without wrapper
        if ($this->totalQueuedItems() === 1) {
            $this->runWithoutBatching(function () {
                if (count($this->queuedJobs) === 1) {
                    [$job, $opts] = $this->queuedJobs[0];
                    $this->applyJobOptions($job, $opts);
                    Bus::dispatch($job);
                } else {
                    [
                        $class,
                        $method,
                        $arguments,
                    ] = $this->queuedEventListeners[0];
                    $this->queueQueuedListener($class, $method, $arguments);
                }
            });
            $this->reset();
            return;
        }

        $items = $this->buildItemList();

        foreach (array_chunk($items, max(1, $max)) as $chunk) {
            [$jobs, $listeners] = [[], []];
            foreach ($chunk as [$type, $payload]) {
                $type === 'job'
                    ? ($jobs[] = $payload)
                    : ($listeners[] = $payload);
            }

            $this->dispatchBatchJob($jobs, $listeners);
        }

        $this->reset();
    }

    private function hasNoQueuedItems(): bool
    {
        return empty($this->queuedJobs) && empty($this->queuedEventListeners);
    }

    private function totalQueuedItems(): int
    {
        return count($this->queuedJobs) + count($this->queuedEventListeners);
    }

    /**
     * @return array<int, array{0: 'job'|'listener', 1: mixed}>
     */
    private function buildItemList(): array
    {
        $items = [];
        foreach ($this->queuedJobs as $jobPayload) {
            $items[] = ['job', $jobPayload];
        }
        foreach ($this->queuedEventListeners as $listenerPayload) {
            $items[] = ['listener', $listenerPayload];
        }
        return $items;
    }

    private function applyJobOptions(object $job, array $opts): void
    {
        if (!empty($opts['connection'])) {
            $job->onConnection($opts['connection']);
        }
        if (!empty($opts['queue'])) {
            $job->onQueue($opts['queue']);
        }
        if (!empty($opts['delay'])) {
            $job->delay($opts['delay']);
        }
    }

    private function dispatchBatchJob(array $jobs, array $listeners): void
    {
        $batchJob = new ProcessBatch($jobs, $listeners);

        if (config('batch-dispatcher.synchronous_testing')) {
            Bus::dispatchSync($batchJob);
            return;
        }

        $connection = $this->options['connection'] ?? null;
        $queueName = $this->options['queue'] ?? null;
        $delay = $this->options['delay'] ?? null;

        if ($connection) {
            $batchJob->onConnection($connection);
        }
        if ($queueName) {
            $batchJob->onQueue($queueName);
        }
        if ($delay) {
            $batchJob->delay($delay);
        }

        Bus::dispatch($batchJob);
    }

    private function queueQueuedListener(
        string $class,
        string $method,
        array $arguments,
    ): void {
        $listenerInstance = (new \ReflectionClass(
            $class,
        ))->newInstanceWithoutConstructor();

        $connectionName = method_exists($listenerInstance, 'viaConnection')
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

        $job = new \Illuminate\Events\CallQueuedListener(
            $class,
            $method,
            $arguments,
        );

        $connection = app(\Illuminate\Queue\QueueManager::class)->connection(
            $connectionName,
        );

        is_null($delay)
            ? $connection->pushOn($queueName, $job)
            : $connection->laterOn($queueName, $delay, $job);
    }

    protected function reset(): void
    {
        $this->queuedJobs = [];
        $this->queuedEventListeners = [];
        $this->options = null;
    }
}
