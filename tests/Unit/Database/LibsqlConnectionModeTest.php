<?php

use Libsql\Laravel\Database\LibsqlDatabase;

/**
 * detectConnectionMode() must distinguish a local file database (a path with
 * no url) from an in-memory one. Remote/embedded-replica modes always carry a
 * url, so only the local vs memory split is exercised here (no network).
 */

test('it uses local mode for a file database without a url', function () {
    $db = new LibsqlDatabase([
        'database' => test_database_path('mode_probe.db'),
        'url' => '',
        'password' => '',
    ]);

    expect($db->getConnectionMode())->toBe('local');
})->group('LibsqlConnectionModeTest', 'UnitTest');

test('it uses memory mode for an in-memory database', function () {
    $db = new LibsqlDatabase([
        'database' => ':memory:',
        'url' => '',
        'password' => '',
    ]);

    expect($db->getConnectionMode())->toBe('memory');
})->group('LibsqlConnectionModeTest', 'UnitTest');

test('it uses memory mode when neither database nor url is provided', function () {
    $db = new LibsqlDatabase([
        'database' => '',
        'url' => '',
        'password' => '',
    ]);

    expect($db->getConnectionMode())->toBe('memory');
})->group('LibsqlConnectionModeTest', 'UnitTest');
