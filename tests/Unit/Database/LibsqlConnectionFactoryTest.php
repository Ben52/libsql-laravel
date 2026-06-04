<?php

use Libsql\Laravel\Database\LibsqlConnectionFactory;

/**
 * Exposes the protected url-resolution logic so we can assert how the factory
 * turns a connection config array into a libSQL connection url.
 */
function makeFactory(): LibsqlConnectionFactory
{
    return new class(app()) extends LibsqlConnectionFactory {
        public function exposeResolveUrl(array $config): string
        {
            return $this->resolveUrl($config);
        }
    };
}

test('it keeps an explicit url for modern Turso (url + auth token) configuration', function () {
    $factory = makeFactory();

    // Modern style: TURSO_DATABASE_URL + TURSO_AUTH_TOKEN, no host/port.
    $url = $factory->exposeResolveUrl([
        'driver' => 'libsql',
        'url' => 'libsql://my-db.turso.io',
        'password' => 'a-secret-token',
    ]);

    expect($url)->toBe('libsql://my-db.turso.io');
})->group('LibsqlConnectionFactoryTest', 'UnitTest');

test('it builds a url from legacy host configuration', function () {
    $factory = makeFactory();

    $url = $factory->exposeResolveUrl([
        'driver' => 'libsql',
        'host' => '127.0.0.1',
    ]);

    expect($url)->toBe('libsql://127.0.0.1');
})->group('LibsqlConnectionFactoryTest', 'UnitTest');

test('it builds a url from legacy host and port configuration', function () {
    $factory = makeFactory();

    $url = $factory->exposeResolveUrl([
        'driver' => 'libsql',
        'host' => '127.0.0.1',
        'port' => 8080,
    ]);

    expect($url)->toBe('libsql://127.0.0.1:8080');
})->group('LibsqlConnectionFactoryTest', 'UnitTest');

test('it leaves the url empty for a local-only configuration', function () {
    $factory = makeFactory();

    // No url and no host => local/embedded database, url must stay empty.
    $url = $factory->exposeResolveUrl([
        'driver' => 'libsql',
        'database' => ':memory:',
    ]);

    expect($url)->toBe('');
})->group('LibsqlConnectionFactoryTest', 'UnitTest');

test('it prefers an explicit url over legacy host when both are present', function () {
    $factory = makeFactory();

    $url = $factory->exposeResolveUrl([
        'driver' => 'libsql',
        'url' => 'libsql://my-db.turso.io',
        'host' => '127.0.0.1',
        'port' => 8080,
    ]);

    expect($url)->toBe('libsql://my-db.turso.io');
})->group('LibsqlConnectionFactoryTest', 'UnitTest');
