<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Reproduction: use the libSQL connection as Laravel's `database` queue driver.
 * Mirrors what a real app does with QUEUE_CONNECTION=database.
 */

// A trivial job that records that it ran, so we can prove the worker processed it.
class RecordingJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Bus\Queueable;
    use \Illuminate\Foundation\Bus\Dispatchable;
    use \Illuminate\Queue\InteractsWithQueue;
    use \Illuminate\Queue\SerializesModels;

    public function handle(): void
    {
        $GLOBALS['__recording_job_ran'] = ($GLOBALS['__recording_job_ran'] ?? 0) + 1;
    }
}

beforeEach(function () {
    // Standard Laravel jobs-table schema (database queue).
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'libsql',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);

    $GLOBALS['__recording_job_ran'] = 0;
});

afterEach(function () {
    Schema::dropAllTables();
});

test('a job can be pushed onto the database queue', function () {
    RecordingJob::dispatch();

    expect(Queue::connection('database')->size('default'))->toBe(1);
})->group('QueueDatabaseTest', 'FeatureTest');

test('a queued job can be popped and processed by the worker', function () {
    RecordingJob::dispatch();

    // Run the worker once, exactly like `php artisan queue:work --once`.
    $exit = $this->artisan('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--stop-when-empty' => true,
    ]);
    $exit->run();

    expect($GLOBALS['__recording_job_ran'])->toBe(1)
        ->and(Queue::connection('database')->size('default'))->toBe(0);
})->group('QueueDatabaseTest', 'FeatureTest');
