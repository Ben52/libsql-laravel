<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-operations stress tests.
 *
 * Table-name prefix: sch_
 *
 * Covers:
 *   - Many column types in a single table (round-trips)
 *   - Adding a column via Schema::table (nullable, with ->after())
 *   - Dropping a column
 *   - Regular index creation and use
 *   - Unique constraint enforcement (violation must throw)
 *   - Self-referencing foreign key (adjacency-list / parent_id pattern)
 */

beforeEach(function () {
    // Drop in reverse dependency order for FK safety.
    Schema::dropIfExists('sch_nodes');
    Schema::dropIfExists('sch_wide');
});

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function schWideRow(array $overrides = []): array
{
    $now = CarbonImmutable::now()->toDateTimeString();

    return array_merge([
        'label'        => 'row',
        'qty'          => 1,
        'big_qty'      => 9999999999,
        'price'        => 9.99,
        'ratio'        => 0.123456789,
        'amount'       => '12345.6789',
        'active'       => true,
        'note'         => null,
        'body'         => null,
        'raw_data'     => null,
        'happened_at'  => null,
        'born_on'      => null,
        'meta'         => null,
        'created_at'   => $now,
        'updated_at'   => $now,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Test 1: many column types in one table
// ---------------------------------------------------------------------------

test('schema: wide table with many column types can be created and rows round-trip', function () {
    Schema::create('sch_wide', function (Blueprint $t) {
        $t->id();
        $t->string('label', 191);
        $t->integer('qty');
        $t->bigInteger('big_qty');
        $t->float('price');
        $t->double('ratio');
        $t->decimal('amount', 12, 4);
        $t->boolean('active');
        $t->text('note')->nullable();
        $t->longText('body')->nullable();
        $t->binary('raw_data')->nullable();
        $t->timestamp('happened_at')->nullable();
        $t->date('born_on')->nullable();
        $t->json('meta')->nullable();
        $t->timestamps();
    });

    $now = CarbonImmutable::now();

    DB::table('sch_wide')->insert(schWideRow([
        'label'       => 'full',
        'qty'         => 42,
        'big_qty'     => 123456789012,
        'price'       => 3.14,
        'ratio'       => 0.271828,
        'amount'      => '9876.5432',
        'active'      => true,
        'note'        => 'a note',
        'body'        => str_repeat('x', 1000),
        'happened_at' => $now->toDateTimeString(),
        'born_on'     => '1990-06-15',
        'meta'        => json_encode(['k' => 'v']),
    ]));

    $row = DB::table('sch_wide')->where('label', 'full')->first();

    expect($row)->not->toBeNull();
    expect((int) $row->qty)->toBe(42);
    expect((int) $row->big_qty)->toBe(123456789012);
    expect((float) $row->price)->toBe(3.14);
    expect($row->note)->toBe('a note');
    expect(strlen($row->body))->toBe(1000);
    expect((bool) $row->active)->toBeTrue();
    expect((string) $row->born_on)->toContain('1990-06-15');
    $meta = is_string($row->meta) ? json_decode($row->meta, true) : (array) $row->meta;
    expect($meta['k'])->toBe('v');
})->group('stress');

// ---------------------------------------------------------------------------
// Test 2: add a nullable column via Schema::table
// ---------------------------------------------------------------------------

test('schema: adding a nullable column to an existing table persists and returns null for old rows', function () {
    Schema::create('sch_wide', function (Blueprint $t) {
        $t->id();
        $t->string('label', 191);
        $t->integer('qty');
        $t->timestamps();
    });

    $now = CarbonImmutable::now()->toDateTimeString();
    DB::table('sch_wide')->insert(['label' => 'before', 'qty' => 1, 'created_at' => $now, 'updated_at' => $now]);

    Schema::table('sch_wide', function (Blueprint $t) {
        $t->string('extra', 100)->nullable()->after('label');
    });

    // Existing row should have null for the new column.
    $existing = DB::table('sch_wide')->where('label', 'before')->first();
    expect($existing->extra)->toBeNull();

    // New rows should store and return the value.
    DB::table('sch_wide')->insert(['label' => 'after', 'qty' => 2, 'extra' => 'hello', 'created_at' => $now, 'updated_at' => $now]);
    $new = DB::table('sch_wide')->where('label', 'after')->first();
    expect($new->extra)->toBe('hello');
})->group('stress');

// ---------------------------------------------------------------------------
// Test 3: drop a column
// ---------------------------------------------------------------------------

test('schema: dropping a column makes it no longer selectable', function () {
    Schema::create('sch_wide', function (Blueprint $t) {
        $t->id();
        $t->string('label', 191);
        $t->string('to_drop', 100)->nullable();
        $t->timestamps();
    });

    $now = CarbonImmutable::now()->toDateTimeString();
    DB::table('sch_wide')->insert(['label' => 'r1', 'to_drop' => 'gone', 'created_at' => $now, 'updated_at' => $now]);

    // Verify the column exists before dropping.
    $before = DB::table('sch_wide')->where('label', 'r1')->first();
    expect($before->to_drop)->toBe('gone');

    Schema::table('sch_wide', function (Blueprint $t) {
        $t->dropColumn('to_drop');
    });

    // After dropping, selecting it should either return null or throw — we verify
    // the column is gone by checking the column listing.
    $columns = array_column(Schema::getColumns('sch_wide'), 'name');
    expect($columns)->not->toContain('to_drop');
})->group('stress');

// ---------------------------------------------------------------------------
// Test 4: index creation speeds up (and exists on) a column
// ---------------------------------------------------------------------------

test('schema: index on a column is created and the table is queryable via that column', function () {
    Schema::create('sch_wide', function (Blueprint $t) {
        $t->id();
        $t->string('label', 191)->index();
        $t->integer('qty');
        $t->timestamps();
    });

    $now = CarbonImmutable::now()->toDateTimeString();
    DB::table('sch_wide')->insert([
        ['label' => 'alpha', 'qty' => 10, 'created_at' => $now, 'updated_at' => $now],
        ['label' => 'beta',  'qty' => 20, 'created_at' => $now, 'updated_at' => $now],
        ['label' => 'gamma', 'qty' => 30, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $row = DB::table('sch_wide')->where('label', 'beta')->first();

    expect($row)->not->toBeNull();
    expect((int) $row->qty)->toBe(20);
    expect(DB::table('sch_wide')->where('label', 'alpha')->count())->toBe(1);
})->group('stress');

// ---------------------------------------------------------------------------
// Test 5: unique constraint prevents duplicates
// ---------------------------------------------------------------------------

test('schema: unique constraint on a column throws on duplicate insert', function () {
    Schema::create('sch_wide', function (Blueprint $t) {
        $t->id();
        $t->string('label', 191)->unique();
        $t->integer('qty');
        $t->timestamps();
    });

    $now = CarbonImmutable::now()->toDateTimeString();
    DB::table('sch_wide')->insert(['label' => 'unique_val', 'qty' => 1, 'created_at' => $now, 'updated_at' => $now]);

    // Second insert with the same label must throw a QueryException.
    $threw = false;
    try {
        DB::table('sch_wide')->insert(['label' => 'unique_val', 'qty' => 2, 'created_at' => $now, 'updated_at' => $now]);
    } catch (QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    // Only the first row should exist.
    expect(DB::table('sch_wide')->where('label', 'unique_val')->count())->toBe(1);
})->group('stress');

// ---------------------------------------------------------------------------
// Test 6: unique constraint via Schema::table (added after creation)
// ---------------------------------------------------------------------------

test('schema: unique index added via Schema::table enforces uniqueness on subsequent inserts', function () {
    Schema::create('sch_wide', function (Blueprint $t) {
        $t->id();
        $t->string('label', 191);
        $t->integer('qty');
        $t->timestamps();
    });

    $now = CarbonImmutable::now()->toDateTimeString();
    // Insert two different rows first (before the unique constraint exists).
    DB::table('sch_wide')->insert([
        ['label' => 'a', 'qty' => 1, 'created_at' => $now, 'updated_at' => $now],
        ['label' => 'b', 'qty' => 2, 'created_at' => $now, 'updated_at' => $now],
    ]);

    Schema::table('sch_wide', function (Blueprint $t) {
        $t->unique('label');
    });

    // A new duplicate must be rejected.
    $threw = false;
    try {
        DB::table('sch_wide')->insert(['label' => 'a', 'qty' => 99, 'created_at' => $now, 'updated_at' => $now]);
    } catch (QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    // Original rows are intact.
    expect(DB::table('sch_wide')->count())->toBe(2);
})->group('stress');

// ---------------------------------------------------------------------------
// Test 7: self-referencing foreign key (adjacency list / parent_id)
// ---------------------------------------------------------------------------

test('schema: self-referencing foreign key table stores and retrieves tree structure', function () {
    Schema::create('sch_nodes', function (Blueprint $t) {
        $t->id();
        $t->string('name', 191);
        $t->unsignedBigInteger('parent_id')->nullable();
        $t->timestamps();
        $t->foreign('parent_id')->references('id')->on('sch_nodes')->nullOnDelete();
    });

    $now = CarbonImmutable::now()->toDateTimeString();

    $rootId = DB::table('sch_nodes')->insertGetId([
        'name'       => 'root',
        'parent_id'  => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $childId1 = DB::table('sch_nodes')->insertGetId([
        'name'       => 'child1',
        'parent_id'  => $rootId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $childId2 = DB::table('sch_nodes')->insertGetId([
        'name'       => 'child2',
        'parent_id'  => $rootId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $grandchildId = DB::table('sch_nodes')->insertGetId([
        'name'       => 'grandchild',
        'parent_id'  => $childId1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Root has no parent.
    $root = DB::table('sch_nodes')->where('id', $rootId)->first();
    expect($root->name)->toBe('root');
    expect($root->parent_id)->toBeNull();

    // Children reference root as parent.
    $children = DB::table('sch_nodes')
        ->where('parent_id', $rootId)
        ->orderBy('name')
        ->get();
    expect($children)->toHaveCount(2);
    expect($children[0]->name)->toBe('child1');
    expect($children[1]->name)->toBe('child2');

    // Grandchild references child1 as parent.
    $grandchild = DB::table('sch_nodes')->where('id', $grandchildId)->first();
    expect($grandchild->name)->toBe('grandchild');
    expect((int) $grandchild->parent_id)->toBe($childId1);

    // Self-join to resolve two levels at once.
    $rows = DB::table('sch_nodes as n')
        ->join('sch_nodes as p', 'p.id', '=', 'n.parent_id')
        ->where('n.id', $grandchildId)
        ->select('n.name as child_name', 'p.name as parent_name')
        ->first();
    expect($rows->child_name)->toBe('grandchild');
    expect($rows->parent_name)->toBe('child1');
})->group('stress');

// ---------------------------------------------------------------------------
// Test 8: composite index (two-column) created via Schema::table
// ---------------------------------------------------------------------------

test('schema: composite index on two columns is created and queries using both columns succeed', function () {
    Schema::create('sch_wide', function (Blueprint $t) {
        $t->id();
        $t->string('label', 191);
        $t->integer('qty');
        $t->timestamps();
    });

    Schema::table('sch_wide', function (Blueprint $t) {
        $t->index(['label', 'qty'], 'sch_wide_label_qty_idx');
    });

    $now = CarbonImmutable::now()->toDateTimeString();
    DB::table('sch_wide')->insert([
        ['label' => 'x', 'qty' => 1, 'created_at' => $now, 'updated_at' => $now],
        ['label' => 'x', 'qty' => 2, 'created_at' => $now, 'updated_at' => $now],
        ['label' => 'y', 'qty' => 1, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $count = DB::table('sch_wide')->where('label', 'x')->where('qty', 1)->count();
    expect($count)->toBe(1);

    $row = DB::table('sch_wide')->where('label', 'x')->where('qty', 2)->first();
    expect($row)->not->toBeNull();
    expect((int) $row->qty)->toBe(2);
})->group('stress');
