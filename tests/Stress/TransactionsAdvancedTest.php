<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Advanced transaction stress tests: nested transactions / savepoints,
 * full & partial rollbacks, transaction return values, and insertGetId /
 * lastInsertId correctness at every nesting level.
 *
 * Table prefix: tx_
 */

// ---------------------------------------------------------------------------
// Schema setup – idempotent so re-runs on persistent backends are safe.
// ---------------------------------------------------------------------------

beforeEach(function () {
    Schema::dropIfExists('tx_events');
    Schema::dropIfExists('tx_items');

    Schema::create('tx_items', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->integer('val')->default(0);
        $t->timestamps();
    });

    Schema::create('tx_events', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('item_id');
        $t->string('kind');
        $t->timestamps();
    });
});

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function txRow(array $overrides = []): array
{
    return array_merge([
        'name'       => 'item',
        'val'        => 0,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ], $overrides);
}

function txEvent(int $itemId, string $kind): array
{
    return [
        'item_id'    => $itemId,
        'kind'       => $kind,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ];
}

// ---------------------------------------------------------------------------
// Basic transaction: full rollback discards all inserts
// ---------------------------------------------------------------------------

test('full rollback leaves the table empty', function () {
    DB::beginTransaction();

    DB::table('tx_items')->insert(txRow(['name' => 'a']));
    DB::table('tx_items')->insert(txRow(['name' => 'b']));

    expect(DB::table('tx_items')->count())->toBe(2); // visible inside tx

    DB::rollBack();

    expect(DB::table('tx_items')->count())->toBe(0);
})->group('stress');

// ---------------------------------------------------------------------------
// Basic transaction: commit persists data
// ---------------------------------------------------------------------------

test('committed transaction persists all inserts', function () {
    DB::beginTransaction();

    DB::table('tx_items')->insert(txRow(['name' => 'committed']));
    DB::table('tx_items')->insert(txRow(['name' => 'also-committed']));

    DB::commit();

    expect(DB::table('tx_items')->count())->toBe(2);
    $names = DB::table('tx_items')->orderBy('id')->pluck('name')->all();
    expect($names)->toBe(['committed', 'also-committed']);
})->group('stress');

// ---------------------------------------------------------------------------
// Transaction::transaction() returns the closure's return value
// ---------------------------------------------------------------------------

test('DB::transaction() returns the value returned by the closure', function () {
    $result = DB::transaction(function () {
        DB::table('tx_items')->insert(txRow(['name' => 'ret']));

        return 'hello-from-transaction';
    });

    expect($result)->toBe('hello-from-transaction');
    expect(DB::table('tx_items')->count())->toBe(1);
})->group('stress');

test('DB::transaction() can return a complex value (array)', function () {
    $result = DB::transaction(function () {
        $id1 = DB::table('tx_items')->insertGetId(txRow(['name' => 'x']));
        $id2 = DB::table('tx_items')->insertGetId(txRow(['name' => 'y']));

        return ['ids' => [$id1, $id2], 'count' => 2];
    });

    expect($result['count'])->toBe(2);
    expect($result['ids'])->toHaveCount(2);
    expect(min($result['ids']))->toBeGreaterThan(0);
    expect(count(array_unique($result['ids'])))->toBe(2);
})->group('stress');

// ---------------------------------------------------------------------------
// insertGetId inside a transaction returns the correct row id
// ---------------------------------------------------------------------------

test('insertGetId inside a transaction returns a real positive integer', function () {
    $id = DB::transaction(function () {
        return DB::table('tx_items')->insertGetId(txRow(['name' => 'single']));
    });

    expect($id)->toBeInt()->toBeGreaterThan(0);

    $row = DB::table('tx_items')->find($id);
    expect($row)->not->toBeNull();
    expect($row->name)->toBe('single');
})->group('stress');

test('sequential insertGetId calls inside one transaction yield distinct positive ids', function () {
    $ids = DB::transaction(function () {
        return [
            DB::table('tx_items')->insertGetId(txRow(['name' => 'seq1'])),
            DB::table('tx_items')->insertGetId(txRow(['name' => 'seq2'])),
            DB::table('tx_items')->insertGetId(txRow(['name' => 'seq3'])),
        ];
    });

    expect($ids)->toHaveCount(3);
    // All positive
    foreach ($ids as $id) {
        expect($id)->toBeGreaterThan(0);
    }
    // All distinct
    expect(count(array_unique($ids)))->toBe(3);
    // IDs are monotonically increasing (SQLite autoincrement)
    expect($ids[1])->toBeGreaterThan($ids[0]);
    expect($ids[2])->toBeGreaterThan($ids[1]);
})->group('stress');

// ---------------------------------------------------------------------------
// Nested transactions / savepoints
// ---------------------------------------------------------------------------

test('nested DB::transaction() calls commit both levels', function () {
    $outerResult = DB::transaction(function () {
        $outerItemId = DB::table('tx_items')->insertGetId(txRow(['name' => 'outer']));

        $innerResult = DB::transaction(function () use ($outerItemId) {
            $innerItemId = DB::table('tx_items')->insertGetId(txRow(['name' => 'inner']));
            DB::table('tx_events')->insert(txEvent($outerItemId, 'created'));
            DB::table('tx_events')->insert(txEvent($innerItemId, 'created'));

            return $innerItemId;
        });

        return ['outer' => $outerItemId, 'inner' => $innerResult];
    });

    // Both rows persist
    expect(DB::table('tx_items')->count())->toBe(2);
    expect(DB::table('tx_events')->count())->toBe(2);

    // IDs are positive and distinct
    expect($outerResult['outer'])->toBeGreaterThan(0);
    expect($outerResult['inner'])->toBeGreaterThan(0);
    expect($outerResult['inner'])->not->toBe($outerResult['outer']);
})->group('stress');

test('inner transaction rollback (exception) is caught and outer transaction commits', function () {
    // When the inner closure throws, DB::transaction() rolls back only to the
    // savepoint (inner level).  The outer can catch and carry on.
    $outerItemId = null;

    DB::transaction(function () use (&$outerItemId) {
        $outerItemId = DB::table('tx_items')->insertGetId(txRow(['name' => 'outer-survives']));

        try {
            DB::transaction(function () {
                DB::table('tx_items')->insert(txRow(['name' => 'inner-lost']));
                throw new \RuntimeException('inner failure');
            });
        } catch (\RuntimeException $e) {
            // Swallow: the inner savepoint has been rolled back; outer continues.
        }

        DB::table('tx_events')->insert(txEvent($outerItemId, 'after-inner-fail'));
    });

    // Only the outer item and the event it logged should survive.
    $names = DB::table('tx_items')->orderBy('id')->pluck('name')->all();
    expect($names)->toBe(['outer-survives']);
    expect(DB::table('tx_events')->where('kind', 'after-inner-fail')->count())->toBe(1);
})->group('stress');

test('outer transaction rollback discards both outer and inner committed inserts', function () {
    try {
        DB::transaction(function () {
            DB::table('tx_items')->insert(txRow(['name' => 'outer-lost']));

            // Inner succeeds (saves to savepoint level)
            DB::transaction(function () {
                DB::table('tx_items')->insert(txRow(['name' => 'inner-lost']));
            });

            // Outer throws after inner succeeded
            throw new \RuntimeException('outer failure');
        });
    } catch (\RuntimeException $e) {
        // Expected; swallow.
    }

    // Nothing should persist – the outer rollback undoes everything.
    expect(DB::table('tx_items')->count())->toBe(0);
})->group('stress');

// ---------------------------------------------------------------------------
// Partial rollback via DB::rollBack() to an explicit level
// ---------------------------------------------------------------------------

test('partial rollback to level 1 discards level-2 inserts only', function () {
    DB::beginTransaction(); // level 1

    $id1 = DB::table('tx_items')->insertGetId(txRow(['name' => 'level-1']));

    DB::beginTransaction(); // level 2 – uses a savepoint

    DB::table('tx_items')->insert(txRow(['name' => 'level-2-a']));
    DB::table('tx_items')->insert(txRow(['name' => 'level-2-b']));

    // Roll back only to level 1 (discards the level-2 savepoint)
    DB::rollBack(1);

    // Commit the level-1 transaction
    DB::commit();

    $names = DB::table('tx_items')->orderBy('id')->pluck('name')->all();
    expect($names)->toBe(['level-1']);
    expect($id1)->toBeGreaterThan(0);
})->group('stress');

// ---------------------------------------------------------------------------
// insertGetId correctness at each nesting level
// ---------------------------------------------------------------------------

test('insertGetId at each nesting level returns a usable id for dependent rows', function () {
    [$idLevel1, $idLevel2, $idLevel3] = DB::transaction(function () {
        $l1 = DB::table('tx_items')->insertGetId(txRow(['name' => 'L1', 'val' => 1]));

        $inner = DB::transaction(function () use ($l1) {
            $l2 = DB::table('tx_items')->insertGetId(txRow(['name' => 'L2', 'val' => 2]));
            DB::table('tx_events')->insert(txEvent($l1, 'ref-from-L2'));

            $innermost = DB::transaction(function () use ($l2) {
                $l3 = DB::table('tx_items')->insertGetId(txRow(['name' => 'L3', 'val' => 3]));
                DB::table('tx_events')->insert(txEvent($l2, 'ref-from-L3'));

                return $l3;
            });

            return [$l2, $innermost];
        });

        return [$l1, $inner[0], $inner[1]];
    });

    // All three ids are positive and distinct
    expect($idLevel1)->toBeGreaterThan(0);
    expect($idLevel2)->toBeGreaterThan(0);
    expect($idLevel3)->toBeGreaterThan(0);
    expect(count(array_unique([$idLevel1, $idLevel2, $idLevel3])))->toBe(3);

    // All three rows exist with the right val
    expect((int) DB::table('tx_items')->where('id', $idLevel1)->value('val'))->toBe(1);
    expect((int) DB::table('tx_items')->where('id', $idLevel2)->value('val'))->toBe(2);
    expect((int) DB::table('tx_items')->where('id', $idLevel3)->value('val'))->toBe(3);

    // Event rows reference the correct parent items
    expect(DB::table('tx_events')->where('item_id', $idLevel1)->where('kind', 'ref-from-L2')->count())->toBe(1);
    expect(DB::table('tx_events')->where('item_id', $idLevel2)->where('kind', 'ref-from-L3')->count())->toBe(1);
})->group('stress');

// ---------------------------------------------------------------------------
// Exception inside transaction: table stays clean
// ---------------------------------------------------------------------------

test('exception thrown inside DB::transaction() triggers rollback and table is empty', function () {
    expect(function () {
        DB::transaction(function () {
            DB::table('tx_items')->insert(txRow(['name' => 'will-vanish']));
            throw new \LogicException('deliberate failure');
        });
    })->toThrow(\LogicException::class, 'deliberate failure');

    expect(DB::table('tx_items')->count())->toBe(0);
})->group('stress');

// ---------------------------------------------------------------------------
// transactionLevel() tracks depth correctly
// ---------------------------------------------------------------------------

test('transactionLevel() reflects current nesting depth', function () {
    expect(DB::transactionLevel())->toBe(0);

    DB::beginTransaction();
    expect(DB::transactionLevel())->toBe(1);

    DB::beginTransaction(); // savepoint
    expect(DB::transactionLevel())->toBe(2);

    DB::beginTransaction(); // savepoint
    expect(DB::transactionLevel())->toBe(3);

    DB::rollBack(); // rolls back to level 2
    expect(DB::transactionLevel())->toBe(2);

    DB::commit(); // releases savepoint at level 2 → level 1
    expect(DB::transactionLevel())->toBe(1);

    DB::commit(); // commits outer → level 0
    expect(DB::transactionLevel())->toBe(0);
})->group('stress');

// ---------------------------------------------------------------------------
// Multiple independent top-level transactions run sequentially
// ---------------------------------------------------------------------------

test('two sequential top-level transactions commit independently', function () {
    // First transaction
    $id1 = DB::transaction(function () {
        return DB::table('tx_items')->insertGetId(txRow(['name' => 'tx1']));
    });

    // Second transaction
    $id2 = DB::transaction(function () {
        return DB::table('tx_items')->insertGetId(txRow(['name' => 'tx2']));
    });

    expect($id1)->toBeGreaterThan(0);
    expect($id2)->toBeGreaterThan($id1);
    expect(DB::table('tx_items')->count())->toBe(2);

    $names = DB::table('tx_items')->orderBy('id')->pluck('name')->all();
    expect($names)->toBe(['tx1', 'tx2']);
})->group('stress');
