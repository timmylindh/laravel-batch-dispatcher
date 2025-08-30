<?php

namespace Timmylindh\LaravelBatchDispatcher;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcherContract;
use Illuminate\Support\ServiceProvider;
use Timmylindh\LaravelBatchDispatcher\Bus\BatchingDispatcher;
use Timmylindh\LaravelBatchDispatcher\Http\Middleware\BatchRequests;
use Illuminate\Bus\Dispatcher as ConcreteBusDispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactoryContract;
use Timmylindh\LaravelBatchDispatcher\Events\BatchEventDispatcher;

class LaravelBatchDispatcherServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/batch-dispatcher.php',
            'batch-dispatcher',
        );

        $this->app->singleton(BatchManager::class, fn() => new BatchManager());
        $this->app->singleton(
            BatchingDispatcher::class,
            fn($app) => new BatchingDispatcher(
                $app,
                fn($connection = null) => $app[
                    QueueFactoryContract::class
                ]->connection($connection),
            ),
        );
        $this->app->singleton(
            BatchEventDispatcher::class,
            fn($app) => (new BatchEventDispatcher($app))->setQueueResolver(
                fn() => $app['queue'],
            ),
        );

        if (!config('batch-dispatcher.enabled')) {
            return;
        }

        // Replace the core concrete so contracts & facade resolve to our dispatcher
        $this->app->singleton(
            ConcreteBusDispatcher::class,
            fn($app) => $app->make(BatchingDispatcher::class),
        );

        // Ensure resolving the contract or 'bus' alias returns our concrete dispatcher
        $this->app->alias(
            ConcreteBusDispatcher::class,
            BusDispatcherContract::class,
        );
        $this->app->alias(ConcreteBusDispatcher::class, 'bus');

        // If something resolved earlier, enforce our dispatcher consistently
        $this->app->extend(
            ConcreteBusDispatcher::class,
            fn($dispatcher, $app) => when(
                $dispatcher instanceof BatchingDispatcher,
                $dispatcher,
                fn() => $app->make(BatchingDispatcher::class),
            ),
        );
        $this->app->extend(
            BusDispatcherContract::class,
            fn($dispatcher, $app) => when(
                $dispatcher instanceof BatchingDispatcher,
                $dispatcher,
                fn() => $app->make(BatchingDispatcher::class),
            ),
        );
        $this->app->extend(
            'bus',
            fn($dispatcher, $app) => when(
                $dispatcher instanceof BatchingDispatcher,
                $dispatcher,
                fn() => $app->make(ConcreteBusDispatcher::class),
            ),
        );

        // Bind our event dispatcher that can capture queued events during batching
        $this->app->extend(
            EventsDispatcherContract::class,
            fn($dispatcher, $app) => when(
                $dispatcher instanceof BatchEventDispatcher,
                $dispatcher,
                fn() => $app->make(BatchEventDispatcher::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->publishes(
            [
                __DIR__ . '/../config/batch-dispatcher.php' => config_path(
                    'batch-dispatcher.php',
                ),
            ],
            'laravel-batch-dispatcher-config',
        );

        if (!config('batch-dispatcher.enabled')) {
            return;
        }

        if (config('batch-dispatcher.enable_middleware')) {
            $this->app['router']->pushMiddlewareToGroup(
                'web',
                BatchRequests::class,
            );
            $this->app['router']->pushMiddlewareToGroup(
                'api',
                BatchRequests::class,
            );
        }
    }
}
