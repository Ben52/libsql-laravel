<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relationship-level correctness checks using the query builder only.
 * Covers one-to-many, belongs-to, many-to-many (pivot), has-many-through,
 * eager-ish joins, and aggregate counts.
 *
 * Table prefix: rel_
 */
beforeEach(function () {
    // Drop in reverse dependency order so FK constraints (if enforced) don't block.
    Schema::dropIfExists('rel_tag_task');
    Schema::dropIfExists('rel_tasks');
    Schema::dropIfExists('rel_tags');
    Schema::dropIfExists('rel_comments');
    Schema::dropIfExists('rel_posts');
    Schema::dropIfExists('rel_authors');

    // authors  (root)
    Schema::create('rel_authors', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('country')->default('US');
        $t->timestamps();
    });

    // posts  (belongs-to author)
    Schema::create('rel_posts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('author_id');
        $t->string('title');
        $t->text('body')->nullable();
        $t->timestamps();
    });

    // comments  (belongs-to post; has-many-through author -> post -> comment)
    Schema::create('rel_comments', function (Blueprint $t) {
        $t->id();
        $t->foreignId('post_id');
        $t->string('content');
        $t->timestamps();
    });

    // tags  (standalone, joined via pivot to tasks)
    Schema::create('rel_tags', function (Blueprint $t) {
        $t->id();
        $t->string('label')->unique();
        $t->timestamps();
    });

    // tasks  (standalone, joined via pivot to tags)
    Schema::create('rel_tasks', function (Blueprint $t) {
        $t->id();
        $t->string('title');
        $t->boolean('done')->default(false);
        $t->timestamps();
    });

    // pivot table for many-to-many  (task <-> tag)
    Schema::create('rel_tag_task', function (Blueprint $t) {
        $t->id();
        $t->foreignId('task_id');
        $t->foreignId('tag_id');
        $t->timestamps();
    });
});

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function relAuthor(string $name, string $country = 'US'): int
{
    $now = CarbonImmutable::now();

    return DB::table('rel_authors')->insertGetId([
        'name' => $name,
        'country' => $country,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function relPost(int $authorId, string $title, ?string $body = null): int
{
    $now = CarbonImmutable::now();

    return DB::table('rel_posts')->insertGetId([
        'author_id' => $authorId,
        'title' => $title,
        'body' => $body,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function relComment(int $postId, string $content): int
{
    $now = CarbonImmutable::now();

    return DB::table('rel_comments')->insertGetId([
        'post_id' => $postId,
        'content' => $content,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function relTag(string $label): int
{
    $now = CarbonImmutable::now();

    return DB::table('rel_tags')->insertGetId([
        'label' => $label,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function relTask(string $title, bool $done = false): int
{
    $now = CarbonImmutable::now();

    return DB::table('rel_tasks')->insertGetId([
        'title' => $title,
        'done' => $done,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function relAttach(int $taskId, int $tagId): void
{
    $now = CarbonImmutable::now();
    DB::table('rel_tag_task')->insert([
        'task_id' => $taskId,
        'tag_id' => $tagId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// ---------------------------------------------------------------------------
// one-to-many: author has many posts
// ---------------------------------------------------------------------------

test('one-to-many: foreign key column persists and rows are retrievable', function () {
    $authorId = relAuthor('Alice');
    $postId1 = relPost($authorId, 'First Post');
    $postId2 = relPost($authorId, 'Second Post');

    $posts = DB::table('rel_posts')->where('author_id', $authorId)->orderBy('id')->get();

    expect($posts)->toHaveCount(2);
    expect((int) $posts[0]->author_id)->toBe($authorId);
    expect((int) $posts[1]->author_id)->toBe($authorId);
    expect($posts[0]->title)->toBe('First Post');
    expect($posts[1]->title)->toBe('Second Post');
    expect((int) $posts[0]->id)->toBe($postId1);
    expect((int) $posts[1]->id)->toBe($postId2);
})->group('stress');

test('one-to-many: count of child rows per parent is correct', function () {
    $alice = relAuthor('Alice');
    $bob = relAuthor('Bob');

    relPost($alice, 'A1');
    relPost($alice, 'A2');
    relPost($alice, 'A3');
    relPost($bob, 'B1');

    $aliceCount = DB::table('rel_posts')->where('author_id', $alice)->count();
    $bobCount = DB::table('rel_posts')->where('author_id', $bob)->count();

    expect($aliceCount)->toBe(3);
    expect($bobCount)->toBe(1);
})->group('stress');

test('one-to-many: author with no posts returns empty result set', function () {
    $authorId = relAuthor('Loner');

    $posts = DB::table('rel_posts')->where('author_id', $authorId)->get();

    expect($posts)->toHaveCount(0);
})->group('stress');

// ---------------------------------------------------------------------------
// belongs-to: post resolves its author
// ---------------------------------------------------------------------------

test('belongs-to: post author_id resolves to the correct author row', function () {
    $authorId = relAuthor('Carol', 'CA');
    $postId = relPost($authorId, 'Carol Post');

    $post = DB::table('rel_posts')->where('id', $postId)->first();
    $author = DB::table('rel_authors')->where('id', $post->author_id)->first();

    expect($author)->not->toBeNull();
    expect($author->name)->toBe('Carol');
    expect($author->country)->toBe('CA');
})->group('stress');

test('belongs-to: deleting author leaves orphan post with stale fk (no cascade)', function () {
    $authorId = relAuthor('Temp');
    relPost($authorId, 'Orphan Post');

    DB::table('rel_authors')->where('id', $authorId)->delete();

    // The post row still exists; its author_id column still holds the old value.
    $orphans = DB::table('rel_posts')->where('author_id', $authorId)->get();
    expect($orphans)->toHaveCount(1);
    expect($orphans[0]->title)->toBe('Orphan Post');
})->group('stress');

// ---------------------------------------------------------------------------
// many-to-many: task <-> tag via rel_tag_task pivot
// ---------------------------------------------------------------------------

test('many-to-many: pivot inserts correctly and both sides are queryable', function () {
    $taskId = relTask('Write tests');
    $tagId1 = relTag('php');
    $tagId2 = relTag('testing');

    relAttach($taskId, $tagId1);
    relAttach($taskId, $tagId2);

    // from task side: how many tags?
    $tagCount = DB::table('rel_tag_task')->where('task_id', $taskId)->count();
    expect($tagCount)->toBe(2);

    // from tag side: how many tasks carry the 'php' tag?
    $taskCount = DB::table('rel_tag_task')->where('tag_id', $tagId1)->count();
    expect($taskCount)->toBe(1);
})->group('stress');

test('many-to-many: join through pivot returns correct tag labels for a task', function () {
    $taskId = relTask('Deploy app');
    $tagId1 = relTag('devops');
    $tagId2 = relTag('urgent');
    $tagId3 = relTag('backend');

    relAttach($taskId, $tagId1);
    relAttach($taskId, $tagId3); // skip 'urgent'

    $labels = DB::table('rel_tag_task')
        ->join('rel_tags', 'rel_tags.id', '=', 'rel_tag_task.tag_id')
        ->where('rel_tag_task.task_id', $taskId)
        ->orderBy('rel_tags.label')
        ->pluck('rel_tags.label')
        ->all();

    expect($labels)->toBe(['backend', 'devops']);
})->group('stress');

test('many-to-many: detach via query builder removes only the correct pivot row', function () {
    $taskId = relTask('Clean up');
    $tagId1 = relTag('cleanup');
    $tagId2 = relTag('low-priority');

    relAttach($taskId, $tagId1);
    relAttach($taskId, $tagId2);

    // detach 'cleanup' tag
    $deleted = DB::table('rel_tag_task')
        ->where('task_id', $taskId)
        ->where('tag_id', $tagId1)
        ->delete();

    expect($deleted)->toBe(1);

    $remaining = DB::table('rel_tag_task')->where('task_id', $taskId)->count();
    expect($remaining)->toBe(1);

    $remainingTag = DB::table('rel_tag_task')
        ->where('task_id', $taskId)
        ->value('tag_id');
    expect((int) $remainingTag)->toBe($tagId2);
})->group('stress');

test('many-to-many: a tag attached to multiple tasks is counted correctly', function () {
    $tagId = relTag('shared');
    $taskId1 = relTask('Task A');
    $taskId2 = relTask('Task B');
    $taskId3 = relTask('Task C');

    relAttach($taskId1, $tagId);
    relAttach($taskId2, $tagId);
    relAttach($taskId3, $tagId);

    $count = DB::table('rel_tag_task')->where('tag_id', $tagId)->count();
    expect($count)->toBe(3);

    // Verify task titles come back via join
    $titles = DB::table('rel_tag_task')
        ->join('rel_tasks', 'rel_tasks.id', '=', 'rel_tag_task.task_id')
        ->where('rel_tag_task.tag_id', $tagId)
        ->orderBy('rel_tasks.title')
        ->pluck('rel_tasks.title')
        ->all();

    expect($titles)->toBe(['Task A', 'Task B', 'Task C']);
})->group('stress');

// ---------------------------------------------------------------------------
// has-many-through: author -> posts -> comments
// ---------------------------------------------------------------------------

test('has-many-through: comments reachable from author via two-hop join', function () {
    $authorId = relAuthor('Dave');
    $postId1 = relPost($authorId, 'Post 1');
    $postId2 = relPost($authorId, 'Post 2');

    relComment($postId1, 'Great post!');
    relComment($postId1, 'Thanks!');
    relComment($postId2, 'Interesting');

    $commentCount = DB::table('rel_comments')
        ->join('rel_posts', 'rel_posts.id', '=', 'rel_comments.post_id')
        ->where('rel_posts.author_id', $authorId)
        ->count();

    expect($commentCount)->toBe(3);
})->group('stress');

test('has-many-through: comment content is correct after two-hop join', function () {
    $authorId = relAuthor('Eve');
    $postId = relPost($authorId, 'Single Post');
    relComment($postId, 'Alpha');
    relComment($postId, 'Beta');

    $contents = DB::table('rel_comments')
        ->join('rel_posts', 'rel_posts.id', '=', 'rel_comments.post_id')
        ->where('rel_posts.author_id', $authorId)
        ->orderBy('rel_comments.id')
        ->pluck('rel_comments.content')
        ->all();

    expect($contents)->toBe(['Alpha', 'Beta']);
})->group('stress');

test('has-many-through: two authors do not bleed each others comments', function () {
    $alice = relAuthor('Alice2');
    $bob = relAuthor('Bob2');

    $alicePost = relPost($alice, 'Alice Post');
    $bobPost = relPost($bob, 'Bob Post');

    relComment($alicePost, 'Alice comment 1');
    relComment($alicePost, 'Alice comment 2');
    relComment($bobPost, 'Bob comment 1');

    $aliceComments = DB::table('rel_comments')
        ->join('rel_posts', 'rel_posts.id', '=', 'rel_comments.post_id')
        ->where('rel_posts.author_id', $alice)
        ->count();

    $bobComments = DB::table('rel_comments')
        ->join('rel_posts', 'rel_posts.id', '=', 'rel_comments.post_id')
        ->where('rel_posts.author_id', $bob)
        ->count();

    expect($aliceComments)->toBe(2);
    expect($bobComments)->toBe(1);
})->group('stress');

// ---------------------------------------------------------------------------
// eager-ish joins: select columns from multiple tables in a single query
// ---------------------------------------------------------------------------

test('join: post row includes author name via inner join', function () {
    $authorId = relAuthor('Frank');
    relPost($authorId, 'Joined Post', 'body text');

    $row = DB::table('rel_posts')
        ->join('rel_authors', 'rel_authors.id', '=', 'rel_posts.author_id')
        ->select('rel_posts.title', 'rel_authors.name as author_name', 'rel_posts.body')
        ->first();

    expect($row->title)->toBe('Joined Post');
    expect($row->author_name)->toBe('Frank');
    expect($row->body)->toBe('body text');
})->group('stress');

test('join: left join returns null author_name for posts with no matching author', function () {
    // Insert a post whose author does not exist.
    $now = CarbonImmutable::now();
    DB::table('rel_posts')->insert([
        'author_id' => 99999,
        'title' => 'Dangling Post',
        'body' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $row = DB::table('rel_posts')
        ->leftJoin('rel_authors', 'rel_authors.id', '=', 'rel_posts.author_id')
        ->where('rel_posts.title', 'Dangling Post')
        ->select('rel_posts.title', 'rel_authors.name as author_name')
        ->first();

    expect($row->title)->toBe('Dangling Post');
    expect($row->author_name)->toBeNull();
})->group('stress');

test('join: three-table join produces correct aggregate count', function () {
    $authorId = relAuthor('Grace');
    $postId1 = relPost($authorId, 'P1');
    $postId2 = relPost($authorId, 'P2');
    relComment($postId1, 'C1');
    relComment($postId1, 'C2');
    relComment($postId1, 'C3');
    relComment($postId2, 'C4');

    $total = DB::table('rel_authors')
        ->join('rel_posts', 'rel_posts.author_id', '=', 'rel_authors.id')
        ->join('rel_comments', 'rel_comments.post_id', '=', 'rel_posts.id')
        ->where('rel_authors.id', $authorId)
        ->count();

    expect($total)->toBe(4);
})->group('stress');

// ---------------------------------------------------------------------------
// aggregate counts on relationship data
// ---------------------------------------------------------------------------

test('counts: withCount equivalent via subquery returns correct per-author post count', function () {
    $alice = relAuthor('AliceCnt');
    $bob = relAuthor('BobCnt');

    relPost($alice, 'a1');
    relPost($alice, 'a2');
    relPost($bob, 'b1');

    $rows = DB::table('rel_authors')
        ->whereIn('id', [$alice, $bob])
        ->orderBy('name')
        ->get(['id', 'name']);

    $counts = [];
    foreach ($rows as $row) {
        $counts[$row->name] = DB::table('rel_posts')
            ->where('author_id', $row->id)
            ->count();
    }

    expect($counts['AliceCnt'])->toBe(2);
    expect($counts['BobCnt'])->toBe(1);
})->group('stress');

test('counts: groupBy with count gives post totals per author in a single query', function () {
    $alice = relAuthor('AliceGrp');
    $bob = relAuthor('BobGrp');

    relPost($alice, 'ag1');
    relPost($alice, 'ag2');
    relPost($alice, 'ag3');
    relPost($bob, 'bg1');
    relPost($bob, 'bg2');

    $results = DB::table('rel_posts')
        ->whereIn('author_id', [$alice, $bob])
        ->selectRaw('author_id, COUNT(*) as post_count')
        ->groupBy('author_id')
        ->orderBy('author_id')
        ->get();

    expect($results)->toHaveCount(2);

    $byAuthor = $results->keyBy('author_id');
    expect((int) $byAuthor[$alice]->post_count)->toBe(3);
    expect((int) $byAuthor[$bob]->post_count)->toBe(2);
})->group('stress');

test('counts: pivot row count reflects attach/detach operations', function () {
    $taskId = relTask('Count task');
    $tag1 = relTag('ct1');
    $tag2 = relTag('ct2');
    $tag3 = relTag('ct3');

    relAttach($taskId, $tag1);
    relAttach($taskId, $tag2);
    relAttach($taskId, $tag3);

    $before = DB::table('rel_tag_task')->where('task_id', $taskId)->count();
    expect($before)->toBe(3);

    // detach one
    DB::table('rel_tag_task')
        ->where('task_id', $taskId)
        ->where('tag_id', $tag2)
        ->delete();

    $after = DB::table('rel_tag_task')->where('task_id', $taskId)->count();
    expect($after)->toBe(2);
})->group('stress');
