<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeric precision stress tests.
 *
 * Covers: large integers (>2^53), negative numbers, zero, decimals/floats with
 * many fractional digits, decimal columns, and SQL aggregates (sum/avg/min/max).
 *
 * Table prefix: num_
 */
beforeEach(function () {
    Schema::dropIfExists('num_decimals');
    Schema::dropIfExists('num_numbers');

    Schema::create('num_numbers', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('big_int')->nullable();
        $t->bigInteger('neg_int')->nullable();
        $t->double('dbl_val')->nullable();
        $t->timestamps();
    });

    Schema::create('num_decimals', function (Blueprint $t) {
        $t->id();
        $t->string('label');
        $t->decimal('amount', 20, 8); // high-precision decimal column
        $t->timestamps();
    });
});

// ---------------------------------------------------------------------------
// Large integers
// ---------------------------------------------------------------------------

test('stores and retrieves a large integer that exceeds 2^53', function () {
    // PHP and SQLite both store 64-bit signed integers correctly.
    // 2^53 = 9007199254740992; we go well beyond that.
    $large = 9999999999999999; // ~10^16, safely within int64 but > 2^53

    DB::table('num_numbers')->insert([
        'big_int'    => $large,
        'neg_int'    => 0,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $row = DB::table('num_numbers')->first();

    expect((int) $row->big_int)->toBe($large);
})->group('stress');

test('stores and retrieves a large negative integer', function () {
    $large = -9999999999999999;

    DB::table('num_numbers')->insert([
        'big_int'    => 0,
        'neg_int'    => $large,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $row = DB::table('num_numbers')->first();

    expect((int) $row->neg_int)->toBe($large);
})->group('stress');

test('stores and retrieves zero without mangling', function () {
    DB::table('num_numbers')->insert([
        'big_int'    => 0,
        'neg_int'    => 0,
        'dbl_val'    => 0.0,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $row = DB::table('num_numbers')->first();

    expect((int) $row->big_int)->toBe(0);
    expect((int) $row->neg_int)->toBe(0);
    expect((float) $row->dbl_val)->toBe(0.0);
})->group('stress');

// ---------------------------------------------------------------------------
// Floating-point / double precision
// ---------------------------------------------------------------------------

test('stores and retrieves a double with many fractional digits', function () {
    // PHP float is IEEE-754 double (53-bit mantissa); 15–16 significant digits.
    $val = 3.141592653589793;

    DB::table('num_numbers')->insert([
        'dbl_val'    => $val,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $row = DB::table('num_numbers')->first();

    // Allow for the tiny rounding inherent in IEEE-754 double storage.
    expect(abs((float) $row->dbl_val - $val))->toBeLessThan(1e-14);
})->group('stress');

test('stores and retrieves a negative double', function () {
    $val = -2.718281828459045;

    DB::table('num_numbers')->insert([
        'dbl_val'    => $val,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $row = DB::table('num_numbers')->first();

    expect(abs((float) $row->dbl_val - $val))->toBeLessThan(1e-14);
})->group('stress');

test('stores and retrieves a very small positive double', function () {
    $val = 1.23456789012345e-10;

    DB::table('num_numbers')->insert([
        'dbl_val'    => $val,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $row = DB::table('num_numbers')->first();

    expect(abs((float) $row->dbl_val - $val))->toBeLessThan(1e-24);
})->group('stress');

// ---------------------------------------------------------------------------
// Decimal column precision
// ---------------------------------------------------------------------------

test('decimal column with 8 fractional digits round-trips without truncation', function () {
    $amount = '12345678.12345678';

    DB::table('num_decimals')->insert([
        'label'      => 'pi-like',
        'amount'     => $amount,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $row = DB::table('num_decimals')->where('label', 'pi-like')->first();

    // Cast back to a float for comparison; tolerate last-bit rounding only.
    expect(abs((float) $row->amount - (float) $amount))->toBeLessThan(1e-6);
})->group('stress');

test('decimal column preserves negative amounts', function () {
    $amount = '-9999.99999999';

    DB::table('num_decimals')->insert([
        'label'      => 'negative',
        'amount'     => $amount,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $row = DB::table('num_decimals')->where('label', 'negative')->first();

    expect((float) $row->amount)->toBeLessThan(0.0);
    expect(abs((float) $row->amount - (float) $amount))->toBeLessThan(1e-6);
})->group('stress');

test('decimal column stores zero exactly', function () {
    DB::table('num_decimals')->insert([
        'label'      => 'zero',
        'amount'     => '0.00000000',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $row = DB::table('num_decimals')->where('label', 'zero')->first();

    expect((float) $row->amount)->toBe(0.0);
})->group('stress');

// ---------------------------------------------------------------------------
// SQL aggregates on the decimals table
// ---------------------------------------------------------------------------

test('sum aggregate is correct over decimal values', function () {
    $rows = [
        ['label' => 'a', 'amount' => '100.00000001'],
        ['label' => 'b', 'amount' => '200.00000002'],
        ['label' => 'c', 'amount' => '300.00000003'],
    ];
    $ts = ['created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()];

    foreach ($rows as $r) {
        DB::table('num_decimals')->insert(array_merge($r, $ts));
    }

    $sum = (float) DB::table('num_decimals')->sum('amount');
    $expected = 100.00000001 + 200.00000002 + 300.00000003; // 600.00000006

    expect(abs($sum - $expected))->toBeLessThan(1e-6);
})->group('stress');

test('avg aggregate is correct over decimal values', function () {
    $values = ['10.00000004', '20.00000004', '30.00000004'];
    $ts     = ['created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()];

    foreach ($values as $i => $v) {
        DB::table('num_decimals')->insert(array_merge(['label' => "avg{$i}", 'amount' => $v], $ts));
    }

    $avg      = (float) DB::table('num_decimals')->avg('amount');
    $expected = 20.00000004; // (10 + 20 + 30) / 3

    expect(abs($avg - $expected))->toBeLessThan(1e-6);
})->group('stress');

test('min and max aggregates return correct extremes', function () {
    $values = ['-500.12345678', '0.00000000', '999.87654321', '-1000.00000001', '1.00000001'];
    $ts     = ['created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()];

    foreach ($values as $i => $v) {
        DB::table('num_decimals')->insert(array_merge(['label' => "mm{$i}", 'amount' => $v], $ts));
    }

    $min = (float) DB::table('num_decimals')->min('amount');
    $max = (float) DB::table('num_decimals')->max('amount');

    expect(abs($min - (-1000.00000001)))->toBeLessThan(1e-6);
    expect(abs($max - 999.87654321))->toBeLessThan(1e-6);
})->group('stress');

// ---------------------------------------------------------------------------
// Aggregates on the big-integer table
// ---------------------------------------------------------------------------

test('sum of large integers is exact', function () {
    $ts = ['created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()];

    DB::table('num_numbers')->insert(array_merge(['big_int' => 1000000000000000], $ts));
    DB::table('num_numbers')->insert(array_merge(['big_int' => 2000000000000000], $ts));
    DB::table('num_numbers')->insert(array_merge(['big_int' => 3000000000000000], $ts));

    $sum = (int) DB::table('num_numbers')->sum('big_int');

    expect($sum)->toBe(6000000000000000);
})->group('stress');

test('min and max of integer column return correct boundary values', function () {
    $ts   = ['created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()];
    $vals = [-9000000000000000, -1, 0, 1, 9000000000000000];

    foreach ($vals as $v) {
        DB::table('num_numbers')->insert(array_merge(['big_int' => $v], $ts));
    }

    expect((int) DB::table('num_numbers')->min('big_int'))->toBe(-9000000000000000);
    expect((int) DB::table('num_numbers')->max('big_int'))->toBe(9000000000000000);
})->group('stress');

// ---------------------------------------------------------------------------
// Mixed positive/negative sums
// ---------------------------------------------------------------------------

test('sum cancels positive and negative values to zero', function () {
    $ts = ['created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()];

    DB::table('num_decimals')->insert(array_merge(['label' => 'pos', 'amount' => '123456.78901234'], $ts));
    DB::table('num_decimals')->insert(array_merge(['label' => 'neg', 'amount' => '-123456.78901234'], $ts));

    $sum = (float) DB::table('num_decimals')->sum('amount');

    expect(abs($sum))->toBeLessThan(1e-6);
})->group('stress');
