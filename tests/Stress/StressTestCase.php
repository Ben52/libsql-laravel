<?php

namespace Libsql\Laravel\Tests\Stress;

use Illuminate\Database\Eloquent\Factories\Factory;
use Libsql\Laravel\LibsqlServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base case for the stress suite. The libSQL connection is configured from the
 * LIBSQL_TEST_BACKEND env var so the SAME tests run against every mode:
 *
 *   memory   in-memory database (default; offline)
 *   file     local file database (offline)
 *   sqld     local sqld server   (docker compose up -d; offline)
 *   remote   real Turso          (needs .stress-creds.env)
 *   replica  embedded replica    (local file synced with real Turso)
 *
 * Run e.g.:  LIBSQL_TEST_BACKEND=remote vendor/bin/pest tests/Stress
 */
class StressTestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Libsql\\Laravel\\Tests\\Fixtures\\Factories\\'.class_basename($modelName).'Factory'
        );

        if (getenv('STRESS_TRACE_SQL')) {
            \Illuminate\Support\Facades\DB::beforeExecuting(function ($q) {
                fwrite(STDERR, '>>> '.$q.PHP_EOL);
            });
        }
    }

    protected function getPackageProviders($app)
    {
        return [LibsqlServiceProvider::class];
    }

    public static function backend(): string
    {
        return getenv('LIBSQL_TEST_BACKEND') ?: 'memory';
    }

    /** Whether the current backend needs (and has) real Turso credentials. */
    public static function credsAvailable(): bool
    {
        $c = self::loadCreds();

        return ! empty($c['STRESS_TURSO_URL']) && ! empty($c['STRESS_TURSO_TOKEN']);
    }

    public function getEnvironmentSetUp($app)
    {
        $creds = self::loadCreds();
        $backend = self::backend();
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'libsql_stress_'.$backend.'.db';
        @unlink($file); // fresh local file per run
        $url = $creds['STRESS_TURSO_URL'] ?? '';
        $token = $creds['STRESS_TURSO_TOKEN'] ?? '';

        $conn = match ($backend) {
            'memory' => ['database' => ':memory:', 'url' => '', 'password' => ''],
            'file' => ['database' => $file, 'url' => '', 'password' => ''],
            'sqld' => ['database' => '', 'url' => 'http://127.0.0.1:8081', 'password' => ''],
            'remote' => ['database' => '', 'url' => $url, 'password' => $token],
            'replica' => ['database' => $file, 'url' => $url, 'password' => $token],
            default => throw new \RuntimeException("Unknown LIBSQL_TEST_BACKEND: {$backend}"),
        };

        config()->set('database.connections.libsql', array_merge([
            'driver' => 'libsql',
            'prefix' => '',
        ], $conn));
        config()->set('database.default', 'libsql');
        config()->set('queue.default', 'sync');
    }

    private static function loadCreds(): array
    {
        $f = __DIR__.'/../../.stress-creds.env';
        if (! is_file($f)) {
            return [];
        }

        $out = [];
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
                $out[$m[1]] = $m[2];
            }
        }

        return $out;
    }
}
