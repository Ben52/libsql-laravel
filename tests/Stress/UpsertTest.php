<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upsert-category stress tests.
 *
 * Table prefix: "up_"
 * Covers: upsert(), updateOrInsert(), insertOrIgnore(),
 *         firstOrCreate(), increment()/decrement().
 * Verifies insert-vs-update behaviour, affected counts, and final row values.
 */
beforeEach(function () {
    Schema::dropIfExists('up_products');
    Schema::dropIfExists('up_counters');
    Schema::dropIfExists('up_users');

    Schema::create('up_products', function (Blueprint $t) {
        $t->id();
        $t->string('sku')->unique();
        $t->string('name');
        $t->integer('stock')->default(0);
        $t->decimal('price', 10, 2)->default(0);
        $t->boolean('active')->default(true);
        $t->timestamp('refreshed_at')->nullable();
        $t->timestamps();
    });

    Schema::create('up_counters', function (Blueprint $t) {
        $t->id();
        $t->string('key')->unique();
        $t->integer('value')->default(0);
        $t->timestamps();
    });

    Schema::create('up_users', function (Blueprint $t) {
        $t->id();
        $t->string('email')->unique();
        $t->string('name');
        $t->integer('login_count')->default(0);
        $t->timestamps();
    });
});

// ---------------------------------------------------------------------------
// upsert()
// ---------------------------------------------------------------------------

test('upsert inserts new rows when no conflict exists', function () {
    $affected = DB::table('up_products')->upsert(
        [
            ['sku' => 'AAA', 'name' => 'Alpha', 'stock' => 10, 'price' => 1.99, 'active' => true],
            ['sku' => 'BBB', 'name' => 'Beta',  'stock' => 20, 'price' => 2.99, 'active' => true],
        ],
        uniqueBy: ['sku'],
        update:   ['name', 'stock', 'price', 'active'],
    );

    expect(DB::table('up_products')->count())->toBe(2);
    expect($affected)->toBeGreaterThanOrEqual(2); // some backends return 2, some 4 for "upsert"
})->group('stress');

test('upsert updates existing rows on conflict', function () {
    DB::table('up_products')->insert([
        'sku' => 'AAA', 'name' => 'OldName', 'stock' => 5, 'price' => 0.99, 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('up_products')->upsert(
        [['sku' => 'AAA', 'name' => 'NewName', 'stock' => 99, 'price' => 3.50, 'active' => false]],
        uniqueBy: ['sku'],
        update:   ['name', 'stock', 'price', 'active'],
    );

    $row = DB::table('up_products')->where('sku', 'AAA')->first();

    expect(DB::table('up_products')->count())->toBe(1);
    expect($row->name)->toBe('NewName');
    expect((int) $row->stock)->toBe(99);
    expect((float) $row->price)->toBe(3.5);
    expect((bool) $row->active)->toBeFalse();
})->group('stress');

test('upsert handles mixed insert and update in one call', function () {
    DB::table('up_products')->insert([
        'sku' => 'EXIST', 'name' => 'Existing', 'stock' => 1, 'price' => 1.0, 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('up_products')->upsert(
        [
            ['sku' => 'EXIST', 'name' => 'Updated', 'stock' => 50, 'price' => 5.0, 'active' => true],
            ['sku' => 'NEW',   'name' => 'Brand New', 'stock' => 7, 'price' => 7.0, 'active' => true],
        ],
        uniqueBy: ['sku'],
        update:   ['name', 'stock', 'price'],
    );

    expect(DB::table('up_products')->count())->toBe(2);

    $existing = DB::table('up_products')->where('sku', 'EXIST')->first();
    expect($existing->name)->toBe('Updated');
    expect((int) $existing->stock)->toBe(50);

    $newRow = DB::table('up_products')->where('sku', 'NEW')->first();
    expect($newRow->name)->toBe('Brand New');
    expect((int) $newRow->stock)->toBe(7);
})->group('stress');

test('upsert with CarbonImmutable timestamp in update columns', function () {
    $ts = CarbonImmutable::parse('2026-03-15 12:00:00');

    DB::table('up_products')->insert([
        'sku' => 'TS1', 'name' => 'TimestampItem', 'stock' => 1, 'price' => 1.0, 'active' => true,
        'refreshed_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('up_products')->upsert(
        [['sku' => 'TS1', 'name' => 'TimestampItem', 'stock' => 2, 'price' => 1.0, 'active' => true, 'refreshed_at' => $ts]],
        uniqueBy: ['sku'],
        update:   ['stock', 'refreshed_at'],
    );

    $row = DB::table('up_products')->where('sku', 'TS1')->first();

    expect((int) $row->stock)->toBe(2);
    expect((string) $row->refreshed_at)->toContain('2026-03-15 12:00:00');
})->group('stress');

// ---------------------------------------------------------------------------
// updateOrInsert()
// ---------------------------------------------------------------------------

test('updateOrInsert inserts when row does not exist', function () {
    $result = DB::table('up_products')->updateOrInsert(
        ['sku' => 'NEW_SKU'],
        ['name' => 'Inserted', 'stock' => 10, 'price' => 9.99, 'active' => true,
         'created_at' => now(), 'updated_at' => now()],
    );

    expect(DB::table('up_products')->count())->toBe(1);

    $row = DB::table('up_products')->where('sku', 'NEW_SKU')->first();
    expect($row->name)->toBe('Inserted');
    expect((int) $row->stock)->toBe(10);
})->group('stress');

test('updateOrInsert updates when row already exists', function () {
    DB::table('up_products')->insert([
        'sku' => 'UPDT', 'name' => 'Before', 'stock' => 3, 'price' => 1.0, 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('up_products')->updateOrInsert(
        ['sku' => 'UPDT'],
        ['name' => 'After', 'stock' => 77, 'price' => 2.5],
    );

    expect(DB::table('up_products')->count())->toBe(1);

    $row = DB::table('up_products')->where('sku', 'UPDT')->first();
    expect($row->name)->toBe('After');
    expect((int) $row->stock)->toBe(77);
    expect((float) $row->price)->toBe(2.5);
})->group('stress');

test('updateOrInsert does not duplicate rows on repeated calls', function () {
    for ($i = 0; $i < 5; $i++) {
        DB::table('up_products')->updateOrInsert(
            ['sku' => 'DEDUP'],
            ['name' => "Run{$i}", 'stock' => $i, 'price' => 1.0, 'active' => true,
             'created_at' => now(), 'updated_at' => now()],
        );
    }

    expect(DB::table('up_products')->where('sku', 'DEDUP')->count())->toBe(1);

    $row = DB::table('up_products')->where('sku', 'DEDUP')->first();
    expect($row->name)->toBe('Run4');
    expect((int) $row->stock)->toBe(4);
})->group('stress');

// ---------------------------------------------------------------------------
// insertOrIgnore()
// ---------------------------------------------------------------------------

test('insertOrIgnore inserts new rows and returns count', function () {
    $affected = DB::table('up_products')->insertOrIgnore([
        ['sku' => 'IGN1', 'name' => 'Item1', 'stock' => 1, 'price' => 1.0, 'active' => true,
         'created_at' => now(), 'updated_at' => now()],
        ['sku' => 'IGN2', 'name' => 'Item2', 'stock' => 2, 'price' => 2.0, 'active' => true,
         'created_at' => now(), 'updated_at' => now()],
    ]);

    expect($affected)->toBe(2);
    expect(DB::table('up_products')->count())->toBe(2);
})->group('stress');

test('insertOrIgnore silently skips rows that violate unique constraint', function () {
    DB::table('up_products')->insert([
        'sku' => 'DUP', 'name' => 'Original', 'stock' => 5, 'price' => 5.0, 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $affected = DB::table('up_products')->insertOrIgnore([
        ['sku' => 'DUP',  'name' => 'ShouldBeIgnored', 'stock' => 99, 'price' => 99.0, 'active' => false,
         'created_at' => now(), 'updated_at' => now()],
        ['sku' => 'UNIQ', 'name' => 'ShouldInsert',    'stock' => 7,  'price' => 7.0,  'active' => true,
         'created_at' => now(), 'updated_at' => now()],
    ]);

    // Only the non-conflicting row was inserted
    expect($affected)->toBe(1);
    expect(DB::table('up_products')->count())->toBe(2);

    // Original row was NOT overwritten
    $original = DB::table('up_products')->where('sku', 'DUP')->first();
    expect($original->name)->toBe('Original');
    expect((int) $original->stock)->toBe(5);
})->group('stress');

test('insertOrIgnore returns 0 when all rows are duplicates', function () {
    DB::table('up_products')->insert([
        'sku' => 'ALLDUP', 'name' => 'Existing', 'stock' => 3, 'price' => 3.0, 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $affected = DB::table('up_products')->insertOrIgnore([
        ['sku' => 'ALLDUP', 'name' => 'Ignored', 'stock' => 0, 'price' => 0.0, 'active' => false,
         'created_at' => now(), 'updated_at' => now()],
    ]);

    expect($affected)->toBe(0);
    expect(DB::table('up_products')->count())->toBe(1);
})->group('stress');

// ---------------------------------------------------------------------------
// firstOrCreate()  (Eloquent-less: use DB::table + manual equivalent pattern)
// We test via updateOrInsert which underpins the same "get-or-create" semantics
// at the query-builder level; the Eloquent firstOrCreate wraps this logic.
// Here we test the raw builder equivalent: first() + insertGetId() combo.
// ---------------------------------------------------------------------------

test('firstOrCreate-style: first existing row is returned without inserting', function () {
    DB::table('up_users')->insert([
        'email' => 'alice@example.com', 'name' => 'Alice', 'login_count' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Simulate firstOrCreate: look for the row, insert only if missing
    $existing = DB::table('up_users')->where('email', 'alice@example.com')->first();
    if (! $existing) {
        DB::table('up_users')->insert([
            'email' => 'alice@example.com', 'name' => 'New Alice', 'login_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(DB::table('up_users')->count())->toBe(1);
    $row = DB::table('up_users')->where('email', 'alice@example.com')->first();
    // Must be the original, not overwritten
    expect($row->name)->toBe('Alice');
    expect((int) $row->login_count)->toBe(3);
})->group('stress');

test('firstOrCreate-style: missing row is inserted and retrievable', function () {
    $existing = DB::table('up_users')->where('email', 'bob@example.com')->first();
    if (! $existing) {
        DB::table('up_users')->insert([
            'email' => 'bob@example.com', 'name' => 'Bob', 'login_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(DB::table('up_users')->count())->toBe(1);
    $row = DB::table('up_users')->where('email', 'bob@example.com')->first();
    expect($row->name)->toBe('Bob');
    expect((int) $row->login_count)->toBe(0);
})->group('stress');

// ---------------------------------------------------------------------------
// increment() / decrement()
// ---------------------------------------------------------------------------

test('increment increases value by 1 and returns affected rows', function () {
    DB::table('up_counters')->insert([
        'key' => 'hits', 'value' => 10,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $affected = DB::table('up_counters')->where('key', 'hits')->increment('value');

    expect($affected)->toBe(1);
    $row = DB::table('up_counters')->where('key', 'hits')->first();
    expect((int) $row->value)->toBe(11);
})->group('stress');

test('increment with a custom amount', function () {
    DB::table('up_counters')->insert([
        'key' => 'views', 'value' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('up_counters')->where('key', 'views')->increment('value', 25);

    $row = DB::table('up_counters')->where('key', 'views')->first();
    expect((int) $row->value)->toBe(125);
})->group('stress');

test('decrement decreases value by 1 and returns affected rows', function () {
    DB::table('up_counters')->insert([
        'key' => 'stock', 'value' => 50,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $affected = DB::table('up_counters')->where('key', 'stock')->decrement('value');

    expect($affected)->toBe(1);
    $row = DB::table('up_counters')->where('key', 'stock')->first();
    expect((int) $row->value)->toBe(49);
})->group('stress');

test('decrement with a custom amount', function () {
    DB::table('up_counters')->insert([
        'key' => 'budget', 'value' => 200,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('up_counters')->where('key', 'budget')->decrement('value', 75);

    $row = DB::table('up_counters')->where('key', 'budget')->first();
    expect((int) $row->value)->toBe(125);
})->group('stress');

test('increment updates extra columns in the same statement', function () {
    DB::table('up_users')->insert([
        'email' => 'carol@example.com', 'name' => 'Carol', 'login_count' => 5,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('up_users')
        ->where('email', 'carol@example.com')
        ->increment('login_count', 1, ['name' => 'Carol Updated']);

    $row = DB::table('up_users')->where('email', 'carol@example.com')->first();
    expect((int) $row->login_count)->toBe(6);
    expect($row->name)->toBe('Carol Updated');
})->group('stress');

test('increment and decrement on multiple distinct rows only affect the targeted row', function () {
    DB::table('up_counters')->insert([
        ['key' => 'alpha', 'value' => 10, 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'beta',  'value' => 20, 'created_at' => now(), 'updated_at' => now()],
        ['key' => 'gamma', 'value' => 30, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('up_counters')->where('key', 'alpha')->increment('value', 5);
    DB::table('up_counters')->where('key', 'gamma')->decrement('value', 10);

    $alpha = DB::table('up_counters')->where('key', 'alpha')->first();
    $beta  = DB::table('up_counters')->where('key', 'beta')->first();
    $gamma = DB::table('up_counters')->where('key', 'gamma')->first();

    expect((int) $alpha->value)->toBe(15);
    expect((int) $beta->value)->toBe(20);  // untouched
    expect((int) $gamma->value)->toBe(20);
})->group('stress');

test('increment allows value to go negative', function () {
    DB::table('up_counters')->insert([
        'key' => 'neg', 'value' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('up_counters')->where('key', 'neg')->decrement('value', 10);

    $row = DB::table('up_counters')->where('key', 'neg')->first();
    expect((int) $row->value)->toBe(-7);
})->group('stress');
