<?php
namespace Timmylindh\LaravelBatchDispatcher\Events;

use Timmylindh\LaravelBatchDispatcher\BatchManager;

use Illuminate\Events\Dispatcher;

class BatchEventDispatcher extends Dispatcher
{
    protected function queueHandler($class, $method, $event)
    {
        $batchManager = app(BatchManager::class);

        if ($batchManager->isBatching()) {
            // Capture the listener job call instead of pushing onto queue
            $batchManager->addEventListener(
                $class,
                $method,
                is_array($event) ? $event : [$event],
            );
            return;
        }

        parent::queueHandler($class, $method, $event);
    }

    /**
     * Create a callable for putting an event handler on the queue.
     *
     * @param  string  $class
     * @param  string  $method
     * @return \Closure
     */
    protected function createQueuedHandlerCallable($class, $method)
    {
        return function () use ($class, $method) {
            $arguments = array_map(function ($a) {
                return is_object($a) ? clone $a : $a;
            }, func_get_args());

            if ($this->handlerWantsToBeQueued($class, $arguments)) {
                $this->queueHandler($class, $method, $arguments);
            }
        };
    }
}
