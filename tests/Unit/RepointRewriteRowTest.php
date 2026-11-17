<?php

/**
 * UAMS_MRA_Repoint::rewrite_row(): the status and applied code each row ends with, and what it writes.
 *
 * WordPress and the database are stand-ins that record what the row asks of them, so every
 * branch of the method (revision, unchanged, unreadable, failed write, applied) is read
 * from its result and from the exact arguments of the writes. The Integration suite runs the
 * same method against a real database; these tests pin the branches without one.
 *
 * @see includes/class-repoint.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

use function Brain\Monkey\Functions\when;

mutates(UAMS_MRA_Repoint::class);

const REWRITE_OLD = 'https://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf';
const REWRITE_NEW = 'https://new.example.edu/r.pdf';

if (! class_exists('UamsMraRecordingDb')) {
    /**
     * The parts of wpdb that a meta row uses, recording the writes.
     */
    final class UamsMraRecordingDb
    {
        public string $postmeta = 'wp_postmeta';

        public string $termmeta = 'wp_termmeta';

        public string $usermeta = 'wp_usermeta';

        public string $commentmeta = 'wp_commentmeta';

        public mixed $stored = null;

        public mixed $updateResult = 1;

        /** @var list<array{string, array<string, mixed>, array<string, mixed>}> */
        public array $updates = [];

        /** @var list<string> */
        public array $queries = [];

        public function prepare(string $query, mixed ...$args): string
        {
            /** @var list<scalar> $values */
            $values = $args;
            $this->queries[] = $query.'|'.implode(',', $values);

            return $query;
        }

        public function get_var(): mixed
        {
            return $this->stored;
        }

        /**
         * @param  array<string, mixed>  $data
         * @param  array<string, mixed>  $where
         */
        public function update(string $table, array $data, array $where): mixed
        {
            $this->updates[] = [$table, $data, $where];

            return $this->updateResult;
        }
    }
}

/**
 * Runs the protected method on a row.
 *
 * @param  array<string, mixed>  $fields
 * @return array{status: string, applied: int}
 */
function rewrite_row_result(array $fields): array
{
    $row = (object) array_merge([
        'url' => REWRITE_OLD,
        'target_url' => REWRITE_NEW,
        'source_type' => 'content',
        'source_pk' => 7,
        'source_key' => 'a_key',
        'source_id' => 55,
    ], $fields);

    $method = new ReflectionMethod(UAMS_MRA_Repoint::class, 'rewrite_row');

    /** @var array{status: string, applied: int} $result */
    $result = $method->invoke(null, $row);

    return $result;
}

/**
 * The recording stand-in the test put in place of `$wpdb`.
 */
function rewrite_db(): UamsMraRecordingDb
{
    /** @var UamsMraRecordingDb $db */
    $db = $GLOBALS['wpdb'];

    return $db;
}

/**
 * A stored object of a class that does not exist, holding the URL where a rewrite cannot reach it.
 */
function rewrite_unreachable(): string
{
    return 'O:8:"NotAClass":1:{s:3:"url";s:'.strlen(REWRITE_OLD).':"'.REWRITE_OLD.'";}';
}

beforeEach(function (): void {
    $GLOBALS['wpdb'] = new UamsMraRecordingDb;

    when('is_serialized')->alias(static fn ($value): bool => is_string($value) && preg_match('/^(?:N;|[bidsaOCE]:)/', trim($value)) === 1);
    when('wp_slash')->alias(static fn ($value) => is_string($value) ? addslashes($value) : $value);
    when('is_wp_error')->alias(static fn ($value): bool => $value === 'error');
    when('current_user_can')->justReturn(true);
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

it('skips a revision and a revision meta row, whatever the URLs are', function (string $type): void {
    expect(rewrite_row_result(['source_type' => $type]))->toBe(['status' => 'skipped', 'applied' => 2]);
})->with(['revision', 'revision_meta']);

it('skips a row whose target is empty, and one whose target is its own URL', function (string $to): void {
    expect(rewrite_row_result(['target_url' => $to]))->toBe(['status' => 'skipped', 'applied' => 2]);
})->with(['an empty target' => '', 'the same URL' => REWRITE_OLD]);

it('skips a row of a type it does not know', function (): void {
    expect(rewrite_row_result(['source_type' => 'mystery']))->toBe(['status' => 'skipped', 'applied' => 2]);
});

it('does not skip a content row whose target differs and is not empty', function (): void {
    when('get_post')->justReturn();

    expect(rewrite_row_result([]))->toBe(['status' => 'failed', 'applied' => 3]);
});

it('fails a content row for a user who may not post unfiltered HTML, without reading the post', function (): void {
    when('current_user_can')->justReturn(false);
    Functions\expect('get_post')->never();

    expect(rewrite_row_result([]))->toBe(['status' => 'failed', 'applied' => 3]);
});

it('skips a content row whose text does not hold the URL', function (): void {
    when('get_post')->justReturn((object) ['post_content' => 'no link here', 'post_excerpt' => 'nor here']);
    Functions\expect('wp_update_post')->never();

    expect(rewrite_row_result([]))->toBe(['status' => 'skipped', 'applied' => 2]);
});

it('rewrites the content and the excerpt through wp_update_post with slashed text and an error return', function (): void {
    when('get_post')->justReturn((object) ['post_content' => 'a '.REWRITE_OLD.' b', 'post_excerpt' => 'c '.REWRITE_OLD]);
    Functions\expect('wp_update_post')
        ->once()
        ->with(['ID' => 7, 'post_content' => addslashes('a '.REWRITE_NEW.' b'), 'post_excerpt' => addslashes('c '.REWRITE_NEW)], true)
        ->andReturn(7);

    expect(rewrite_row_result([]))->toBe(['status' => 'applied', 'applied' => 1]);
});

it('fails a content row when wp_update_post returns an error', function (): void {
    when('get_post')->justReturn((object) ['post_content' => REWRITE_OLD, 'post_excerpt' => '']);
    when('wp_update_post')->justReturn('error');

    expect(rewrite_row_result([]))->toBe(['status' => 'failed', 'applied' => 3]);
});

it('rewrites an option and says so', function (): void {
    when('get_option')->justReturn(['url' => REWRITE_OLD]);
    Functions\expect('update_option')->once()->with('a_key', ['url' => REWRITE_NEW])->andReturn(true);

    expect(rewrite_row_result(['source_type' => 'option']))->toBe(['status' => 'applied', 'applied' => 1]);
});

it('fails an option row with no name, and one whose write is refused', function (): void {
    expect(rewrite_row_result(['source_type' => 'option', 'source_key' => '']))->toBe(['status' => 'failed', 'applied' => 3]);

    when('get_option')->justReturn(REWRITE_OLD);
    when('update_option')->justReturn(false);

    expect(rewrite_row_result(['source_type' => 'option']))->toBe(['status' => 'failed', 'applied' => 3]);
});

it('skips an option that no longer holds the URL, and fails one that holds it where it cannot be reached', function (): void {
    when('get_option')->justReturn('nothing to change');

    expect(rewrite_row_result(['source_type' => 'option']))->toBe(['status' => 'skipped', 'applied' => 2]);

    when('get_option')->justReturn(rewrite_unreachable());
    Functions\expect('update_option')->never();

    expect(rewrite_row_result(['source_type' => 'option']))->toBe(['status' => 'failed', 'applied' => 3]);
});

it('writes a meta row to the table and key of its type, then drops that row from the cache group of its type', function (string $type, string $table, string $key, string $group): void {
    rewrite_db()->stored = 'see '.REWRITE_OLD;
    Functions\expect('wp_cache_delete')->once()->with('55', $group);

    expect(rewrite_row_result(['source_type' => $type]))->toBe(['status' => 'applied', 'applied' => 1])
        ->and(rewrite_db()->updates)->toBe([[$table, ['meta_value' => 'see '.REWRITE_NEW], [$key => 7]]])
        ->and(rewrite_db()->queries)->toBe(['SELECT meta_value FROM '.$table.' WHERE '.$key.' = %d|7']);
})->with([
    'postmeta' => ['postmeta', 'wp_postmeta', 'meta_id', 'post_meta'],
    'termmeta' => ['termmeta', 'wp_termmeta', 'meta_id', 'term_meta'],
    'usermeta' => ['usermeta', 'wp_usermeta', 'umeta_id', 'user_meta'],
    'commentmeta' => ['commentmeta', 'wp_commentmeta', 'meta_id', 'comment_meta'],
]);

it('fails a meta row that is gone, or whose update returns false', function (): void {
    rewrite_db()->stored = null;

    expect(rewrite_row_result(['source_type' => 'postmeta']))->toBe(['status' => 'failed', 'applied' => 3]);

    rewrite_db()->stored = REWRITE_OLD;
    rewrite_db()->updateResult = false;

    Functions\expect('wp_cache_delete')->never();

    expect(rewrite_row_result(['source_type' => 'postmeta']))->toBe(['status' => 'failed', 'applied' => 3]);
});

it('skips an unchanged meta value, fails one that holds the URL out of reach, and writes nothing for either', function (): void {
    rewrite_db()->stored = 'nothing to change';

    expect(rewrite_row_result(['source_type' => 'postmeta']))->toBe(['status' => 'skipped', 'applied' => 2]);

    rewrite_db()->stored = rewrite_unreachable();

    expect(rewrite_row_result(['source_type' => 'postmeta']))->toBe(['status' => 'failed', 'applied' => 3])
        ->and(rewrite_db()->updates)->toBe([]);
});
