# laravel-batch-dispatcher

[![Latest Version on Packagist](https://img.shields.io/packagist/v/timmylindh/laravel-batch-dispatcher.svg?style=flat-square)](https://packagist.org/packages/timmylindh/laravel-batch-dispatcher)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/timmylindh/laravel-batch-dispatcher/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/timmylindh/laravel-batch-dispatcher/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/timmylindh/laravel-batch-dispatcher/check-code-formatting.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/timmylindh/laravel-batch-dispatcher/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/timmylindh/laravel-batch-dispatcher.svg?style=flat-square)](https://packagist.org/packages/timmylindh/laravel-batch-dispatcher)

Batch queued jobs and queued event listeners into a single queued job. This reduces the number of queue requests by capturing multiple dispatches and sending them as one job that processes all items.

## Installation

Requires:

- Laravel >= 10
- PHP >= 8.1

You can install the package via composer:

```bash
composer require timmylindh/laravel-batch-dispatcher
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-batch-dispatcher-config"
```

## Usage

### Behavior

- All calls to `dispatch()`, `SomeJob::dispatch()`, and `Event::dispatch()` will be buffered during the request.
- On terminate, the package queues a single wrapper job which in turn dispatches all buffered jobs and queued listeners.

## Configuration

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-batch-dispatcher-config"
```

The batching behavior is controlled by `config/batch-dispatcher.php`:

```php
return [
  "enabled" => env("BATCH_DISPATCHER_ENABLED", true),

  /**
   * In testing, avoid serializing jobs and run the wrapper immediately for assertions
   */
  "synchronous_testing" => env(
    "BATCH_DISPATCHER_SYNC_TESTING",
    env("APP_ENV") === "testing"
  ),

  /**
   * Maximum number of buffered items (jobs + queued listeners + queued events)
   * per wrapper job. When exceeded, multiple wrapper jobs will be dispatched.
   */
  "max_batch_size" => env("BATCH_DISPATCHER_MAX_SIZE", 10),

  /**
   * Enable the middleware to batch the requests.
   * Otherwise you will have to manually wrap the requests in the middleware.
   */
  "enable_middleware" => env("BATCH_DISPATCHER_ENABLE_MIDDLEWARE", true),
];
```

### Notes

- Only instances of jobs implementing `ShouldQueue` and queued event listeners are batched.
- Per-job queue options (connection/queue/delay) are respected when listeners are enqueued by the wrapper. Jobs are dispatched as usual by the wrapper.

## How it works

During the request, we intercept:

- Bus dispatches of `ShouldQueue` jobs and store the job instances in memory
- Event dispatches with queued listeners and capture their queued calls

On terminate, a single `ProcessBatch` job is queued. It then dispatches the buffered jobs and enqueues/invokes listeners.

## Testing

```bash
composer test
```

## Credits

- [Timmy Lindholm](https://github.com/timmylindh)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
