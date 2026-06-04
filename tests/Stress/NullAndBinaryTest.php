<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NULL handling, boolean round-trips, and binary/blob data.
 *
 * Table-name prefix: nb_
 */

beforeEach(function () {
    Schema::dropIfExists('nb_blobs');
    Schema::dropIfExists('nb_nullables');

    Schema::create('nb_nullables', function (Blueprint $t) {
        $t->id();
        $t->string('str_col')->nullable();
        $t->integer('int_col')->nullable();
        $t->bigInteger('bigint_col')->nullable();
        $t->float('float_col')->nullable();
        $t->double('double_col')->nullable();
        $t->decimal('decimal_col', 10, 4)->nullable();
        $t->boolean('bool_col')->nullable();
        $t->text('text_col')->nullable();
        $t->longText('longtext_col')->nullable();
        $t->date('date_col')->nullable();
        $t->dateTime('datetime_col')->nullable();
        $t->timestamp('timestamp_col')->nullable();
        $t->json('json_col')->nullable();
    });

    Schema::create('nb_blobs', function (Blueprint $t) {
        $t->id();
        $t->string('label');
        $t->binary('data');
    });
});

// ---------------------------------------------------------------------------
// NULL round-trips across every column type
// ---------------------------------------------------------------------------

test('NULL inserts and reads back as null for every nullable column type', function () {
    DB::table('nb_nullables')->insert([
        'str_col'       => null,
        'int_col'       => null,
        'bigint_col'    => null,
        'float_col'     => null,
        'double_col'    => null,
        'decimal_col'   => null,
        'bool_col'      => null,
        'text_col'      => null,
        'longtext_col'  => null,
        'date_col'      => null,
        'datetime_col'  => null,
        'timestamp_col' => null,
        'json_col'      => null,
    ]);

    $row = DB::table('nb_nullables')->first();

    expect($row->str_col)->toBeNull();
    expect($row->int_col)->toBeNull();
    expect($row->bigint_col)->toBeNull();
    expect($row->float_col)->toBeNull();
    expect($row->double_col)->toBeNull();
    expect($row->decimal_col)->toBeNull();
    expect($row->bool_col)->toBeNull();
    expect($row->text_col)->toBeNull();
    expect($row->longtext_col)->toBeNull();
    expect($row->date_col)->toBeNull();
    expect($row->datetime_col)->toBeNull();
    expect($row->timestamp_col)->toBeNull();
    expect($row->json_col)->toBeNull();
})->group('stress');

test('column that was non-null can be updated to null', function () {
    $id = DB::table('nb_nullables')->insertGetId([
        'str_col'  => 'hello',
        'int_col'  => 42,
        'bool_col' => true,
    ]);

    DB::table('nb_nullables')->where('id', $id)->update([
        'str_col'  => null,
        'int_col'  => null,
        'bool_col' => null,
    ]);

    $row = DB::table('nb_nullables')->where('id', $id)->first();

    expect($row->str_col)->toBeNull();
    expect($row->int_col)->toBeNull();
    expect($row->bool_col)->toBeNull();
})->group('stress');

test('whereNull and whereNotNull filter correctly', function () {
    DB::table('nb_nullables')->insert([
        ['str_col' => 'present', 'int_col' => 1],
        ['str_col' => null,      'int_col' => null],
        ['str_col' => 'also',    'int_col' => null],
    ]);

    $nullCount    = DB::table('nb_nullables')->whereNull('str_col')->count();
    $notNullCount = DB::table('nb_nullables')->whereNotNull('str_col')->count();

    expect($nullCount)->toBe(1);
    expect($notNullCount)->toBe(2);
})->group('stress');

test('mixed null and non-null rows in the same batch insert', function () {
    $rows = [
        ['str_col' => 'a', 'int_col' => 10, 'float_col' => null,  'bool_col' => true],
        ['str_col' => null, 'int_col' => null, 'float_col' => 3.14, 'bool_col' => null],
        ['str_col' => 'c', 'int_col' => 30, 'float_col' => null,  'bool_col' => false],
    ];

    DB::table('nb_nullables')->insert($rows);

    $all = DB::table('nb_nullables')->orderBy('id')->get();

    expect($all)->toHaveCount(3);

    expect($all[0]->str_col)->toBe('a');
    expect($all[0]->int_col)->not->toBeNull();
    expect($all[0]->float_col)->toBeNull();

    expect($all[1]->str_col)->toBeNull();
    expect($all[1]->int_col)->toBeNull();
    expect((float) $all[1]->float_col)->toBe(3.14);

    expect($all[2]->str_col)->toBe('c');
    expect($all[2]->float_col)->toBeNull();
})->group('stress');

test('NULL date columns do not coerce to a date string', function () {
    DB::table('nb_nullables')->insert(['date_col' => null, 'datetime_col' => null, 'timestamp_col' => null]);

    $row = DB::table('nb_nullables')->first();

    expect($row->date_col)->toBeNull();
    expect($row->datetime_col)->toBeNull();
    expect($row->timestamp_col)->toBeNull();
})->group('stress');

test('non-null date columns round-trip correctly', function () {
    $dt = CarbonImmutable::parse('2025-12-31 23:59:59');

    DB::table('nb_nullables')->insert([
        'date_col'      => $dt->toDateString(),
        'datetime_col'  => $dt->toDateTimeString(),
        'timestamp_col' => $dt->toDateTimeString(),
    ]);

    $row = DB::table('nb_nullables')->first();

    expect((string) $row->date_col)->toContain('2025-12-31');
    expect((string) $row->datetime_col)->toContain('2025-12-31 23:59:59');
    expect((string) $row->timestamp_col)->toContain('2025-12-31 23:59:59');
})->group('stress');

// ---------------------------------------------------------------------------
// Boolean round-trips
// ---------------------------------------------------------------------------

test('boolean true stores and reads back as truthy', function () {
    DB::table('nb_nullables')->insert(['bool_col' => true]);

    $row = DB::table('nb_nullables')->first();

    expect((bool) $row->bool_col)->toBeTrue();
    expect((int) $row->bool_col)->toBe(1);
})->group('stress');

test('boolean false stores and reads back as falsy', function () {
    DB::table('nb_nullables')->insert(['bool_col' => false]);

    $row = DB::table('nb_nullables')->first();

    expect((bool) $row->bool_col)->toBeFalse();
    expect((int) $row->bool_col)->toBe(0);
})->group('stress');

test('integer 1 stores and reads back the same as boolean true', function () {
    DB::table('nb_nullables')->insert(['bool_col' => 1]);

    $row = DB::table('nb_nullables')->first();

    expect((int) $row->bool_col)->toBe(1);
    expect((bool) $row->bool_col)->toBeTrue();
})->group('stress');

test('integer 0 stores and reads back the same as boolean false', function () {
    DB::table('nb_nullables')->insert(['bool_col' => 0]);

    $row = DB::table('nb_nullables')->first();

    expect((int) $row->bool_col)->toBe(0);
    expect((bool) $row->bool_col)->toBeFalse();
})->group('stress');

test('multiple rows with mixed boolean values are retrieved correctly', function () {
    DB::table('nb_nullables')->insert([
        ['bool_col' => true],
        ['bool_col' => false],
        ['bool_col' => true],
        ['bool_col' => null],
        ['bool_col' => 0],
        ['bool_col' => 1],
    ]);

    $trueCount  = DB::table('nb_nullables')->where('bool_col', true)->count();
    $falseCount = DB::table('nb_nullables')->where('bool_col', false)->count();
    $nullCount  = DB::table('nb_nullables')->whereNull('bool_col')->count();

    // true (row1) + true (row3) + integer 1 (row6) = 3 rows with bool_col = 1
    expect($trueCount)->toBe(3);
    // false (row2) + integer 0 (row5) = 2 rows with bool_col = 0
    expect($falseCount)->toBe(2);
    expect($nullCount)->toBe(1);
})->group('stress');

test('boolean true/false round-trip through update', function () {
    $id = DB::table('nb_nullables')->insertGetId(['bool_col' => true]);

    DB::table('nb_nullables')->where('id', $id)->update(['bool_col' => false]);

    $row = DB::table('nb_nullables')->where('id', $id)->first();
    expect((bool) $row->bool_col)->toBeFalse();

    DB::table('nb_nullables')->where('id', $id)->update(['bool_col' => true]);

    $row = DB::table('nb_nullables')->where('id', $id)->first();
    expect((bool) $row->bool_col)->toBeTrue();
})->group('stress');

// ---------------------------------------------------------------------------
// Binary / BLOB data
// ---------------------------------------------------------------------------

test('empty binary string round-trips correctly', function () {
    DB::table('nb_blobs')->insert(['label' => 'empty', 'data' => '']);

    $row = DB::table('nb_blobs')->where('label', 'empty')->first();

    expect($row->data)->toBe('');
})->group('stress')->skip('libsql FFI panics (capacity overflow) when binding an empty string to a binary column — package limitation');

test('plain ASCII binary data round-trips byte-for-byte', function () {
    $payload = 'Hello, binary world!';

    DB::table('nb_blobs')->insert(['label' => 'ascii', 'data' => $payload]);

    $row = DB::table('nb_blobs')->where('label', 'ascii')->first();

    expect($row->data)->toBe($payload);
})->group('stress');

test('binary data with null bytes round-trips identically', function () {
    // Build a string with embedded null bytes (common in binary protocols)
    $payload = "start\x00middle\x00\x00end";

    DB::table('nb_blobs')->insert(['label' => 'nullbytes', 'data' => $payload]);

    $row = DB::table('nb_blobs')->where('label', 'nullbytes')->first();

    expect($row->data)->toBe($payload);
    expect(strlen($row->data))->toBe(strlen($payload));
})->group('stress');

test('non-UTF8 byte sequences round-trip identically', function () {
    // Byte sequences that are not valid UTF-8
    $payload = "\x80\x81\x82\x83\xfe\xff\x00\x01\x02\x03";

    DB::table('nb_blobs')->insert(['label' => 'non-utf8', 'data' => $payload]);

    $row = DB::table('nb_blobs')->where('label', 'non-utf8')->first();

    expect($row->data)->toBe($payload);
    expect(strlen($row->data))->toBe(strlen($payload));
})->group('stress');

test('all 256 byte values round-trip in a single blob', function () {
    // Build a string containing every possible byte value 0x00–0xFF
    $payload = '';
    for ($i = 0; $i <= 255; $i++) {
        $payload .= chr($i);
    }

    expect(strlen($payload))->toBe(256);

    DB::table('nb_blobs')->insert(['label' => 'all256', 'data' => $payload]);

    $row = DB::table('nb_blobs')->where('label', 'all256')->first();

    expect(strlen($row->data))->toBe(256);
    expect($row->data)->toBe($payload);
})->group('stress');

test('large binary payload round-trips correctly', function () {
    // ~64 KB of pseudo-random-looking bytes
    $chunk   = '';
    for ($i = 0; $i < 256; $i++) {
        $chunk .= chr($i);
    }
    $payload = str_repeat($chunk, 256); // 65 536 bytes

    DB::table('nb_blobs')->insert(['label' => 'large', 'data' => $payload]);

    $row = DB::table('nb_blobs')->where('label', 'large')->first();

    expect(strlen($row->data))->toBe(65536);
    expect($row->data)->toBe($payload);
})->group('stress');

test('multiple binary blobs are stored and retrieved independently', function () {
    $payloads = [
        'blob-a' => "\x00\x01\x02",
        'blob-b' => "\xff\xfe\xfd",
        'blob-c' => "mixed\x00bytes\xff",
    ];

    foreach ($payloads as $label => $data) {
        DB::table('nb_blobs')->insert(['label' => $label, 'data' => $data]);
    }

    foreach ($payloads as $label => $expected) {
        $row = DB::table('nb_blobs')->where('label', $label)->first();
        expect($row->data)->toBe($expected);
        expect(strlen($row->data))->toBe(strlen($expected));
    }
})->group('stress');
