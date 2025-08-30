# laravel-batch-dispatcher

[![Latest Version on Packagist](https://img.shields.io/packagist/v/timmylindh/laravel-beanstalk-worker.svg?style=flat-square)](https://packagist.org/packages/timmylindh/laravel-beanstalk-worker)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/timmylindh/laravel-beanstalk-worker/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/timmylindh/laravel-beanstalk-worker/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/timmylindh/laravel-beanstalk-worker/check-code-formatting.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/timmylindh/laravel-beanstalk-worker/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/timmylindh/laravel-beanstalk-worker.svg?style=flat-square)](https://packagist.org/packages/timmylindh/laravel-beanstalk-worker)

Batch queued jobs and queued event listeners into a single queued job. This reduces the number of queue requests by capturing multiple dispatches and sending them as one job that processes all items.

The package supports all Laravel queue and cron features, such as retries, backoff, delay, release, max tries, timeout, etc.

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
    'enabled' => env('BATCH_DISPATCHER_ENABLED', true),

    // In testing, run the wrapper synchronously so assertions are easy
    'synchronous_testing' => env('BATCH_DISPATCHER_SYNC_TESTING', app()->environment('testing')),
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
