 <?php
 use Illuminate\Contracts\Queue\ShouldQueue;
 use Illuminate\Events\Dispatcher as EventsDispatcher;
 use Illuminate\Support\Facades\Route;
 use Illuminate\Support\Facades\Bus;
 use Illuminate\Support\Facades\Queue;
 use Timmylindh\LaravelBatchDispatcher\BatchManager;
 use Timmylindh\LaravelBatchDispatcher\Jobs\ProcessBatch;

 class CountAccumulator
 {
     public static int $count = 0;
 }
 class CountJobA implements ShouldQueue
 {
     public function handle()
     {
         CountAccumulator::$count++;
     }
 }
 class CountJobB implements ShouldQueue
 {
     public function handle()
     {
         CountAccumulator::$count++;
     }
 }

 it('batches multiple queued jobs and runs them at request end', function () {
     CountAccumulator::$count = 0;

     Route::get('/batch-jobs', function () {
         dispatch(new CountJobA());
         dispatch(new CountJobB());
         return 'ok';
     });

     $this->get('/batch-jobs')->assertOk();

     expect(CountAccumulator::$count)->toBe(2);
 });

 class TestQueuedEvent implements ShouldQueue
 {
 }

 class TestQueuedListener implements ShouldQueue
 {
     public static bool $handled = false;
     public function handle($event): void
     {
         self::$handled = true;
     }
 }

 it('batches queued event listeners and runs them at request end', function () {
     // Register a queued listener class for our queued event
     app(EventsDispatcher::class)->listen(
         TestQueuedEvent::class,
         TestQueuedListener::class,
     );

     // Ensure static flag reset
     TestQueuedListener::$handled = false;

     Route::get('/batch-events', function () {
         event(new TestQueuedEvent());
         return 'ok';
     });

     $this->get('/batch-events')->assertOk();

     expect(TestQueuedListener::$handled)->toBeTrue();
 });

 class NonQueuedEvent
 {
 }
 class NonQueuedListener
 {
     public static int $count = 0;
     public function handle(NonQueuedEvent $e)
     {
         self::$count++;
     }
 }

 it('non-queued listeners run immediately and are not batched', function () {
     NonQueuedListener::$count = 0;
     app(EventsDispatcher::class)->listen(
         NonQueuedEvent::class,
         NonQueuedListener::class,
     );

     Route::get('/non-queued', function () {
         event(new NonQueuedEvent());
         return 'ok';
     });

     Bus::fake();

     $this->get('/non-queued')->assertOk();

     expect(NonQueuedListener::$count)->toBe(1);
     Bus::assertNotDispatched(
         \Timmylindh\LaravelBatchDispatcher\Jobs\ProcessBatch::class,
     );
 });

 class ShouldQueueEvent implements ShouldQueue
 {
 }
 class ConditionallyQueuedListener implements ShouldQueue
 {
     public static bool $handled = false;
     public function shouldQueue($event): bool
     {
         return false;
     }
     public function handle($event): void
     {
         self::$handled = true;
     }
 }

 it(
     'respects shouldQueue on listeners (not queued nor executed when false)',
     function () {
         ConditionallyQueuedListener::$handled = false;
         app(EventsDispatcher::class)->listen(
             ShouldQueueEvent::class,
             ConditionallyQueuedListener::class,
         );

         Route::get('/should-queue-false', function () {
             event(new ShouldQueueEvent());
             return 'ok';
         });

         Bus::fake();

         $this->get('/should-queue-false')->assertOk();

         expect(ConditionallyQueuedListener::$handled)->toBeFalse();
         Bus::assertNotDispatched(
             \Timmylindh\LaravelBatchDispatcher\Jobs\ProcessBatch::class,
         );
     },
 );

 it(
     'preserves per-job connection/queue/delay when dispatching from wrapper',
     function () {
         Bus::fake();
         config()->set('batch-dispatcher.synchronous_testing', false);

         $job = new class implements ShouldQueue {
             public $connection;
             public $queue;
             public $delay;
             public function onConnection($c)
             {
                 $this->connection = $c;
                 return $this;
             }
             public function onQueue($q)
             {
                 $this->queue = $q;
                 return $this;
             }
             public function delay($d)
             {
                 $this->delay = $d;
                 return $this;
             }
             public function handle()
             {
             }
         };

         $job->onConnection('sqs')->onQueue('high')->delay(5);
         $batch = new \Timmylindh\LaravelBatchDispatcher\Jobs\ProcessBatch(
             [[$job, ['connection' => 'sqs', 'queue' => 'high', 'delay' => 5]]],
             [],
         );
         $batch->handle();

         Bus::assertDispatched(get_class($job), function ($dispatched) {
             return ($dispatched->connection ?? null) === 'sqs' &&
                 ($dispatched->queue ?? null) === 'high' &&
                 ($dispatched->delay ?? null) === 5;
         });
     },
 );

 it(
     'dispatch inside non-queued listener is still batched until request end',
     function () {
         CountAccumulator::$count = 0;

         app(EventsDispatcher::class)->listen(
             NonQueuedEvent::class,
             function () {
                 dispatch(new CountJobA());
                 dispatch(new CountJobB());
             },
         );

         Route::get('/nested-dispatch', function () {
             event(new NonQueuedEvent());
             return 'ok';
         });

         $this->get('/nested-dispatch')->assertOk();

         expect(CountAccumulator::$count)->toBe(2);
     },
 );

 it(
     'dispatches exactly three ProcessBatch jobs when 7 items with batch size 3',
     function () {
         config()->set('batch-dispatcher.synchronous_testing', false);
         config()->set('batch-dispatcher.max_batch_size', 3);

         Queue::fake();

         $manager = app(BatchManager::class);
         $manager->begin();
         for ($i = 0; $i < 7; $i++) {
             Bus::dispatch(new CountJobA());
         }
         $manager->commit();

         Queue::assertPushed(ProcessBatch::class, 3);
     },
 );

 it(
     'queues no ProcessBatch when no queued event listeners are captured',
     function () {
         config()->set('batch-dispatcher.synchronous_testing', false);

         app(EventsDispatcher::class)->listen(
             NonQueuedEvent::class,
             NonQueuedListener::class,
         );

         Queue::fake();

         $manager = app(BatchManager::class);
         $manager->begin();
         event(new NonQueuedEvent());
         $manager->commit();

         Queue::assertNotPushed(ProcessBatch::class);
     },
 );

 it(
     'queues ProcessBatch once when queued event listeners are captured',
     function () {
         config()->set('batch-dispatcher.synchronous_testing', false);

         // Register queued listener
         app(EventsDispatcher::class)->listen(
             TestQueuedEvent::class,
             TestQueuedListener::class,
         );

         Queue::fake();

         $manager = app(BatchManager::class);
         $manager->begin();
         event(new TestQueuedEvent());
         event(new TestQueuedEvent());
         $manager->commit();

         Queue::assertPushed(ProcessBatch::class, 1);
     },
 );

 it(
     'queues a single ProcessBatch for mixed jobs and queued event listeners',
     function () {
         config()->set('batch-dispatcher.synchronous_testing', false);

         // Register queued listener
         app(EventsDispatcher::class)->listen(
             TestQueuedEvent::class,
             TestQueuedListener::class,
         );

         Queue::fake();

         $manager = app(BatchManager::class);
         $manager->begin();
         dispatch(new CountJobA());
         event(new TestQueuedEvent());
         dispatch(new CountJobB());
         $manager->commit();

         Queue::assertPushed(ProcessBatch::class, 1);
     },
 );

 it(
     'does not buffer dispatchSync of a queued job into a ProcessBatch',
     function () {
         config()->set('batch-dispatcher.synchronous_testing', false);

         Queue::fake();

         $manager = app(BatchManager::class);
         CountAccumulator::$count = 0;

         $manager->begin();
         Bus::dispatchSync(new CountJobA());
         $manager->commit();

         // Job executed immediately and no ProcessBatch was queued
         expect(CountAccumulator::$count)->toBe(1);
         Queue::assertNotPushed(ProcessBatch::class);
     },
 );

 it(
     'does not wrap a single queued job in ProcessBatch (direct dispatch)',
     function () {
         config()->set('batch-dispatcher.synchronous_testing', false);

         Queue::fake();

         $manager = app(BatchManager::class);
         $manager->begin();
         dispatch(new CountJobA());
         $manager->commit();

         Queue::assertNotPushed(ProcessBatch::class);
         Queue::assertPushed(CountJobA::class, 1);
     },
 );

 it(
     'does not wrap a single queued listener in ProcessBatch (direct dispatch)',
     function () {
         config()->set('batch-dispatcher.synchronous_testing', false);

         app(EventsDispatcher::class)->listen(
             TestQueuedEvent::class,
             TestQueuedListener::class,
         );

         Queue::fake();

         $manager = app(BatchManager::class);
         $manager->begin();
         event(new TestQueuedEvent());
         $manager->commit();

         Queue::assertNotPushed(ProcessBatch::class);
     },
 );

