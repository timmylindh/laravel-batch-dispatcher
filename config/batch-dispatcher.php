<?php

return [
    'enabled' => env('BATCH_DISPATCHER_ENABLED', true),

    /**
     * In testing, avoid serializing jobs and run the wrapper immediately for assertions
     */
    'synchronous_testing' => env(
        'BATCH_DISPATCHER_SYNC_TESTING',
        env('APP_ENV') === 'testing',
    ),

    /**
     * Maximum number of buffered items (jobs + queued listeners + queued events)
     * per wrapper job. When exceeded, multiple wrapper jobs will be dispatched.
     */
    'max_batch_size' => env('BATCH_DISPATCHER_MAX_SIZE', 10),

    /**
     * Enable the middleware to batch the requests.
     * Otherwise you will have to manually wrap the requests in the middleware.
     */
    'enable_middleware' => env('BATCH_DISPATCHER_ENABLE_MIDDLEWARE', true),
];
