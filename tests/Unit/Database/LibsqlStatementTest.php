<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reaches the protected parameterCasting() to assert how bound values are
 * normalised before being handed to the native libSQL bind().
 */
function castParameters(array $values): array
{
    $statement = DB::connection('libsql')->getPdo()->prepare('select 1');

    $method = new ReflectionMethod($statement, 'parameterCasting');
    $method->setAccessible(true);

    return $method->invoke($statement, $values);
}

test('it casts an immutable Carbon date to a datetime string', function () {
    // Eloquent timestamps are CarbonImmutable when the app uses immutable dates,
    // which is not an instance of Illuminate\Support\Carbon.
    $result = castParameters([CarbonImmutable::parse('2026-01-02 03:04:05')]);

    expect($result[0])->toBe('2026-01-02 03:04:05');
})->group('LibsqlStatementTest', 'UnitTest');

test('it casts a native DateTimeImmutable to a datetime string', function () {
    $result = castParameters([new DateTimeImmutable('2026-01-02 03:04:05')]);

    expect($result[0])->toBe('2026-01-02 03:04:05');
})->group('LibsqlStatementTest', 'UnitTest');

test('it still casts a mutable Carbon date to a datetime string', function () {
    $result = castParameters([\Illuminate\Support\Carbon::parse('2026-01-02 03:04:05')]);

    expect($result[0])->toBe('2026-01-02 03:04:05');
})->group('LibsqlStatementTest', 'UnitTest');
