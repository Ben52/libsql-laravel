<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->pdo = DB::connection()->getPdo();
});

test('it can manage the last insert id value', function () {
    $this->pdo->setLastInsertId(value: 123);

    expect($this->pdo->lastInsertId())->toBe('123');
})->group('LibsqlPDOTest', 'UnitTest');

test('it reports sqlite as the driver name attribute', function () {
    // Laravel's database queue driver reads ATTR_DRIVER_NAME on every pop();
    // libSQL is SQLite-compatible, so it must answer 'sqlite'.
    expect($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('sqlite');
})->group('LibsqlPDOTest', 'UnitTest');

test('it reports the sqlite version for the server version attribute', function () {
    expect($this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION))->toBe($this->pdo->version());
})->group('LibsqlPDOTest', 'UnitTest');

test('it returns null for unmodeled pdo attributes', function () {
    expect($this->pdo->getAttribute(PDO::ATTR_ERRMODE))->toBeNull();
})->group('LibsqlPDOTest', 'UnitTest');
