<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagination correctness tests. Covers limit/offset, orderBy asc/desc,
 * paginate(), simplePaginate(), chunk(), chunkById(), and cursor()/lazy().
 *
 * Table prefix: pg_
 */
beforeEach(function () {
    Schema::dropIfExists('pg_items');

    Schema::create('pg_items', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->integer('score');
        $t->timestamp('created_at')->nullable();
        $t->timestamp('updated_at')->nullable();
    });

    // Insert 20 rows: score 1..20, name = "item-{score}"
    $rows = [];
    $base = CarbonImmutable::parse('2026-01-01 00:00:00');
    for ($i = 1; $i <= 20; $i++) {
        $rows[] = [
            'name'       => "item-{$i}",
            'score'      => $i,
            'created_at' => $base->addSeconds($i),
            'updated_at' => $base->addSeconds($i),
        ];
    }
    DB::table('pg_items')->insert($rows);
});

// ---------------------------------------------------------------------------
// limit / offset
// ---------------------------------------------------------------------------

test('limit returns the correct number of rows', function () {
    $rows = DB::table('pg_items')->orderBy('score')->limit(5)->get();

    expect($rows)->toHaveCount(5);
    expect((int) $rows->first()->score)->toBe(1);
    expect((int) $rows->last()->score)->toBe(5);
})->group('stress');

test('offset skips the correct number of rows', function () {
    $rows = DB::table('pg_items')->orderBy('score')->offset(10)->limit(5)->get();

    expect($rows)->toHaveCount(5);
    expect((int) $rows->first()->score)->toBe(11);
    expect((int) $rows->last()->score)->toBe(15);
})->group('stress');

test('offset beyond total count returns empty collection', function () {
    $rows = DB::table('pg_items')->orderBy('score')->offset(100)->limit(5)->get();

    expect($rows)->toHaveCount(0);
})->group('stress');

test('limit larger than row count returns all rows', function () {
    $rows = DB::table('pg_items')->orderBy('score')->limit(999)->get();

    expect($rows)->toHaveCount(20);
})->group('stress');

// ---------------------------------------------------------------------------
// orderBy asc / desc
// ---------------------------------------------------------------------------

test('orderBy score asc returns rows in ascending order', function () {
    $scores = DB::table('pg_items')->orderBy('score', 'asc')->pluck('score')->map(fn ($v) => (int) $v)->all();

    expect($scores)->toBe(range(1, 20));
})->group('stress');

test('orderBy score desc returns rows in descending order', function () {
    $scores = DB::table('pg_items')->orderBy('score', 'desc')->pluck('score')->map(fn ($v) => (int) $v)->all();

    expect($scores)->toBe(range(20, 1));
})->group('stress');

test('orderBy name asc sorts lexicographically', function () {
    $names = DB::table('pg_items')->orderBy('name', 'asc')->pluck('name')->all();

    $expected = $names; // capture actual order
    sort($expected);    // PHP sort = same collation as SQLite default
    expect($names)->toBe($expected);
})->group('stress');

// ---------------------------------------------------------------------------
// paginate()
// ---------------------------------------------------------------------------

test('paginate returns correct items on first page', function () {
    $page = DB::table('pg_items')->orderBy('score')->paginate(5, ['*'], 'page', 1);

    expect($page->total())->toBe(20);
    expect($page->lastPage())->toBe(4);
    expect($page->currentPage())->toBe(1);
    expect(count($page->items()))->toBe(5);
    expect((int) $page->items()[0]->score)->toBe(1);
    expect((int) $page->items()[4]->score)->toBe(5);
})->group('stress');

test('paginate returns correct items on last page', function () {
    $page = DB::table('pg_items')->orderBy('score')->paginate(5, ['*'], 'page', 4);

    expect($page->currentPage())->toBe(4);
    expect(count($page->items()))->toBe(5);
    expect((int) $page->items()[0]->score)->toBe(16);
    expect((int) $page->items()[4]->score)->toBe(20);
})->group('stress');

test('paginate hasMorePages is false on last page', function () {
    $page = DB::table('pg_items')->orderBy('score')->paginate(5, ['*'], 'page', 4);

    expect($page->hasMorePages())->toBeFalse();
})->group('stress');

test('paginate hasMorePages is true on non-last page', function () {
    $page = DB::table('pg_items')->orderBy('score')->paginate(5, ['*'], 'page', 1);

    expect($page->hasMorePages())->toBeTrue();
})->group('stress');

test('paginate total count is always correct regardless of page', function () {
    foreach ([1, 2, 3, 4] as $p) {
        $page = DB::table('pg_items')->orderBy('score')->paginate(5, ['*'], 'page', $p);
        expect($page->total())->toBe(20);
    }
})->group('stress');

test('paginate with perPage larger than total returns single page', function () {
    $page = DB::table('pg_items')->orderBy('score')->paginate(50, ['*'], 'page', 1);

    expect($page->total())->toBe(20);
    expect($page->lastPage())->toBe(1);
    expect($page->hasMorePages())->toBeFalse();
    expect(count($page->items()))->toBe(20);
})->group('stress');

// ---------------------------------------------------------------------------
// simplePaginate()
// ---------------------------------------------------------------------------

test('simplePaginate returns correct items on first page', function () {
    $page = DB::table('pg_items')->orderBy('score')->simplePaginate(5, ['*'], 'page', 1);

    expect(count($page->items()))->toBe(5);
    expect((int) $page->items()[0]->score)->toBe(1);
    expect((int) $page->items()[4]->score)->toBe(5);
    expect($page->hasMorePages())->toBeTrue();
})->group('stress');

test('simplePaginate hasMorePages is false on last page', function () {
    $page = DB::table('pg_items')->orderBy('score')->simplePaginate(5, ['*'], 'page', 4);

    expect(count($page->items()))->toBe(5);
    expect((int) $page->items()[0]->score)->toBe(16);
    expect($page->hasMorePages())->toBeFalse();
})->group('stress');

test('simplePaginate second page contains correct rows', function () {
    $page = DB::table('pg_items')->orderBy('score')->simplePaginate(7, ['*'], 'page', 2);

    // page 2 = rows 8..14 (scores 8 to 14)
    expect(count($page->items()))->toBe(7);
    expect((int) $page->items()[0]->score)->toBe(8);
    expect((int) $page->items()[6]->score)->toBe(14);
})->group('stress');

// ---------------------------------------------------------------------------
// chunk()
// ---------------------------------------------------------------------------

test('chunk visits all rows exactly once', function () {
    $collected = [];

    DB::table('pg_items')->orderBy('score')->chunk(6, function ($rows) use (&$collected) {
        foreach ($rows as $row) {
            $collected[] = (int) $row->score;
        }
    });

    sort($collected);
    expect($collected)->toBe(range(1, 20));
})->group('stress');

test('chunk processes rows in the given chunk size', function () {
    $chunkSizes = [];

    DB::table('pg_items')->orderBy('score')->chunk(7, function ($rows) use (&$chunkSizes) {
        $chunkSizes[] = count($rows);
    });

    // 20 rows / 7 = chunks of 7, 7, 6
    expect($chunkSizes)->toBe([7, 7, 6]);
})->group('stress');

test('chunk can be stopped early by returning false', function () {
    $collected = [];

    DB::table('pg_items')->orderBy('score')->chunk(5, function ($rows) use (&$collected) {
        foreach ($rows as $row) {
            $collected[] = (int) $row->score;
        }

        return false; // stop after first chunk
    });

    expect($collected)->toBe([1, 2, 3, 4, 5]);
})->group('stress');

// ---------------------------------------------------------------------------
// chunkById()
// ---------------------------------------------------------------------------

test('chunkById visits all rows exactly once', function () {
    $collected = [];

    DB::table('pg_items')->chunkById(6, function ($rows) use (&$collected) {
        foreach ($rows as $row) {
            $collected[] = (int) $row->score;
        }
    });

    sort($collected);
    expect($collected)->toBe(range(1, 20));
})->group('stress');

test('chunkById processes rows in the given chunk size', function () {
    $chunkSizes = [];

    DB::table('pg_items')->chunkById(7, function ($rows) use (&$chunkSizes) {
        $chunkSizes[] = count($rows);
    });

    expect($chunkSizes)->toBe([7, 7, 6]);
})->group('stress');

test('chunkById with custom column collects correct values', function () {
    $scores = [];

    DB::table('pg_items')->chunkById(5, function ($rows) use (&$scores) {
        foreach ($rows as $row) {
            $scores[] = (int) $row->score;
        }
    }, 'id');

    sort($scores);
    expect($scores)->toBe(range(1, 20));
})->group('stress');

test('chunkById can be stopped early by returning false', function () {
    $collected = [];

    DB::table('pg_items')->orderBy('id')->chunkById(5, function ($rows) use (&$collected) {
        foreach ($rows as $row) {
            $collected[] = (int) $row->score;
        }

        return false;
    });

    expect(count($collected))->toBe(5);
})->group('stress');

// ---------------------------------------------------------------------------
// lazy() / cursor()
// ---------------------------------------------------------------------------

test('lazy returns all rows as a lazy collection', function () {
    $scores = DB::table('pg_items')->orderBy('score')->lazy()->map(fn ($r) => (int) $r->score)->all();

    expect($scores)->toBe(range(1, 20));
})->group('stress');

test('lazy with chunk size still returns all rows', function () {
    $count = DB::table('pg_items')->orderBy('score')->lazy(7)->count();

    expect($count)->toBe(20);
})->group('stress');

test('lazy preserves ordering', function () {
    $scores = DB::table('pg_items')->orderBy('score', 'desc')->lazy()->map(fn ($r) => (int) $r->score)->all();

    expect($scores)->toBe(range(20, 1));
})->group('stress');

test('cursor returns all rows', function () {
    $scores = [];
    foreach (DB::table('pg_items')->orderBy('score')->cursor() as $row) {
        $scores[] = (int) $row->score;
    }

    expect($scores)->toBe(range(1, 20));
})->group('stress')->skip('cursor() opens a second in-process connection that cannot see the in-memory schema created by beforeEach; passes on file/sqld/remote backends');

test('cursor preserves descending order', function () {
    $scores = [];
    foreach (DB::table('pg_items')->orderBy('score', 'desc')->cursor() as $row) {
        $scores[] = (int) $row->score;
    }

    expect($scores)->toBe(range(20, 1));
})->group('stress')->skip('cursor() opens a second in-process connection that cannot see the in-memory schema created by beforeEach; passes on file/sqld/remote backends');

// ---------------------------------------------------------------------------
// Cross-page consistency
// ---------------------------------------------------------------------------

test('paginating with a where clause counts and pages filtered rows only', function () {
    // Scores > 10 = rows 11..20 = 10 rows
    $page1 = DB::table('pg_items')->where('score', '>', 10)->orderBy('score')->paginate(4, ['*'], 'page', 1);
    $page2 = DB::table('pg_items')->where('score', '>', 10)->orderBy('score')->paginate(4, ['*'], 'page', 2);
    $page3 = DB::table('pg_items')->where('score', '>', 10)->orderBy('score')->paginate(4, ['*'], 'page', 3);

    expect($page1->total())->toBe(10);
    expect($page1->lastPage())->toBe(3);
    expect(count($page1->items()))->toBe(4);
    expect((int) $page1->items()[0]->score)->toBe(11);

    expect(count($page2->items()))->toBe(4);
    expect((int) $page2->items()[0]->score)->toBe(15);

    expect(count($page3->items()))->toBe(2);
    expect((int) $page3->items()[0]->score)->toBe(19);
    expect((int) $page3->items()[1]->score)->toBe(20);
})->group('stress');

test('all pages together cover the full dataset without duplicates', function () {
    $perPage = 6;
    $allScores = [];

    for ($p = 1; $p <= 4; $p++) {
        $page = DB::table('pg_items')->orderBy('score')->paginate($perPage, ['*'], 'page', $p);
        foreach ($page->items() as $item) {
            $allScores[] = (int) $item->score;
        }
        if (! $page->hasMorePages()) {
            break;
        }
    }

    sort($allScores);
    expect($allScores)->toBe(range(1, 20));
    expect(array_unique($allScores))->toHaveCount(20);
})->group('stress');
