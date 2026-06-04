<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backend-agnostic correctness checks. Runs against whatever LIBSQL_TEST_BACKEND
 * selects (memory|file|sqld|remote|replica). Self-contained schema so it is
 * idempotent across repeated runs on a persistent (remote) database.
 */
beforeEach(function () {
    Schema::dropIfExists('stress_children');
    Schema::dropIfExists('stress_items');

    Schema::create('stress_items', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->integer('qty');
        $t->float('price');
        $t->boolean('active');
        $t->text('note')->nullable();
        $t->timestamp('happened_at')->nullable();
        $t->timestamps();
    });

    Schema::create('stress_children', function (Blueprint $t) {
        $t->id();
        $t->foreignId('item_id');
        $t->string('label');
        $t->timestamps();
    });
});

function item(array $overrides = []): array
{
    return array_merge([
        'name' => 'widget', 'qty' => 1, 'price' => 1.0, 'active' => true,
        'note' => null, 'happened_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

test('insertGetId returns a real autoincrement id (not 0)', function () {
    $id = DB::table('stress_items')->insertGetId(item());

    expect($id)->toBeInt()->toBeGreaterThan(0);
})->group('stress');

test('round-trips scalar data types', function () {
    DB::table('stress_items')->insert(item([
        'name' => 'widget', 'qty' => 42, 'price' => 3.14, 'active' => true,
        'note' => null, 'happened_at' => CarbonImmutable::parse('2026-02-03 04:05:06'),
    ]));

    $row = DB::table('stress_items')->first();

    expect($row->name)->toBe('widget');
    expect((int) $row->qty)->toBe(42);
    expect((float) $row->price)->toBe(3.14);
    expect((bool) $row->active)->toBeTrue();
    expect($row->note)->toBeNull();
    expect((string) $row->happened_at)->toContain('2026-02-03 04:05:06');
})->group('stress');

test('immutable (CarbonImmutable) timestamps persist', function () {
    DB::table('stress_items')->insert(item([
        'name' => 'imm',
        'happened_at' => CarbonImmutable::now(),
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]));

    expect(DB::table('stress_items')->where('name', 'imm')->count())->toBe(1);
})->group('stress');

test('insertGetId inside a transaction yields ids usable by dependent rows', function () {
    $itemId = DB::transaction(function () {
        $id = DB::table('stress_items')->insertGetId(item(['name' => 'parent']));
        DB::table('stress_children')->insert([
            'item_id' => $id, 'label' => 'child', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    });

    expect($itemId)->toBeGreaterThan(0);
    expect(DB::table('stress_children')->where('item_id', $itemId)->count())->toBe(1);
})->group('stress');

test('sequential inserts in a transaction get distinct positive ids', function () {
    $ids = DB::transaction(function () {
        $out = [];
        for ($i = 0; $i < 5; $i++) {
            $out[] = DB::table('stress_items')->insertGetId(item(['name' => "t{$i}", 'qty' => $i]));
        }

        return $out;
    });

    expect($ids)->toHaveCount(5);
    expect(array_unique($ids))->toHaveCount(5);
    expect(min($ids))->toBeGreaterThan(0);
})->group('stress');

test('bulk insert and aggregates', function () {
    $rows = [];
    for ($i = 1; $i <= 50; $i++) {
        $rows[] = item(['name' => "n{$i}", 'qty' => $i, 'price' => $i * 1.0, 'active' => $i % 2 === 0]);
    }
    DB::table('stress_items')->insert($rows);

    expect(DB::table('stress_items')->count())->toBe(50);
    expect((int) DB::table('stress_items')->sum('qty'))->toBe(1275);
    expect(DB::table('stress_items')->where('active', true)->count())->toBe(25);
})->group('stress');

test('update and delete report affected row counts', function () {
    DB::table('stress_items')->insert([item(['qty' => 1]), item(['qty' => 2]), item(['qty' => 3])]);

    $updated = DB::table('stress_items')->where('qty', '>', 1)->update(['active' => false]);
    $deleted = DB::table('stress_items')->where('qty', 1)->delete();

    expect($updated)->toBe(2);
    expect($deleted)->toBe(1);
    expect(DB::table('stress_items')->count())->toBe(2);
})->group('stress');

test('handles unicode and large text payloads', function () {
    $big = str_repeat('héllo🚀漢字 ', 4000); // ~multi-KB multibyte string

    DB::table('stress_items')->insert(item(['name' => 'big', 'note' => $big]));

    $row = DB::table('stress_items')->where('name', 'big')->first();
    expect($row->note)->toBe($big);
})->group('stress');

test('stores an empty string in a text column without crashing', function () {
    // Regression: '' was misclassified as a blob -> new Blob('') -> FFI panic.
    // parameterCasting now routes '' to text, but binding '' still aborts inside
    // the vendored turso/libsql CharBox (if ($str) is falsy for ''), so this is
    // skipped until that zero-length CharBox bug is fixed. Flip to active then.
    DB::table('stress_items')->insert(item(['name' => 'empty', 'note' => '']));

    $row = DB::table('stress_items')->where('name', 'empty')->first();
    expect($row->note)->toBe('');
})->skip('pending turso/libsql CharBox zero-length fix')->group('stress');
