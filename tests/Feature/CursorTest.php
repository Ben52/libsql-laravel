<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Libsql\Laravel\Tests\Fixtures\Models\User;

/**
 * Regression: cursor() must work on the libSQL connection. The base
 * Connection::cursor() pipes the statement through prepared(\PDOStatement),
 * which a LibsqlStatement is not — so without an override it throws a TypeError.
 */

beforeEach(function () {
    migrateTables('users');
});

afterEach(function () {
    Schema::dropAllTables();
});

test('the query builder cursor() streams every row', function () {
    User::factory()->count(3)->create();

    $names = [];
    foreach (DB::table('users')->orderBy('id')->cursor() as $row) {
        $names[] = $row->name;
    }

    expect($names)->toHaveCount(3);
})->group('CursorTest', 'FeatureTest');

test('eloquent cursor() streams every row', function () {
    User::factory()->count(3)->create();

    $count = 0;
    foreach (User::query()->orderBy('id')->cursor() as $user) {
        expect($user)->toBeInstanceOf(User::class);
        $count++;
    }

    expect($count)->toBe(3);
})->group('CursorTest', 'FeatureTest');

test('cursor() returns no rows for an empty table', function () {
    $rows = iterator_to_array(DB::table('users')->cursor());

    expect($rows)->toBe([]);
})->group('CursorTest', 'FeatureTest');
