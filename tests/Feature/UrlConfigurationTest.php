<?php

use Illuminate\Support\Facades\DB;

/**
 * These tests exercise the connection-resolution path registered by
 * LibsqlServiceProvider (DB::connection() -> $db->extend('libsql', ...)),
 * making sure the modern Turso configuration shape resolves cleanly.
 */

test('it resolves a local libsql connection defined without a prefix key', function () {
    // Mirrors the documented minimal Turso config which omits `prefix`.
    config()->set('database.connections.libsql', [
        'driver' => 'libsql',
        'database' => ':memory:',
    ]);
    DB::purge('libsql');

    $result = DB::connection('libsql')->select('select 1 as one');

    expect($result)->toBeArray()
        ->and($result[0]->one)->toEqual(1);
})->group('UrlConfigurationTest', 'FeatureTest');

test('it can run a query against an in-memory libsql connection end to end', function () {
    config()->set('database.connections.libsql', [
        'driver' => 'libsql',
        'url' => '',
        'password' => '',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    DB::purge('libsql');

    $result = DB::connection('libsql')->select('select 1 as one');

    expect($result[0]->one)->toEqual(1);
})->group('UrlConfigurationTest', 'FeatureTest');
