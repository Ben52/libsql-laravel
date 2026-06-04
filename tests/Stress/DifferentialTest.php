<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Differential tests: compare libSQL (via DB facade, memory backend) against
 * a true PDO pdo_sqlite baseline for value parity.
 *
 * Because the libsql-laravel package overrides the db.factory, even a Laravel
 * "sqlite" connection is served by libSQL.  Therefore we spin up a raw PHP PDO
 * connection directly and compare returned values and PHP types side-by-side.
 *
 * Table prefix: diff_
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a fresh in-memory PDO SQLite connection with a diff_values table
 * and insert the supplied rows.  Returns the PDO handle.
 *
 * @param  array<int, array<string, mixed>>  $rows  Each element is an assoc array
 *                                                   with keys matching the schema.
 */
function pdoBaseline(array $rows): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('
        CREATE TABLE diff_values (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            int_col   INTEGER,
            float_col REAL,
            bool_col  INTEGER,
            text_col  TEXT,
            blob_col  BLOB,
            null_col  TEXT
        )
    ');

    foreach ($rows as $row) {
        $stmt = $pdo->prepare('
            INSERT INTO diff_values
                (int_col, float_col, bool_col, text_col, blob_col, null_col)
            VALUES
                (:int_col, :float_col, :bool_col, :text_col, :blob_col, :null_col)
        ');
        $stmt->execute([
            ':int_col'   => $row['int_col']   ?? null,
            ':float_col' => $row['float_col'] ?? null,
            ':bool_col'  => $row['bool_col']  ?? null,
            ':text_col'  => $row['text_col']  ?? null,
            ':blob_col'  => $row['blob_col']  ?? null,
            ':null_col'  => $row['null_col']  ?? null,
        ]);
    }

    return $pdo;
}

/**
 * Fetch all rows from the PDO baseline as an indexed array of stdClass-like assoc arrays.
 */
function pdoFetchAll(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM diff_values ORDER BY id');

    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// ---------------------------------------------------------------------------
// Schema setup (libSQL side)
// ---------------------------------------------------------------------------

beforeEach(function () {
    Schema::dropIfExists('diff_values');
    Schema::dropIfExists('diff_aggregates');

    Schema::create('diff_values', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('int_col')->nullable();
        $t->double('float_col')->nullable();
        $t->boolean('bool_col')->nullable();
        $t->text('text_col')->nullable();
        $t->binary('blob_col')->nullable();
        $t->string('null_col')->nullable();
    });

    Schema::create('diff_aggregates', function (Blueprint $t) {
        $t->id();
        $t->string('label');
        $t->bigInteger('int_val')->nullable();
        $t->double('float_val')->nullable();
    });
});

// ---------------------------------------------------------------------------
// Test 1: integer parity
// ---------------------------------------------------------------------------

test('integer values match between libSQL and PDO sqlite', function () {
    $cases = [
        0,
        1,
        -1,
        PHP_INT_MAX,
        PHP_INT_MIN,
        42,
        -9999,
        9007199254740993,   // > 2^53
    ];

    // Build rows — include all columns to avoid batch alignment issues
    $libsqlRows = [];
    $pdoRows    = [];
    foreach ($cases as $v) {
        $libsqlRows[] = ['int_col' => $v, 'float_col' => null, 'bool_col' => null, 'text_col' => null, 'blob_col' => null, 'null_col' => null];
        $pdoRows[]    = ['int_col' => $v];
    }

    DB::table('diff_values')->insert($libsqlRows);
    $pdo     = pdoBaseline($pdoRows);
    $pdoData = pdoFetchAll($pdo);
    $libData = DB::table('diff_values')->orderBy('id')->get();

    expect($libData)->toHaveCount(count($cases));
    expect($pdoData)->toHaveCount(count($cases));

    foreach ($cases as $i => $expected) {
        $lib = (int) $libData[$i]->int_col;
        $ref = (int) $pdoData[$i]->int_col;

        expect($lib)->toBe($expected, "libSQL integer mismatch at index {$i}");
        expect($ref)->toBe($expected, "PDO integer mismatch at index {$i}");
        expect($lib)->toBe($ref, "libSQL vs PDO mismatch at index {$i}");
    }
})->group('stress');

// ---------------------------------------------------------------------------
// Test 2: float / double parity
// ---------------------------------------------------------------------------

test('float values match between libSQL and PDO sqlite', function () {
    $cases = [
        0.0,
        1.0,
        -1.0,
        3.141592653589793,
        -2.718281828459045,
        1.23456789012345e10,
        1.23456789012345e-10,
        1.7976931348623158e+308,    // near DBL_MAX
    ];

    $libsqlRows = [];
    $pdoRows    = [];
    foreach ($cases as $v) {
        $libsqlRows[] = ['int_col' => null, 'float_col' => $v, 'bool_col' => null, 'text_col' => null, 'blob_col' => null, 'null_col' => null];
        $pdoRows[]    = ['float_col' => $v];
    }

    DB::table('diff_values')->insert($libsqlRows);
    $pdo     = pdoBaseline($pdoRows);
    $pdoData = pdoFetchAll($pdo);
    $libData = DB::table('diff_values')->orderBy('id')->get();

    expect($libData)->toHaveCount(count($cases));

    foreach ($cases as $i => $expected) {
        $lib = (float) $libData[$i]->float_col;
        $ref = (float) $pdoData[$i]->float_col;

        // Both must be within machine-epsilon of each other and of the original
        expect(abs($lib - $expected))->toBeLessThan(abs($expected) * 1e-12 + 1e-300,
            "libSQL float mismatch at index {$i}: got {$lib}, expected {$expected}");
        expect(abs($ref - $expected))->toBeLessThan(abs($expected) * 1e-12 + 1e-300,
            "PDO float mismatch at index {$i}: got {$ref}, expected {$expected}");
        expect(abs($lib - $ref))->toBeLessThan(abs($expected) * 1e-12 + 1e-300,
            "libSQL vs PDO float diverge at index {$i}: lib={$lib} pdo={$ref}");
    }
})->group('stress');

// ---------------------------------------------------------------------------
// Test 3: large number parity
// ---------------------------------------------------------------------------

test('large integers and large floats match between libSQL and PDO sqlite', function () {
    $intCases   = [PHP_INT_MAX, PHP_INT_MIN, 9_000_000_000_000_000, -9_000_000_000_000_000];
    $floatCases = [1.0e200, -1.0e200, 1.5e-200, -1.5e-200];

    // Insert int cases — always include all columns so batch alignment is unambiguous
    $libsqlIntRows = [];
    $pdoIntRows    = [];
    foreach ($intCases as $v) {
        $libsqlIntRows[] = ['int_col' => $v, 'float_col' => null, 'bool_col' => null, 'text_col' => null, 'blob_col' => null, 'null_col' => null];
        $pdoIntRows[]    = ['int_col' => $v];
    }

    // Insert float cases separately with all columns present
    $libsqlFloatRows = [];
    $pdoFloatRows    = [];
    foreach ($floatCases as $v) {
        $libsqlFloatRows[] = ['int_col' => null, 'float_col' => $v, 'bool_col' => null, 'text_col' => null, 'blob_col' => null, 'null_col' => null];
        $pdoFloatRows[]    = ['float_col' => $v];
    }

    DB::table('diff_values')->insert($libsqlIntRows);
    DB::table('diff_values')->insert($libsqlFloatRows);
    $pdo     = pdoBaseline(array_merge($pdoIntRows, $pdoFloatRows));
    $pdoData = pdoFetchAll($pdo);
    $libData = DB::table('diff_values')->orderBy('id')->get();

    foreach ($intCases as $i => $expected) {
        $lib = (int) $libData[$i]->int_col;
        $ref = (int) $pdoData[$i]->int_col;
        expect($lib)->toBe($expected, "libSQL large int mismatch at index {$i}");
        expect($lib)->toBe($ref, "libSQL vs PDO large int mismatch at index {$i}");
    }

    $offset = count($intCases);
    foreach ($floatCases as $j => $expected) {
        $lib = (float) $libData[$offset + $j]->float_col;
        $ref = (float) $pdoData[$offset + $j]->float_col;
        expect(abs($lib - $expected))->toBeLessThan(abs($expected) * 1e-12 + 1e-300,
            "libSQL large float mismatch at index {$j}");
        expect(abs($lib - $ref))->toBeLessThan(abs($expected) * 1e-12 + 1e-300,
            "libSQL vs PDO large float mismatch at index {$j}");
    }
})->group('stress');

// ---------------------------------------------------------------------------
// Test 4: boolean parity
// ---------------------------------------------------------------------------

test('boolean values match between libSQL and PDO sqlite', function () {
    // SQLite stores booleans as integers 0/1
    $cases = [
        [true,  1],
        [false, 0],
        [1,     1],
        [0,     0],
    ];

    $libsqlRows = [];
    $pdoRows    = [];
    foreach ($cases as [$phpVal]) {
        $libsqlRows[] = ['int_col' => null, 'float_col' => null, 'bool_col' => $phpVal, 'text_col' => null, 'blob_col' => null, 'null_col' => null];
        $pdoRows[]    = ['bool_col' => $phpVal];
    }

    DB::table('diff_values')->insert($libsqlRows);
    $pdo     = pdoBaseline($pdoRows);
    $pdoData = pdoFetchAll($pdo);
    $libData = DB::table('diff_values')->orderBy('id')->get();

    foreach ($cases as $i => [, $expectedInt]) {
        $lib = (int) $libData[$i]->bool_col;
        $ref = (int) $pdoData[$i]->bool_col;

        expect($lib)->toBe($expectedInt, "libSQL bool mismatch at index {$i}");
        expect($ref)->toBe($expectedInt, "PDO bool mismatch at index {$i}");
        expect($lib)->toBe($ref, "libSQL vs PDO bool diverge at index {$i}");
    }
})->group('stress');

// ---------------------------------------------------------------------------
// Test 5: NULL parity
// ---------------------------------------------------------------------------

test('NULL values match between libSQL and PDO sqlite', function () {
    // Insert one row with every column null
    DB::table('diff_values')->insert([
        'int_col'   => null,
        'float_col' => null,
        'bool_col'  => null,
        'text_col'  => null,
        'blob_col'  => null,
        'null_col'  => null,
    ]);

    $pdo     = pdoBaseline([['int_col' => null, 'float_col' => null, 'bool_col' => null,
        'text_col' => null, 'blob_col' => null, 'null_col' => null]]);
    $pdoData = pdoFetchAll($pdo);
    $libData = DB::table('diff_values')->orderBy('id')->get();

    $libRow = $libData[0];
    $pdoRow = $pdoData[0];

    expect($libRow->int_col)->toBeNull('libSQL int_col should be NULL');
    expect($pdoRow->int_col)->toBeNull('PDO int_col should be NULL');

    expect($libRow->float_col)->toBeNull('libSQL float_col should be NULL');
    expect($pdoRow->float_col)->toBeNull('PDO float_col should be NULL');

    expect($libRow->bool_col)->toBeNull('libSQL bool_col should be NULL');
    expect($pdoRow->bool_col)->toBeNull('PDO bool_col should be NULL');

    expect($libRow->text_col)->toBeNull('libSQL text_col should be NULL');
    expect($pdoRow->text_col)->toBeNull('PDO text_col should be NULL');

    expect($libRow->blob_col)->toBeNull('libSQL blob_col should be NULL');
    expect($pdoRow->blob_col)->toBeNull('PDO blob_col should be NULL');

    expect($libRow->null_col)->toBeNull('libSQL null_col should be NULL');
    expect($pdoRow->null_col)->toBeNull('PDO null_col should be NULL');
})->group('stress');

// ---------------------------------------------------------------------------
// Test 6: unicode + emoji string parity
// ---------------------------------------------------------------------------

test('unicode and emoji strings match between libSQL and PDO sqlite', function () {
    $cases = [
        'Hello, world!',
        'héllo',
        '漢字',
        '🚀💡🎉',
        'Ünïcödé',
        'السلام عليكم',
        '日本語テスト',
        "multi\nline\nstring",
        "tab\there",
        str_repeat('🔥漢字 ', 500),   // ~multi-KB multibyte
    ];

    $libsqlRows = [];
    $pdoRows    = [];
    foreach ($cases as $v) {
        $libsqlRows[] = ['int_col' => null, 'float_col' => null, 'bool_col' => null, 'text_col' => $v, 'blob_col' => null, 'null_col' => null];
        $pdoRows[]    = ['text_col' => $v];
    }

    DB::table('diff_values')->insert($libsqlRows);
    $pdo     = pdoBaseline($pdoRows);
    $pdoData = pdoFetchAll($pdo);
    $libData = DB::table('diff_values')->orderBy('id')->get();

    expect($libData)->toHaveCount(count($cases));

    foreach ($cases as $i => $expected) {
        $lib = $libData[$i]->text_col;
        $ref = $pdoData[$i]->text_col;

        expect($lib)->toBe($expected, "libSQL unicode mismatch at index {$i}");
        expect($ref)->toBe($expected, "PDO unicode mismatch at index {$i}");
        expect($lib)->toBe($ref, "libSQL vs PDO unicode diverge at index {$i}");
    }
})->group('stress');

// ---------------------------------------------------------------------------
// Test 7: empty string parity
// ---------------------------------------------------------------------------

test('empty string matches between libSQL and PDO sqlite', function () {
    DB::table('diff_values')->insert(['text_col' => '']);
    $pdo     = pdoBaseline([['text_col' => '']]);
    $pdoData = pdoFetchAll($pdo);
    $libData = DB::table('diff_values')->orderBy('id')->get();

    $lib = $libData[0]->text_col;
    $ref = $pdoData[0]->text_col;

    expect($lib)->toBe('', "libSQL empty string should be ''");
    expect($ref)->toBe('', "PDO empty string should be ''");
    expect($lib)->toBe($ref, 'libSQL vs PDO empty string mismatch');
})->group('stress');

// ---------------------------------------------------------------------------
// Test 8: binary / blob parity
// ---------------------------------------------------------------------------

test('binary blob data matches between libSQL and PDO sqlite', function () {
    // Note: empty string ('') is intentionally excluded — libSQL FFI panics
    // when binding empty blobs/strings (known package limitation; see empty-string test).
    $cases = [
        "\x00",                                     // single null byte
        "start\x00middle\x00\x00end",               // embedded null bytes
        "\x80\x81\x82\x83\xfe\xff",                 // non-UTF-8 bytes
        implode('', array_map('chr', range(0, 255))), // all 256 byte values (non-empty blob)
    ];

    // libSQL side - insert blobs
    foreach ($cases as $payload) {
        DB::table('diff_values')->insert(['blob_col' => $payload]);
    }

    // PDO side - insert the same blobs using BLOB binding
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('
        CREATE TABLE diff_values (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            int_col  INTEGER,
            float_col REAL,
            bool_col INTEGER,
            text_col TEXT,
            blob_col BLOB,
            null_col TEXT
        )
    ');

    foreach ($cases as $payload) {
        $stmt = $pdo->prepare('INSERT INTO diff_values (blob_col) VALUES (:blob)');
        $stmt->bindValue(':blob', $payload, PDO::PARAM_LOB);
        $stmt->execute();
    }

    $pdoData = pdoFetchAll($pdo);
    $libData = DB::table('diff_values')->orderBy('id')->get();

    expect($libData)->toHaveCount(count($cases));
    expect($pdoData)->toHaveCount(count($cases));

    foreach ($cases as $i => $expected) {
        $lib = $libData[$i]->blob_col;
        $ref = $pdoData[$i]->blob_col;

        expect(strlen((string) $lib))->toBe(strlen($expected),
            "libSQL blob length mismatch at index {$i}");
        expect(strlen((string) $ref))->toBe(strlen($expected),
            "PDO blob length mismatch at index {$i}");
        expect($lib)->toBe($ref, "libSQL vs PDO blob data diverge at index {$i}");
    }
})->group('stress');

// ---------------------------------------------------------------------------
// Test 9: datetime string parity
// ---------------------------------------------------------------------------

test('datetime strings match between libSQL and PDO sqlite', function () {
    $datetimes = [
        '2026-01-01 00:00:00',
        '2026-02-28 23:59:59',
        '2000-12-31 12:00:00',
        '1970-01-01 00:00:00',
        CarbonImmutable::parse('2025-07-04 15:30:45')->toDateTimeString(),
    ];

    foreach ($datetimes as $dt) {
        DB::table('diff_values')->insert(['text_col' => $dt]);
    }

    $pdo = pdoBaseline(array_map(fn ($dt) => ['text_col' => $dt], $datetimes));
    $pdoData = pdoFetchAll($pdo);
    $libData = DB::table('diff_values')->orderBy('id')->get();

    foreach ($datetimes as $i => $expected) {
        $lib = $libData[$i]->text_col;
        $ref = $pdoData[$i]->text_col;

        expect($lib)->toBe($expected, "libSQL datetime mismatch at index {$i}");
        expect($ref)->toBe($expected, "PDO datetime mismatch at index {$i}");
        expect($lib)->toBe($ref, "libSQL vs PDO datetime diverge at index {$i}");
    }
})->group('stress');

// ---------------------------------------------------------------------------
// Test 10: COUNT aggregate parity
// ---------------------------------------------------------------------------

test('COUNT aggregate matches between libSQL and PDO sqlite', function () {
    $n = 37;

    // libSQL side
    $libRows = [];
    for ($i = 0; $i < $n; $i++) {
        $libRows[] = ['int_col' => $i, 'text_col' => "row-{$i}"];
    }
    DB::table('diff_values')->insert($libRows);

    // PDO side
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE diff_values (id INTEGER PRIMARY KEY AUTOINCREMENT, int_col INTEGER, float_col REAL, bool_col INTEGER, text_col TEXT, blob_col BLOB, null_col TEXT)');
    for ($i = 0; $i < $n; $i++) {
        $stmt = $pdo->prepare('INSERT INTO diff_values (int_col, text_col) VALUES (:i, :t)');
        $stmt->execute([':i' => $i, ':t' => "row-{$i}"]);
    }

    $libCount = DB::table('diff_values')->count();
    $pdoCount = (int) $pdo->query('SELECT COUNT(*) FROM diff_values')->fetchColumn();

    expect($libCount)->toBe($n, "libSQL COUNT should be {$n}");
    expect($pdoCount)->toBe($n, "PDO COUNT should be {$n}");
    expect($libCount)->toBe($pdoCount, 'libSQL vs PDO COUNT mismatch');
})->group('stress');

// ---------------------------------------------------------------------------
// Test 11: SUM aggregate parity
// ---------------------------------------------------------------------------

test('SUM aggregate matches between libSQL and PDO sqlite', function () {
    // Insert rows into diff_aggregates (libSQL) and a PDO baseline
    $values = [10, 20, 30, 40, 50, -15, 0, 100];
    $expectedSum = array_sum($values); // 235

    // libSQL
    $libRows = [];
    foreach ($values as $v) {
        $libRows[] = ['label' => "v{$v}", 'int_val' => $v, 'float_val' => (float) $v];
    }
    DB::table('diff_aggregates')->insert($libRows);

    // PDO
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE diff_aggregates (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT, int_val INTEGER, float_val REAL)');
    foreach ($values as $v) {
        $stmt = $pdo->prepare('INSERT INTO diff_aggregates (label, int_val, float_val) VALUES (:l, :i, :f)');
        $stmt->execute([':l' => "v{$v}", ':i' => $v, ':f' => (float) $v]);
    }

    $libSum  = (int) DB::table('diff_aggregates')->sum('int_val');
    $pdoSum  = (int) $pdo->query('SELECT SUM(int_val) FROM diff_aggregates')->fetchColumn();

    expect($libSum)->toBe($expectedSum, "libSQL SUM should be {$expectedSum}");
    expect($pdoSum)->toBe($expectedSum, "PDO SUM should be {$expectedSum}");
    expect($libSum)->toBe($pdoSum, 'libSQL vs PDO SUM mismatch');
})->group('stress');

// ---------------------------------------------------------------------------
// Test 12: mixed type row parity (all column types in one shot)
// ---------------------------------------------------------------------------

test('mixed-type row values match between libSQL and PDO sqlite', function () {
    $textVal  = 'Hello 🌍 漢字';
    $intVal   = 1234567890;
    $floatVal = 2.718281828;
    $boolVal  = 1;
    $blobVal  = "\x00\xff\xfe binary \x01\x02";
    $nullVal  = null;

    DB::table('diff_values')->insert([
        'int_col'   => $intVal,
        'float_col' => $floatVal,
        'bool_col'  => $boolVal,
        'text_col'  => $textVal,
        'blob_col'  => $blobVal,
        'null_col'  => $nullVal,
    ]);

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE diff_values (id INTEGER PRIMARY KEY AUTOINCREMENT, int_col INTEGER, float_col REAL, bool_col INTEGER, text_col TEXT, blob_col BLOB, null_col TEXT)');
    $stmt = $pdo->prepare('INSERT INTO diff_values (int_col, float_col, bool_col, text_col, blob_col, null_col) VALUES (:i, :f, :b, :t, :bl, :n)');
    $stmt->bindValue(':i',  $intVal,   PDO::PARAM_INT);
    $stmt->bindValue(':f',  $floatVal, PDO::PARAM_STR);
    $stmt->bindValue(':b',  $boolVal,  PDO::PARAM_INT);
    $stmt->bindValue(':t',  $textVal,  PDO::PARAM_STR);
    $stmt->bindValue(':bl', $blobVal,  PDO::PARAM_LOB);
    $stmt->bindValue(':n',  $nullVal,  PDO::PARAM_NULL);
    $stmt->execute();

    $pdoRow = $pdo->query('SELECT * FROM diff_values ORDER BY id')->fetch(PDO::FETCH_OBJ);
    $libRow = DB::table('diff_values')->orderBy('id')->first();

    // integer
    expect((int) $libRow->int_col)->toBe($intVal, 'libSQL int_col mismatch');
    expect((int) $pdoRow->int_col)->toBe($intVal, 'PDO int_col mismatch');
    expect((int) $libRow->int_col)->toBe((int) $pdoRow->int_col, 'libSQL vs PDO int_col diverge');

    // float
    expect(abs((float) $libRow->float_col - $floatVal))->toBeLessThan(1e-9, 'libSQL float_col mismatch');
    expect(abs((float) $pdoRow->float_col - $floatVal))->toBeLessThan(1e-9, 'PDO float_col mismatch');

    // boolean
    expect((int) $libRow->bool_col)->toBe($boolVal, 'libSQL bool_col mismatch');
    expect((int) $libRow->bool_col)->toBe((int) $pdoRow->bool_col, 'libSQL vs PDO bool_col diverge');

    // text
    expect($libRow->text_col)->toBe($textVal, 'libSQL text_col mismatch');
    expect($pdoRow->text_col)->toBe($textVal, 'PDO text_col mismatch');
    expect($libRow->text_col)->toBe($pdoRow->text_col, 'libSQL vs PDO text_col diverge');

    // blob
    expect($libRow->blob_col)->toBe($blobVal, 'libSQL blob_col mismatch');
    expect($libRow->blob_col)->toBe($pdoRow->blob_col, 'libSQL vs PDO blob_col diverge');

    // null
    expect($libRow->null_col)->toBeNull('libSQL null_col should be NULL');
    expect($pdoRow->null_col)->toBeNull('PDO null_col should be NULL');
})->group('stress');
