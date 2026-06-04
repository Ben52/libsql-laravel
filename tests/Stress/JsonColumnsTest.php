<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * JSON column storage and retrieval tests.
 *
 * Table prefix: json_
 *
 * Covers:
 *   - Storing and retrieving arrays and objects round-tripped through a json column
 *   - Nested structures preserved exactly after UPDATE
 *   - whereJsonContains / json_extract where-clauses on json paths
 *   - NULL json values
 *   - Deeply nested structures
 */
beforeEach(function () {
    Schema::dropIfExists('json_documents');

    Schema::create('json_documents', function (Blueprint $t) {
        $t->id();
        $t->string('label');
        $t->json('payload')->nullable();
        $t->json('tags')->nullable();
        $t->timestamps();
    });
});

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function jsonRow(string $label, mixed $payload, mixed $tags = null): array
{
    return [
        'label'      => $label,
        'payload'    => is_null($payload) ? null : json_encode($payload),
        'tags'       => is_null($tags) ? null : json_encode($tags),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ];
}

/**
 * Normalise the payload column value to a PHP array/scalar.
 * The libsql driver may return JSON columns already decoded (as an array/object)
 * or still as a JSON string, depending on the backend. Handle both cases.
 */
function decodeJsonColumn(mixed $value): mixed
{
    if (is_null($value)) {
        return null;
    }
    if (is_string($value)) {
        return json_decode($value, true);
    }

    // Already decoded by the driver (e.g. stdClass or array)
    return json_decode(json_encode($value), true);
}

function fetchPayload(string $label): mixed
{
    $row = DB::table('json_documents')->where('label', $label)->first();

    return decodeJsonColumn($row->payload);
}

function fetchTags(string $label): mixed
{
    $row = DB::table('json_documents')->where('label', $label)->first();

    return decodeJsonColumn($row->tags);
}

// ---------------------------------------------------------------------------
// tests
// ---------------------------------------------------------------------------

test('stores and retrieves a flat json object', function () {
    DB::table('json_documents')->insert(jsonRow('flat', ['name' => 'Alice', 'age' => 30]));

    $decoded = fetchPayload('flat');

    expect($decoded)->toBe(['name' => 'Alice', 'age' => 30]);
})->group('stress');

test('stores and retrieves a json array', function () {
    DB::table('json_documents')->insert(jsonRow('arr', [1, 2, 3, 4, 5]));

    $decoded = fetchPayload('arr');

    expect($decoded)->toBe([1, 2, 3, 4, 5]);
})->group('stress');

test('stores and retrieves a nested json object', function () {
    $nested = [
        'user' => [
            'id'      => 42,
            'profile' => ['bio' => 'developer', 'active' => true],
        ],
        'scores' => [10, 20, 30],
    ];

    DB::table('json_documents')->insert(jsonRow('nested', $nested));

    $decoded = fetchPayload('nested');

    expect($decoded)->toBe($nested);
    expect($decoded['user']['profile']['bio'])->toBe('developer');
    expect($decoded['scores'][1])->toBe(20);
})->group('stress');

test('round-trips a deeply nested structure', function () {
    $deep = ['a' => ['b' => ['c' => ['d' => ['e' => 'leaf']]]]];

    DB::table('json_documents')->insert(jsonRow('deep', $deep));

    $decoded = fetchPayload('deep');

    expect($decoded)->toBe($deep);
    expect($decoded['a']['b']['c']['d']['e'])->toBe('leaf');
})->group('stress');

test('stores null json column and retrieves null', function () {
    DB::table('json_documents')->insert(jsonRow('nullpayload', null));

    $row = DB::table('json_documents')->where('label', 'nullpayload')->first();

    expect($row->payload)->toBeNull();
})->group('stress');

test('updates a json column and reads back new value', function () {
    DB::table('json_documents')->insert(jsonRow('updateme', ['version' => 1]));

    DB::table('json_documents')
        ->where('label', 'updateme')
        ->update(['payload' => json_encode(['version' => 2, 'updated' => true])]);

    $decoded = fetchPayload('updateme');

    expect($decoded['version'])->toBe(2);
    expect($decoded['updated'])->toBeTrue();
})->group('stress');

test('replaces nested json value via update', function () {
    $original = ['config' => ['theme' => 'light', 'lang' => 'en']];
    $updated  = ['config' => ['theme' => 'dark',  'lang' => 'fr']];

    DB::table('json_documents')->insert(jsonRow('theme', $original));

    DB::table('json_documents')
        ->where('label', 'theme')
        ->update(['payload' => json_encode($updated)]);

    $decoded = fetchPayload('theme');

    expect($decoded['config']['theme'])->toBe('dark');
    expect($decoded['config']['lang'])->toBe('fr');
})->group('stress');

test('multiple rows each carry independent json payloads', function () {
    DB::table('json_documents')->insert([
        jsonRow('r1', ['x' => 1]),
        jsonRow('r2', ['x' => 2]),
        jsonRow('r3', ['x' => 3]),
    ]);

    $rows = DB::table('json_documents')->orderBy('label')->get();

    expect($rows)->toHaveCount(3);
    expect(json_decode($rows[0]->payload, true)['x'])->toBe(1);
    expect(json_decode($rows[1]->payload, true)['x'])->toBe(2);
    expect(json_decode($rows[2]->payload, true)['x'])->toBe(3);
})->group('stress');

test('stores and retrieves a json array of objects', function () {
    $items = [
        ['id' => 1, 'name' => 'alpha'],
        ['id' => 2, 'name' => 'beta'],
        ['id' => 3, 'name' => 'gamma'],
    ];

    DB::table('json_documents')->insert(jsonRow('arrayofobjs', $items));

    $decoded = fetchPayload('arrayofobjs');

    expect($decoded)->toHaveCount(3);
    expect($decoded[0])->toBe(['id' => 1, 'name' => 'alpha']);
    expect($decoded[2]['name'])->toBe('gamma');
})->group('stress');

test('preserves boolean values inside json', function () {
    $data = ['enabled' => true, 'visible' => false, 'count' => 0];

    DB::table('json_documents')->insert(jsonRow('bools', $data));

    $decoded = fetchPayload('bools');

    expect($decoded['enabled'])->toBeTrue();
    expect($decoded['visible'])->toBeFalse();
    expect($decoded['count'])->toBe(0);
})->group('stress');

test('preserves float values inside json', function () {
    $data = ['pi' => 3.14159, 'ratio' => 0.5, 'large' => 1234567.89];

    DB::table('json_documents')->insert(jsonRow('floats', $data));

    $decoded = fetchPayload('floats');

    expect($decoded['pi'])->toBe(3.14159);
    expect($decoded['ratio'])->toBe(0.5);
    expect($decoded['large'])->toBe(1234567.89);
})->group('stress');

test('preserves unicode strings inside json', function () {
    $data = ['greeting' => 'こんにちは', 'emoji' => '🚀🎉', 'arabic' => 'مرحبا'];

    DB::table('json_documents')->insert(jsonRow('unicode', $data));

    $decoded = fetchPayload('unicode');

    expect($decoded['greeting'])->toBe('こんにちは');
    expect($decoded['emoji'])->toBe('🚀🎉');
    expect($decoded['arabic'])->toBe('مرحبا');
})->group('stress');

test('json_extract where-clause filters rows by nested key', function () {
    DB::table('json_documents')->insert([
        jsonRow('jx_a', ['status' => 'active',   'score' => 10]),
        jsonRow('jx_b', ['status' => 'inactive', 'score' => 20]),
        jsonRow('jx_c', ['status' => 'active',   'score' => 30]),
    ]);

    // Use raw json_extract — SQLite / libSQL supports this natively
    $rows = DB::table('json_documents')
        ->whereRaw("json_extract(payload, '$.status') = ?", ['active'])
        ->orderBy('label')
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows[0]->label)->toBe('jx_a');
    expect($rows[1]->label)->toBe('jx_c');
})->group('stress');

test('json_extract returns numeric value from nested path', function () {
    DB::table('json_documents')->insert(
        jsonRow('jnum', ['meta' => ['level' => 7, 'rank' => 'gold']])
    );

    $row = DB::table('json_documents')
        ->select(DB::raw("json_extract(payload, '$.meta.level') as level"))
        ->where('label', 'jnum')
        ->first();

    expect((int) $row->level)->toBe(7);
})->group('stress');

test('stores tags as json array and reads back exact values', function () {
    DB::table('json_documents')->insert(
        jsonRow('tagged', ['title' => 'post'], ['php', 'laravel', 'libsql'])
    );

    $row = DB::table('json_documents')->where('label', 'tagged')->first();

    $payload = json_decode($row->payload, true);
    $tags    = json_decode($row->tags, true);

    expect($payload['title'])->toBe('post');
    expect($tags)->toBe(['php', 'laravel', 'libsql']);
    expect($tags[1])->toBe('laravel');
})->group('stress');

test('updating only one column leaves json column untouched', function () {
    DB::table('json_documents')->insert(jsonRow('partial', ['data' => 'original']));

    DB::table('json_documents')
        ->where('label', 'partial')
        ->update(['label' => 'partial_renamed']);

    $decoded = fetchPayload('partial_renamed');

    expect($decoded['data'])->toBe('original');
})->group('stress');
