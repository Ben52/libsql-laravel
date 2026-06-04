<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stress tests for date/datetime/timestamp columns.
 *
 * Table prefix: dt_   (reserved for this file only)
 */
beforeEach(function () {
    Schema::dropIfExists('dt_events');

    Schema::create('dt_events', function (Blueprint $t) {
        $t->id();
        $t->string('label');
        $t->date('event_date')->nullable();
        $t->dateTime('event_at')->nullable();
        $t->timestamp('stamped_at')->nullable();
        $t->dateTime('nullable_at')->nullable();
        $t->timestamps();
    });
});

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function dtRow(array $overrides = []): array
{
    return array_merge([
        'label'       => 'test',
        'event_date'  => null,
        'event_at'    => null,
        'stamped_at'  => null,
        'nullable_at' => null,
        'created_at'  => CarbonImmutable::now(),
        'updated_at'  => CarbonImmutable::now(),
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('CarbonImmutable round-trips through datetime column to the second', function () {
    $dt = CarbonImmutable::parse('2025-03-15 10:20:30');

    DB::table('dt_events')->insert(dtRow([
        'label'    => 'immutable',
        'event_at' => $dt,
    ]));

    $row = DB::table('dt_events')->where('label', 'immutable')->first();

    expect((string) $row->event_at)->toContain('2025-03-15 10:20:30');
})->group('stress');

test('mutable Carbon round-trips through datetime column to the second', function () {
    $dt = Carbon::parse('2024-07-04 08:00:00');

    DB::table('dt_events')->insert(dtRow([
        'label'    => 'mutable',
        'event_at' => $dt,
    ]));

    $row = DB::table('dt_events')->where('label', 'mutable')->first();

    expect((string) $row->event_at)->toContain('2024-07-04 08:00:00');
})->group('stress');

test('native DateTime round-trips through datetime column to the second', function () {
    $dt = new DateTime('2023-11-22 14:30:00');

    DB::table('dt_events')->insert(dtRow([
        'label'    => 'native-dt',
        'event_at' => $dt->format('Y-m-d H:i:s'),
    ]));

    $row = DB::table('dt_events')->where('label', 'native-dt')->first();

    expect((string) $row->event_at)->toContain('2023-11-22 14:30:00');
})->group('stress');

test('native DateTimeImmutable round-trips through datetime column to the second', function () {
    $dt = new DateTimeImmutable('2022-01-31 23:59:59');

    DB::table('dt_events')->insert(dtRow([
        'label'    => 'native-dti',
        'event_at' => $dt->format('Y-m-d H:i:s'),
    ]));

    $row = DB::table('dt_events')->where('label', 'native-dti')->first();

    expect((string) $row->event_at)->toContain('2022-01-31 23:59:59');
})->group('stress');

test('date-only column stores and retrieves the correct date without time component', function () {
    $date = CarbonImmutable::parse('2026-06-01');

    DB::table('dt_events')->insert(dtRow([
        'label'      => 'date-only',
        'event_date' => $date->toDateString(),
    ]));

    $row = DB::table('dt_events')->where('label', 'date-only')->first();

    expect((string) $row->event_date)->toContain('2026-06-01');
})->group('stress');

test('null date columns are stored and retrieved as null', function () {
    DB::table('dt_events')->insert(dtRow([
        'label'       => 'nulls',
        'event_date'  => null,
        'event_at'    => null,
        'stamped_at'  => null,
        'nullable_at' => null,
    ]));

    $row = DB::table('dt_events')->where('label', 'nulls')->first();

    expect($row->event_date)->toBeNull();
    expect($row->event_at)->toBeNull();
    expect($row->stamped_at)->toBeNull();
    expect($row->nullable_at)->toBeNull();
})->group('stress');

test('timestamp column round-trips CarbonImmutable to the second', function () {
    $ts = CarbonImmutable::parse('2025-09-01 00:00:01');

    DB::table('dt_events')->insert(dtRow([
        'label'      => 'ts',
        'stamped_at' => $ts,
    ]));

    $row = DB::table('dt_events')->where('label', 'ts')->first();

    expect((string) $row->stamped_at)->toContain('2025-09-01 00:00:01');
})->group('stress');

test('ordering by datetime column returns rows in ascending chronological order', function () {
    $dates = [
        CarbonImmutable::parse('2025-01-03 12:00:00'),
        CarbonImmutable::parse('2025-01-01 08:00:00'),
        CarbonImmutable::parse('2025-01-02 16:30:00'),
    ];

    foreach ($dates as $i => $dt) {
        DB::table('dt_events')->insert(dtRow([
            'label'    => "order-{$i}",
            'event_at' => $dt,
        ]));
    }

    $rows = DB::table('dt_events')
        ->whereNotNull('event_at')
        ->orderBy('event_at', 'asc')
        ->pluck('label')
        ->toArray();

    expect($rows)->toBe(['order-1', 'order-2', 'order-0']);
})->group('stress');

test('ordering by datetime column returns rows in descending chronological order', function () {
    $dates = [
        CarbonImmutable::parse('2025-01-03 12:00:00'),
        CarbonImmutable::parse('2025-01-01 08:00:00'),
        CarbonImmutable::parse('2025-01-02 16:30:00'),
    ];

    foreach ($dates as $i => $dt) {
        DB::table('dt_events')->insert(dtRow([
            'label'    => "desc-{$i}",
            'event_at' => $dt,
        ]));
    }

    $rows = DB::table('dt_events')
        ->whereNotNull('event_at')
        ->orderBy('event_at', 'desc')
        ->pluck('label')
        ->toArray();

    expect($rows)->toBe(['desc-0', 'desc-2', 'desc-1']);
})->group('stress');

test('whereBetween on datetime column returns only rows inside the range', function () {
    $rows = [
        ['label' => 'wb-before', 'event_at' => CarbonImmutable::parse('2025-04-30 23:59:59')],
        ['label' => 'wb-start',  'event_at' => CarbonImmutable::parse('2025-05-01 00:00:00')],
        ['label' => 'wb-mid',    'event_at' => CarbonImmutable::parse('2025-05-15 12:00:00')],
        ['label' => 'wb-end',    'event_at' => CarbonImmutable::parse('2025-05-31 23:59:59')],
        ['label' => 'wb-after',  'event_at' => CarbonImmutable::parse('2025-06-01 00:00:00')],
    ];

    foreach ($rows as $row) {
        DB::table('dt_events')->insert(dtRow($row));
    }

    $labels = DB::table('dt_events')
        ->whereBetween('event_at', [
            '2025-05-01 00:00:00',
            '2025-05-31 23:59:59',
        ])
        ->orderBy('event_at')
        ->pluck('label')
        ->toArray();

    expect($labels)->toBe(['wb-start', 'wb-mid', 'wb-end']);
    expect($labels)->not->toContain('wb-before');
    expect($labels)->not->toContain('wb-after');
})->group('stress');

test('whereDate on date column filters correctly', function () {
    $rows = [
        ['label' => 'wd-match1', 'event_date' => '2025-08-10'],
        ['label' => 'wd-match2', 'event_date' => '2025-08-10'],
        ['label' => 'wd-other',  'event_date' => '2025-08-11'],
    ];

    foreach ($rows as $row) {
        DB::table('dt_events')->insert(dtRow($row));
    }

    $labels = DB::table('dt_events')
        ->whereDate('event_date', '2025-08-10')
        ->orderBy('label')
        ->pluck('label')
        ->toArray();

    expect($labels)->toBe(['wd-match1', 'wd-match2']);
})->group('stress');

test('multiple distinct datetimes each survive the round-trip accurately', function () {
    $fixtures = [
        ['label' => 'epoch-ish',   'event_at' => '1970-01-01 00:00:01'],
        ['label' => 'y2k',         'event_at' => '2000-01-01 00:00:00'],
        ['label' => 'leap-day',    'event_at' => '2024-02-29 12:00:00'],
        ['label' => 'end-of-year', 'event_at' => '2025-12-31 23:59:59'],
    ];

    foreach ($fixtures as $fixture) {
        DB::table('dt_events')->insert(dtRow($fixture));
    }

    foreach ($fixtures as $fixture) {
        $row = DB::table('dt_events')->where('label', $fixture['label'])->first();
        expect((string) $row->event_at)->toContain($fixture['event_at']);
    }
})->group('stress');

test('created_at and updated_at timestamps stored via CarbonImmutable are readable', function () {
    $now = CarbonImmutable::parse('2025-06-04 09:15:00');

    DB::table('dt_events')->insert(dtRow([
        'label'      => 'ts-cols',
        'created_at' => $now,
        'updated_at' => $now,
    ]));

    $row = DB::table('dt_events')->where('label', 'ts-cols')->first();

    expect((string) $row->created_at)->toContain('2025-06-04 09:15:00');
    expect((string) $row->updated_at)->toContain('2025-06-04 09:15:00');
})->group('stress');
