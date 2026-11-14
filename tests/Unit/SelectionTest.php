<?php

/**
 * UAMS_MRA_Selection's stored set, pasted-text resolution and totals, with WordPress mocked.
 *
 * The queries that need real tables (the filter match, the rows and the filename
 * lookup against the results table) are pinned in tests/Integration/SelectionQueryTest.php.
 *
 * @see includes/class-selection.php
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\when;

// resolve_text() names the results table through the store, which the Unit bootstrap does not load.
require_once __DIR__.'/../../includes/class-store.php';
require_once __DIR__.'/../../includes/class-selection.php';

/**
 * A $wpdb answering the filename lookup, and recording what it was asked.
 */
function selection_wpdb(array $answers = []): object
{
    return new class($answers)
    {
        public string $prefix = 'wp_';

        /** @var list<string> */
        public array $queries = [];

        public function __construct(private array $answers) {}

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function prepare(string $query, mixed ...$args): string
        {
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function get_var(string $query): mixed
        {
            $this->queries[] = $query;

            return array_shift($this->answers);
        }
    };
}

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

it('keeps the whole stored set under the limit and stores it for the current user', function (): void {
    when('get_current_user_id')->justReturn(9);

    $stored = [];
    when('update_user_meta')->alias(function (int $user, string $key, array $value) use (&$stored): bool {
        $stored = [$user, $key, $value];

        return true;
    });

    expect(UAMS_MRA_Selection::MAX)->toBe(5000)
        ->and(UAMS_MRA_Selection::META_KEY)->toBe('_uams_mra_selection')
        ->and(UAMS_MRA_Selection::store([5, 6]))->toBe([5, 6])
        ->and($stored)->toBe([9, '_uams_mra_selection', [5, 6]]);
});

it('drops zeros and repeats, reads numeric text as numbers, and renumbers the list', function (): void {
    when('get_current_user_id')->justReturn(1);
    when('update_user_meta')->justReturn(true);

    expect(UAMS_MRA_Selection::store([3, 0, '3', 'x', 7, 7.0, '8']))->toBe([3, 7, 8]);
});

it('stores an empty set as an empty list', function (): void {
    when('get_current_user_id')->justReturn(1);
    when('update_user_meta')->justReturn(true);

    expect(UAMS_MRA_Selection::store([]))->toBe([]);
});

it('cuts a set that is over the limit to the first IDs, and keeps one exactly at it', function (): void {
    when('get_current_user_id')->justReturn(1);
    when('update_user_meta')->justReturn(true);

    $exact = range(1, 5000);
    $over = range(1, 5001);

    expect(UAMS_MRA_Selection::store($exact))->toBe($exact)
        ->and(UAMS_MRA_Selection::store($over))->toBe($exact)
        ->and(UAMS_MRA_Selection::store(range(1, 6000)))->toBe($exact);
});

it('counts the limit after repeats and zeros are gone', function (): void {
    when('get_current_user_id')->justReturn(1);
    when('update_user_meta')->justReturn(true);

    $ids = array_merge(range(1, 5000), range(1, 5000), [0, 0]);

    expect(UAMS_MRA_Selection::store($ids))->toBe(range(1, 5000));
});

it('reads the stored set back as integers', function (): void {
    when('get_current_user_id')->justReturn(4);

    $asked = [];
    when('get_user_meta')->alias(function (int $user, string $key, bool $single) use (&$asked): array {
        $asked = [$user, $key, $single];

        return ['5', 6];
    });

    expect(UAMS_MRA_Selection::get())->toBe([5, 6])
        ->and($asked)->toBe([4, '_uams_mra_selection', true]);
});

it('reads a set that was never stored, or is not a list, as empty', function (mixed $stored): void {
    when('get_current_user_id')->justReturn(4);
    when('get_user_meta')->justReturn($stored);

    expect(UAMS_MRA_Selection::get())->toBe([]);
})->with([
    'nothing stored' => [''],
    'a string' => ['5,6'],
    'false' => [false],
    'null' => [null],
]);

it('forgets the set of the current user and no one else', function (): void {
    when('get_current_user_id')->justReturn(4);

    $deleted = [];
    when('delete_user_meta')->alias(function (int $user, string $key) use (&$deleted): bool {
        $deleted[] = [$user, $key];

        return true;
    });

    UAMS_MRA_Selection::clear();

    expect($deleted)->toBe([[4, '_uams_mra_selection']]);
});

it('resolves an ID only when it names an attachment', function (): void {
    when('get_post_type')->alias(static fn (int $id): string => $id === 12 ? 'attachment' : 'post');

    $out = UAMS_MRA_Selection::resolve_text("12\n13");

    expect($out)->toBe(['ids' => [12], 'unmatched' => ['13']]);
});

it('splits pasted text on newlines, commas and semicolons, whatever mixes them', function (): void {
    when('get_post_type')->justReturn('attachment');

    $out = UAMS_MRA_Selection::resolve_text("1,2;3\r\n4\n\n5 ,, 6\r7");

    expect($out['ids'])->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($out['unmatched'])->toBe([]);
});

it('lists each ID once and each unmatched token as pasted', function (): void {
    when('get_post_type')->alias(static fn (int $id): string => $id < 10 ? 'attachment' : 'post');

    $out = UAMS_MRA_Selection::resolve_text('3,3,30,30, 4');

    expect($out)->toBe(['ids' => [3, 4], 'unmatched' => ['30', '30']]);
});

it('returns nothing for empty text, and reads other types as text', function (mixed $text): void {
    expect(UAMS_MRA_Selection::resolve_text($text))->toBe(['ids' => [], 'unmatched' => []]);
})->with(['', "\n,;\r\n", '   ', null]);

it('treats an ID with a leading zero or an attachment ID of zero as not found', function (): void {
    when('get_post_type')->alias(static fn (int $id): string|false => $id === 0 ? 'attachment' : false);
    $GLOBALS['wpdb'] = selection_wpdb();

    expect(UAMS_MRA_Selection::resolve_text('0'))->toBe(['ids' => [], 'unmatched' => ['0']]);
});

it('matches a URL by the attachment it belongs to', function (): void {
    when('attachment_url_to_postid')->alias(static fn (string $url): string => '88');

    $out = UAMS_MRA_Selection::resolve_text('https://site.example.edu/wp-content/uploads/2025/11/a.jpg');

    expect($out)->toBe(['ids' => [88], 'unmatched' => []]);
});

it('reads the scheme of a URL in either case, and not one that only mentions it', function (): void {
    when('attachment_url_to_postid')->justReturn(5);
    $GLOBALS['wpdb'] = selection_wpdb([0, 0]);

    expect(UAMS_MRA_Selection::resolve_text('HTTP://site.example.edu/a.jpg')['ids'])->toBe([5])
        ->and(UAMS_MRA_Selection::resolve_text('ftp://site.example.edu/a.jpg')['unmatched'])->toBe(['ftp://site.example.edu/a.jpg'])
        ->and(UAMS_MRA_Selection::resolve_text('see https://x/a.jpg')['unmatched'])->toBe(['see https://x/a.jpg']);
});

it('falls back to the filename of a URL the site does not recognize', function (): void {
    when('attachment_url_to_postid')->justReturn(0);
    when('wp_parse_url')->alias(static fn (string $url, int $component): string|int|null|false => parse_url($url, $component));
    $GLOBALS['wpdb'] = selection_wpdb(['31']);

    $out = UAMS_MRA_Selection::resolve_text('https://cdn.example.net/media/2025/hero%20image.jpg?v=2');

    expect($out)->toBe(['ids' => [31], 'unmatched' => []])
        ->and($GLOBALS['wpdb']->queries)->toHaveCount(1)
        ->and($GLOBALS['wpdb']->queries[0])->toContain("file = 'hero image.jpg'")
        ->and($GLOBALS['wpdb']->queries[0])->toContain("file LIKE '%/hero image.jpg'");
});

it('falls back to the whole token when a URL has no path', function (): void {
    when('attachment_url_to_postid')->justReturn(0);
    when('wp_parse_url')->justReturn();
    $GLOBALS['wpdb'] = selection_wpdb([0]);

    $out = UAMS_MRA_Selection::resolve_text('https://example.edu');

    expect($out['unmatched'])->toBe(['https://example.edu'])
        ->and($GLOBALS['wpdb']->queries[0])->toContain("file = 'example.edu'");
});

it('looks a filename up in the results table with the first match by ID', function (): void {
    $GLOBALS['wpdb'] = selection_wpdb(['44']);

    $out = UAMS_MRA_Selection::resolve_text('2025/11/50%_off.pdf');

    $query = $GLOBALS['wpdb']->queries[0];

    expect($out['ids'])->toBe([44])
        ->and($query)->toContain('FROM wp_uams_mra_results')
        ->and($query)->toContain("file = '50%_off.pdf'")
        ->and($query)->toContain("file LIKE '%/50\\%\\_off.pdf'")
        ->and($query)->toContain('ORDER BY attachment_id ASC LIMIT 1');
});

it('reads a stored name by what it says when it is percent-encoded and padded', function (): void {
    $GLOBALS['wpdb'] = selection_wpdb(['9']);

    UAMS_MRA_Selection::resolve_text('a%20b.pdf');

    expect($GLOBALS['wpdb']->queries[0])->toContain("file = 'a b.pdf'");
});

it('does not ask the database about a name that decodes to nothing', function (): void {
    $GLOBALS['wpdb'] = selection_wpdb(['9']);

    $out = UAMS_MRA_Selection::resolve_text('%20');

    expect($out)->toBe(['ids' => [], 'unmatched' => ['%20']])
        ->and($GLOBALS['wpdb']->queries)->toBe([]);
});

it('reports a filename the audit does not know as unmatched', function (): void {
    $GLOBALS['wpdb'] = selection_wpdb([null]);

    expect(UAMS_MRA_Selection::resolve_text('missing.jpg'))->toBe(['ids' => [], 'unmatched' => ['missing.jpg']]);
});

it('totals the bytes, the referenced, the attached and the files with no size', function (): void {
    $rows = [
        ['bytes' => '100', 'ref_count' => '2', 'is_attached' => '1'],
        ['bytes' => '0', 'ref_count' => '0', 'is_attached' => '0'],
        ['bytes' => '50', 'ref_count' => '1', 'is_attached' => '0'],
        ['bytes' => '7', 'ref_count' => '0', 'is_attached' => '1'],
    ];

    expect(UAMS_MRA_Selection::totals($rows))->toBe([
        'count' => 4,
        'bytes' => 157,
        'referenced' => 2,
        'attached' => 2,
        'missing' => 1,
    ]);
});

it('totals an empty set as zeros', function (): void {
    expect(UAMS_MRA_Selection::totals([]))->toBe(['count' => 0, 'bytes' => 0, 'referenced' => 0, 'attached' => 0, 'missing' => 0]);
});

it('counts a file as referenced only above zero references', function (): void {
    expect(UAMS_MRA_Selection::totals([['bytes' => 1, 'ref_count' => 0, 'is_attached' => 0]])['referenced'])->toBe(0)
        ->and(UAMS_MRA_Selection::totals([['bytes' => 1, 'ref_count' => 1, 'is_attached' => 0]])['referenced'])->toBe(1);
});

it('skips an empty or blank token and carries on with the ones after it', function (): void {
    when('get_post_type')->justReturn('attachment');

    expect(UAMS_MRA_Selection::resolve_text(' ,5, ,6')['ids'])->toBe([5, 6])
        ->and(UAMS_MRA_Selection::resolve_text(',5')['ids'])->toBe([5]);
});

it('asks for the post type of the number it read, as an integer', function (): void {
    $asked = [];
    when('get_post_type')->alias(function (mixed $id) use (&$asked): string {
        $asked[] = $id;

        return 'attachment';
    });

    UAMS_MRA_Selection::resolve_text('12');

    expect($asked)->toBe([12]);
});

it('totals a row whose numbers arrive as text a database would not send', function (): void {
    $rows = [
        ['bytes' => '', 'ref_count' => 'abc', 'is_attached' => '0.0'],
        ['bytes' => '0.0', 'ref_count' => '', 'is_attached' => 'abc'],
        ['bytes' => '5', 'ref_count' => '3', 'is_attached' => '1'],
    ];

    expect(UAMS_MRA_Selection::totals($rows))->toBe([
        'count' => 3,
        'bytes' => 5,
        'referenced' => 1,
        'attached' => 1,
        'missing' => 2,
    ]);
});
