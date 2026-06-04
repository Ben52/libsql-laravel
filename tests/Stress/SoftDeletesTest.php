<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stress tests for Eloquent soft-deletes (SoftDeletes trait).
 * All tables are prefixed with "sd_" to avoid collisions with other test files.
 */

// ---------------------------------------------------------------------------
// Inline model – defined once, reused across all tests in this file.
// ---------------------------------------------------------------------------

/**
 * @property int              $id
 * @property string           $name
 * @property string|null      $deleted_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
class SdWidget extends Model
{
    use SoftDeletes;

    protected $table = 'sd_widgets';

    protected $fillable = ['name'];

    /** Use CarbonImmutable for all date casts. */
    protected $casts = [
        'deleted_at' => 'datetime',
    ];
}

// ---------------------------------------------------------------------------
// Schema setup – idempotent so re-runs on persistent backends are safe.
// ---------------------------------------------------------------------------

beforeEach(function () {
    Schema::dropIfExists('sd_widgets');

    Schema::create('sd_widgets', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->softDeletes();   // adds nullable deleted_at TIMESTAMP
        $t->timestamps();
    });
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('soft delete sets deleted_at and hides the row from default queries', function () {
    $w = SdWidget::create(['name' => 'alpha']);

    expect(SdWidget::count())->toBe(1);

    $w->delete();

    // The row is hidden from the default scope …
    expect(SdWidget::count())->toBe(0);
    expect(SdWidget::find($w->id))->toBeNull();

    // … but deleted_at is a concrete timestamp, not null
    $raw = SdWidget::withTrashed()->find($w->id);
    expect($raw)->not->toBeNull();
    expect($raw->deleted_at)->not->toBeNull();
})->group('stress');

test('withTrashed includes soft-deleted rows alongside live rows', function () {
    SdWidget::create(['name' => 'live']);
    $deleted = SdWidget::create(['name' => 'gone']);
    $deleted->delete();

    expect(SdWidget::count())->toBe(1);
    expect(SdWidget::withTrashed()->count())->toBe(2);

    $names = SdWidget::withTrashed()->orderBy('id')->pluck('name')->all();
    expect($names)->toBe(['live', 'gone']);
})->group('stress');

test('onlyTrashed returns exclusively soft-deleted rows', function () {
    SdWidget::create(['name' => 'stay']);
    $d1 = SdWidget::create(['name' => 'del1']);
    $d2 = SdWidget::create(['name' => 'del2']);
    $d1->delete();
    $d2->delete();

    expect(SdWidget::count())->toBe(1);
    expect(SdWidget::onlyTrashed()->count())->toBe(2);

    $names = SdWidget::onlyTrashed()->orderBy('id')->pluck('name')->all();
    expect($names)->toBe(['del1', 'del2']);
})->group('stress');

test('restore clears deleted_at and makes the row visible again', function () {
    $w = SdWidget::create(['name' => 'revive']);
    $w->delete();

    expect(SdWidget::count())->toBe(0);

    SdWidget::withTrashed()->find($w->id)->restore();

    expect(SdWidget::count())->toBe(1);
    $restored = SdWidget::find($w->id);
    expect($restored)->not->toBeNull();
    expect($restored->deleted_at)->toBeNull();
    expect($restored->name)->toBe('revive');
})->group('stress');

test('forceDelete removes the row permanently', function () {
    $w = SdWidget::create(['name' => 'permanent']);
    $w->delete();

    // Confirm it exists as a soft-deleted row first
    expect(SdWidget::withTrashed()->count())->toBe(1);

    SdWidget::withTrashed()->find($w->id)->forceDelete();

    expect(SdWidget::withTrashed()->count())->toBe(0);
    expect(SdWidget::onlyTrashed()->count())->toBe(0);
})->group('stress');

test('deleted_at is a CarbonImmutable-compatible datetime after soft delete', function () {
    $before = CarbonImmutable::now()->subSecond();

    $w = SdWidget::create(['name' => 'ts_check']);
    $w->delete();

    $after = CarbonImmutable::now()->addSecond();

    $row = SdWidget::withTrashed()->find($w->id);
    // Cast to CarbonImmutable for assertion
    $deletedAt = CarbonImmutable::parse($row->deleted_at);

    expect($deletedAt->greaterThanOrEqualTo($before))->toBeTrue();
    expect($deletedAt->lessThanOrEqualTo($after))->toBeTrue();
})->group('stress');

test('multiple soft-deletes and a selective restore leave counts correct', function () {
    $a = SdWidget::create(['name' => 'a']);
    $b = SdWidget::create(['name' => 'b']);
    $c = SdWidget::create(['name' => 'c']);

    $a->delete();
    $b->delete();
    $c->delete();

    expect(SdWidget::count())->toBe(0);
    expect(SdWidget::onlyTrashed()->count())->toBe(3);

    // Restore only "b"
    SdWidget::withTrashed()->where('name', 'b')->first()->restore();

    expect(SdWidget::count())->toBe(1);
    expect(SdWidget::onlyTrashed()->count())->toBe(2);
    expect(SdWidget::first()->name)->toBe('b');
})->group('stress');

test('forceDelete on a never-soft-deleted model removes it directly', function () {
    $w = SdWidget::create(['name' => 'direct_force']);

    // Has not been soft-deleted; call forceDelete directly
    $w->forceDelete();

    expect(SdWidget::withTrashed()->find($w->id))->toBeNull();
    expect(SdWidget::withTrashed()->count())->toBe(0);
})->group('stress');

test('query scopes on non-deleted rows still work after some rows are soft-deleted', function () {
    SdWidget::create(['name' => 'keep1']);
    SdWidget::create(['name' => 'keep2']);
    $gone = SdWidget::create(['name' => 'gone']);
    $gone->delete();

    // Standard where still excludes trashed rows
    expect(SdWidget::where('name', 'like', 'keep%')->count())->toBe(2);
    expect(SdWidget::where('name', 'gone')->count())->toBe(0);

    // withTrashed + where finds it
    expect(SdWidget::withTrashed()->where('name', 'gone')->count())->toBe(1);
})->group('stress');
