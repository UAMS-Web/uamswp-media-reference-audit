<?php

/**
 * UAMS_MRA_Triage beyond tier(): the tier SQL, the stored marks and the counts, with WordPress mocked.
 *
 * The SQL strings are pinned exactly because a tier a row is shown in and the
 * tier it is counted in must never disagree. That the SQL selects the rows tier()
 * assigns is shown on a real database in tests/Integration/TriageTierSqlTest.php.
 *
 * @see includes/class-triage.php
 */

declare(strict_types=1);

use function Brain\Monkey\Filters\expectApplied;
use function Brain\Monkey\Functions\stubTranslationFunctions;
use function Brain\Monkey\Functions\when;

// ids_marked() and counts() name their table through the store, which the Unit bootstrap does not load.
require_once __DIR__.'/../../includes/class-store.php';

/**
 * A $wpdb that records what it is asked and answers from a script.
 */
function triage_wpdb(array $vars = [], mixed $rows = [], mixed $col = []): object
{
    return new class($vars, $rows, $col)
    {
        public string $prefix = 'wp_';

        public string $postmeta = 'wp_postmeta';

        /** @var list<string> */
        public array $queries = [];

        public function __construct(private array $vars, private mixed $rows, private mixed $col) {}

        public function prepare(string $query, mixed ...$args): string
        {
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function get_var(string $query): mixed
        {
            $this->queries[] = $query;

            return array_shift($this->vars);
        }

        public function get_results(string $query, $output = null): mixed
        {
            $this->queries[] = $query;

            return $this->rows;
        }

        public function get_col(string $query): mixed
        {
            $this->queries[] = $query;

            return $this->col;
        }
    };
}

// counts() asks for associative rows with core's constant.
if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/**
 * A value that is not a string but reads as one, as a database layer's row value might.
 */
function triage_stringable(string $value): Stringable
{
    return new class($value) implements Stringable
    {
        public function __construct(private string $value) {}

        public function __toString(): string
        {
            return $this->value;
        }
    };
}

/**
 * Hand a value over with its type hidden, as a caller outside this code may.
 */
function triage_loose(mixed $value): mixed
{
    return $value;
}

beforeEach(function (): void {
    stubTranslationFunctions();
    when('esc_sql')->returnArg();
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

it('lists the tiers most cautious first, each with a label and a description', function (): void {
    $tiers = UAMS_MRA_Triage::tiers();

    expect(array_keys($tiers))->toBe(['verify', 'ask', 'clear'])
        ->and($tiers['verify']['label'])->toBe('Verify')
        ->and($tiers['ask']['label'])->toBe('Ask team')
        ->and($tiers['clear']['label'])->toBe('Low risk');

    foreach ($tiers as $tier) {
        expect(strlen($tier['desc']))->toBeGreaterThan(0);
    }
});

it('lists the dispositions a person can set', function (): void {
    expect(UAMS_MRA_Triage::dispositions())->toBe(['keep' => 'Keep', 'ask' => 'Ask', 'delete' => 'Delete']);
});

it('names the types that travel outside the site, and returns them as an array whatever a filter does', function (): void {
    expect(UAMS_MRA_Triage::distributable_mimes())->toBe([
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'text/csv',
    ]);

    expectApplied('uams_mra_distributable_mimes')->once()->andReturn('image/png');

    expect(UAMS_MRA_Triage::distributable_mimes())->toBe(['image/png']);
});

it('builds the tier SQL from the same mime list tier() reads', function (): void {
    expectApplied('uams_mra_distributable_mimes')->twice()->andReturn(['image/png', 'text/x']);

    $ask = UAMS_MRA_Triage::tier_where('ask');

    expect($ask)->toBe("( is_attached = 0 OR render_state = 'absent' ) AND ( LEFT( mime, 6 ) = 'video/' OR LEFT( mime, 6 ) = 'audio/' OR mime IN ( 'image/png','text/x' ) )")
        ->and(UAMS_MRA_Triage::tier_where('clear'))->toBe("( is_attached = 0 OR render_state = 'absent' ) AND NOT ( LEFT( mime, 6 ) = 'video/' OR LEFT( mime, 6 ) = 'audio/' OR mime IN ( 'image/png','text/x' ) )");
});

it('escapes every mime type that goes into the SQL list', function (): void {
    when('esc_sql')->alias(static fn (string $value): string => addslashes($value));
    expectApplied('uams_mra_distributable_mimes')->once()->andReturn(["x'y"]);

    expect(UAMS_MRA_Triage::tier_where('ask'))->toContain("mime IN ( 'x\\'y' )");
});

it('keeps a literal percent sign out of the tier SQL, which is prepared later', function (string $tier): void {
    expect(UAMS_MRA_Triage::tier_where($tier))->not->toContain('%');
})->with(['verify', 'ask', 'clear']);

it('selects the verify tier and falls back to every row for an unknown tier', function (): void {
    expect(UAMS_MRA_Triage::tier_where('verify'))->toBe("is_attached = 1 AND render_state <> 'absent'")
        ->and(UAMS_MRA_Triage::tier_where('nonsense'))->toBe('1=1');
});

it('splits video and audio by the first six characters only', function (string $mime, string $expected): void {
    expect(UAMS_MRA_Triage::tier(['is_attached' => 0, 'mime' => $mime]))->toBe($expected);
})->with([
    'video' => ['video/mp4', 'ask'],
    'audio' => ['audio/mpeg', 'ask'],
    'the bare prefix' => ['video/', 'ask'],
    'a prefix not at the start' => ['xvideo/mp4', 'clear'],
    'a near miss' => ['video', 'clear'],
    'an audio near miss' => ['audio', 'clear'],
    'a listed document' => ['text/csv', 'ask'],
    'an unlisted text type' => ['text/plain', 'clear'],
    'the empty string' => ['', 'clear'],
]);

it('reads is_attached as a truth value and render_state as a string', function (array $row, string $expected): void {
    expect(UAMS_MRA_Triage::tier($row + ['mime' => 'image/jpeg']))->toBe($expected);
})->with([
    'attached as a string 1' => [['is_attached' => '1'], 'verify'],
    'attached as a string 0' => [['is_attached' => '0'], 'clear'],
    'attached missing' => [[], 'clear'],
    'attached empty' => [['is_attached' => ''], 'clear'],
    'absent exactly' => [['is_attached' => 1, 'render_state' => 'absent'], 'clear'],
    'a render state that only contains absent' => [['is_attached' => 1, 'render_state' => 'absent-ish'], 'verify'],
    'a null render state' => [['is_attached' => 1, 'render_state' => null], 'verify'],
]);

it('reads a stored disposition and note as strings, and a missing one as empty', function (): void {
    expect(UAMS_MRA_Triage::META_DISPOSITION)->toBe('_uams_mra_disposition')
        ->and(UAMS_MRA_Triage::META_NOTE)->toBe('_uams_mra_note');

    when('get_post_meta')->alias(static fn (int $id, string $key): mixed => $key === '_uams_mra_disposition' ? 'keep' : false);

    expect(UAMS_MRA_Triage::get(triage_loose('12')))->toBe(['d' => 'keep', 'n' => '']);
});

it('reads the meta of the attachment it was given, cast to an integer', function (): void {
    $seen = [];
    when('get_post_meta')->alias(function (int $id, string $key, bool $single) use (&$seen): string {
        $seen[] = [$id, $key, $single];

        return 'v';
    });

    UAMS_MRA_Triage::get(triage_loose('12abc'));

    expect($seen)->toBe([[12, '_uams_mra_disposition', true], [12, '_uams_mra_note', true]]);
});

it('stores a disposition and a note, and reports what it stored', function (): void {
    when('sanitize_text_field')->returnArg();
    $writes = [];
    when('update_post_meta')->alias(function (int $id, string $key, string $value) use (&$writes): bool {
        $writes[] = [$id, $key, $value];

        return true;
    });
    when('delete_post_meta')->alias(static fn (): never => throw new RuntimeException('nothing should be deleted'));

    $result = UAMS_MRA_Triage::set(triage_loose('7'), 'delete', 'old logo');

    expect($result)->toBe(['d' => 'delete', 'n' => 'old logo'])
        ->and($writes)->toBe([[7, '_uams_mra_disposition', 'delete'], [7, '_uams_mra_note', 'old logo']]);
});

it('removes the rows instead of storing a blank', function (): void {
    when('sanitize_text_field')->returnArg();
    when('update_post_meta')->alias(static fn (): never => throw new RuntimeException('nothing should be stored'));

    $deleted = [];
    when('delete_post_meta')->alias(function (int $id, string $key) use (&$deleted): bool {
        $deleted[] = [$id, $key];

        return true;
    });

    $result = UAMS_MRA_Triage::set(7, '', '');

    expect($result)->toBe(['d' => '', 'n' => ''])
        ->and($deleted)->toBe([[7, '_uams_mra_disposition'], [7, '_uams_mra_note']]);
});

it('refuses a disposition that is not one a person can set', function (mixed $disposition): void {
    when('sanitize_text_field')->returnArg();
    when('update_post_meta')->justReturn(true);

    $deleted = [];
    when('delete_post_meta')->alias(function (int $id, string $key) use (&$deleted): bool {
        $deleted[] = $key;

        return true;
    });

    expect(UAMS_MRA_Triage::set(7, $disposition, 'n'))->toBe(['d' => '', 'n' => 'n'])
        ->and($deleted)->toBe(['_uams_mra_disposition']);
})->with([
    'unknown' => ['purge'],
    'wrong case' => ['Keep'],
    'padded' => [' keep'],
    'null' => [null],
]);

it('cuts a note at the limit, in characters rather than bytes', function (): void {
    when('sanitize_text_field')->returnArg();
    when('delete_post_meta')->justReturn(true);

    $stored = null;
    when('update_post_meta')->alias(function (int $id, string $key, string $value) use (&$stored): bool {
        $stored = $value;

        return true;
    });

    $exact = str_repeat('é', 200);
    expect(UAMS_MRA_Triage::set(1, '', $exact)['n'])->toBe($exact);

    $result = UAMS_MRA_Triage::set(1, '', $exact.'é');

    expect(mb_strlen($result['n']))->toBe(200)
        ->and($result['n'])->toBe($exact)
        ->and($stored)->toBe($exact);
});

it('stores the note as sanitized', function (): void {
    when('sanitize_text_field')->alias(static fn (string $value): string => trim(strip_tags($value)));
    when('delete_post_meta')->justReturn(true);

    $stored = null;
    when('update_post_meta')->alias(function (int $id, string $key, string $value) use (&$stored): bool {
        $stored = $value;

        return true;
    });

    expect(UAMS_MRA_Triage::set(1, '', ' <b>x</b> ')['n'])->toBe('x')
        ->and($stored)->toBe('x');
});

it('drops a note that sanitizes to nothing', function (): void {
    when('sanitize_text_field')->justReturn('');
    when('update_post_meta')->alias(static fn (): never => throw new RuntimeException('nothing should be stored'));

    $deleted = [];
    when('delete_post_meta')->alias(function (int $id, string $key) use (&$deleted): bool {
        $deleted[] = $key;

        return true;
    });

    expect(UAMS_MRA_Triage::set(1, '', '<script></script>')['n'])->toBe('')
        ->and($deleted)->toBe(['_uams_mra_disposition', '_uams_mra_note']);
});

it('selects rows with no disposition, treating an empty value as none', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb();

    $sql = UAMS_MRA_Triage::disposition_where('none');

    expect(preg_replace('/\s+/', ' ', $sql))->toBe("attachment_id NOT IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = '_uams_mra_disposition' AND meta_value <> '' )");
});

it('selects rows holding one disposition', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb();

    expect(preg_replace('/\s+/', ' ', UAMS_MRA_Triage::disposition_where('delete')))
        ->toBe("attachment_id IN ( SELECT post_id FROM wp_postmeta WHERE meta_key = '_uams_mra_disposition' AND meta_value = 'delete' )");
});

it('escapes the disposition and the meta key it puts in SQL', function (): void {
    when('esc_sql')->alias(static fn (string $value): string => addslashes($value));
    $GLOBALS['wpdb'] = triage_wpdb();

    expect(UAMS_MRA_Triage::disposition_where("x'y"))->toContain("meta_value = 'x\\'y'");
});

it('lists the attachments marked with a disposition as integers', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(col: ['4', '9']);

    expect(UAMS_MRA_Triage::ids_marked('keep'))->toBe([4, 9]);

    $query = $GLOBALS['wpdb']->queries[0];

    expect($query)->toContain('FROM wp_uams_mra_results r')
        ->and($query)->toContain("pm.meta_key = '_uams_mra_disposition'")
        ->and($query)->toContain("pm.meta_value = 'keep'")
        ->and($query)->toContain('INNER JOIN wp_postmeta pm');
});

it('returns nothing, and asks the database nothing, for a disposition that does not exist', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(col: ['4']);

    expect(UAMS_MRA_Triage::ids_marked('purge'))->toBe([])
        ->and($GLOBALS['wpdb']->queries)->toBe([]);
});

it('counts each tier inside the scope it was given', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(vars: ['3', '5', '7', '20']);

    $out = UAMS_MRA_Triage::counts('mime = 1');

    expect($out['tier'])->toBe(['verify' => 3, 'ask' => 5, 'clear' => 7]);

    $queries = $GLOBALS['wpdb']->queries;

    expect($queries[0])->toBe("SELECT COUNT(*) FROM wp_uams_mra_results WHERE ( mime = 1 ) AND ( is_attached = 1 AND render_state <> 'absent' )")
        ->and($queries[1])->toContain('WHERE ( mime = 1 ) AND ( ( is_attached = 0 ')
        ->and($queries[2])->toContain('AND NOT ( LEFT(')
        ->and($queries[4])->toBe('SELECT COUNT(*) FROM wp_uams_mra_results WHERE ( mime = 1 )');
});

it('counts the whole table when no scope is given', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(vars: [0, 0, 0, 4]);

    $out = UAMS_MRA_Triage::counts();

    expect($out['none'])->toBe(4)
        ->and($GLOBALS['wpdb']->queries[0])->toContain('WHERE ( 1=1 ) AND (')
        ->and($GLOBALS['wpdb']->queries[4])->toBe('SELECT COUNT(*) FROM wp_uams_mra_results WHERE ( 1=1 )');
});

it('puts the group counts, the delete size and the unmarked remainder in the result', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(
        vars: [0, 0, 0, '10'],
        rows: [
            ['d' => 'keep', 'n' => '2', 'b' => '100'],
            ['d' => 'delete', 'n' => '3', 'b' => '4096'],
            ['d' => 'bogus', 'n' => '50', 'b' => '9'],
        ],
    );

    $out = UAMS_MRA_Triage::counts();

    expect($out['disp'])->toBe(['keep' => 2, 'ask' => 0, 'delete' => 3])
        ->and($out['delete_bytes'])->toBe(4096)
        ->and($out['none'])->toBe(5);
});

it('reports no delete size when nothing is marked for deletion, and never a negative remainder', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(vars: [0, 0, 0, '1'], rows: [['d' => 'keep', 'n' => '2', 'b' => '777']]);

    $out = UAMS_MRA_Triage::counts();

    expect($out['delete_bytes'])->toBe(0)
        ->and($out['none'])->toBe(0);
});

it('groups the disposition counts by the stored value within the scope', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(vars: [0, 0, 0, 0]);

    UAMS_MRA_Triage::counts('x = 1');

    $query = $GLOBALS['wpdb']->queries[3];

    expect($query)->toContain("pm.meta_key = '_uams_mra_disposition'")
        ->and($query)->toContain("pm.meta_value <> '' AND ( x = 1 )")
        ->and($query)->toContain('GROUP BY pm.meta_value');
});

it('reads a row value by what it says, not by what type it arrives as', function (): void {
    expect(UAMS_MRA_Triage::tier(['is_attached' => 1, 'mime' => 'image/jpeg', 'render_state' => triage_stringable('absent')]))->toBe('clear')
        ->and(UAMS_MRA_Triage::tier(['is_attached' => 0, 'mime' => triage_stringable('application/pdf')]))->toBe('ask')
        ->and(UAMS_MRA_Triage::tier(['is_attached' => 0, 'mime' => triage_stringable('video/mp4')]))->toBe('ask');
});

it('returns an empty string for a mark that was never stored', function (): void {
    when('get_post_meta')->justReturn(false);

    expect(UAMS_MRA_Triage::get(3))->toBe(['d' => '', 'n' => '']);
});

it('stores against the integer form of the attachment and the string form of the disposition and note', function (): void {
    $writes = [];
    when('sanitize_text_field')->alias(static function (mixed $value) use (&$writes): mixed {
        $writes[] = $value;

        return $value;
    });
    when('update_post_meta')->alias(static function (mixed $id, string $key, mixed $value) use (&$writes): bool {
        $writes[] = [$id, $key, $value];

        return true;
    });
    when('delete_post_meta')->justReturn(true);

    $result = UAMS_MRA_Triage::set(triage_loose('7'), triage_loose(triage_stringable('keep')), triage_loose(123));

    expect($result)->toBe(['d' => 'keep', 'n' => '123'])
        ->and($writes)->toBe(['123', [7, '_uams_mra_disposition', 'keep'], [7, '_uams_mra_note', '123']]);
});

it('keeps the first characters of a note that is too long', function (): void {
    when('sanitize_text_field')->returnArg();
    when('delete_post_meta')->justReturn(true);
    when('update_post_meta')->justReturn(true);

    $note = implode('', array_map(static fn (int $i): string => chr(97 + $i % 26), range(0, 249)));

    expect(UAMS_MRA_Triage::set(1, '', $note)['n'])->toBe(substr($note, 0, 200));
});

it('returns an empty list when the database hands back no list', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(col: null);

    expect(UAMS_MRA_Triage::ids_marked('keep'))->toBe([]);
});

it('returns the whole result in a fixed shape, with every group counted as zero when nothing is marked', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(vars: [1, 2, 3, 9], rows: []);

    expect(UAMS_MRA_Triage::counts())->toBe([
        'tier' => ['verify' => 1, 'ask' => 2, 'clear' => 3],
        'disp' => ['keep' => 0, 'ask' => 0, 'delete' => 0],
        'delete_bytes' => 0,
        'none' => 9,
    ]);
});

it('reads a missing group list as no groups', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(vars: [0, 0, 0, 3], rows: null);

    expect(UAMS_MRA_Triage::counts()['none'])->toBe(3);
});

it('skips a group it does not know and still reads the ones after it', function (): void {
    $GLOBALS['wpdb'] = triage_wpdb(
        vars: [0, 0, 0, 10],
        rows: [
            ['d' => 'bogus', 'n' => '50', 'b' => '9'],
            ['d' => 'keep', 'n' => '2', 'b' => '100'],
        ],
    );

    $out = UAMS_MRA_Triage::counts();

    expect($out['disp']['keep'])->toBe(2)
        ->and($out['none'])->toBe(8);
});
