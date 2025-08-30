<?php

namespace Timmylindh\LaravelBatchDispatcher\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Timmylindh\LaravelBatchDispatcher\BatchManager;

class BatchRequests
{
    public function handle(Request $request, Closure $next)
    {
        $manager = app(BatchManager::class);
        $manager->begin();

        $response = $next($request);

        if (method_exists($this, 'terminate')) {
            // noop; Laravel will call terminate
        }

        return $response;
    }

    public function terminate($request, $response)
    {
        app(BatchManager::class)->commit();
    }
}
