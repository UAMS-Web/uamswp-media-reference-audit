<?php

/**
 * UAMS_MRA_Repoint's matching and replacing, with WordPress mocked and the index replaced.
 *
 * classify() decides what one offsite URL is relative to the library, from the
 * index lookup, a HEAD request and the files on disk. Each verdict is produced by
 * a fixture that earns it and by none that should not. The replacement helpers
 * are pinned over strings, arrays, objects and nested values.
 *
 * @see includes/class-repoint.php
 */

declare(strict_types=1);

use function Brain\Monkey\Filters\expectApplied;
use function Brain\Monkey\Functions\when;

mutates(UAMS_MRA_Repoint::class);

const MATCH_URL = 'https://old.example.edu/sites/x/wp-content/uploads/2020/01/hero-300x200.jpg';

/**
 * Call a protected static method of the repoint class.
 */
function repoint_call(string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod(UAMS_MRA_Repoint::class, $method))->invoke(null, ...$args);
}

/**
 * Pass a value of any type to a typed parameter, as WordPress does with stored data.
 */
function repoint_loose(mixed $value): mixed
{
    return $value;
}

/**
 * Redefine a method through Patchwork, which is loaded at run time and unknown to static analysis.
 */
function repoint_redefine(string $target, callable $replacement): void
{
    $redefine = repoint_loose('Patchwork\\redefine');
    $redefine($target, $replacement);
}

/**
 * A scratch directory that the test removes again.
 *
 * @return array{string, list<string>} Directory with a trailing slash, and the files made in it.
 */
function repoint_scratch(): array
{
    $dir = sys_get_temp_dir().'/uams-mra-match-'.bin2hex(random_bytes(4)).'/';
    mkdir($dir);

    return [$dir, []];
}

/**
 * Remove a scratch directory and what is in it.
 */
function repoint_clean(string $dir): void
{
    foreach (glob($dir.'*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($dir);
}

beforeEach(function (): void {
    when('wp_parse_url')->alias(static fn ($url, $component = -1): string|int|array|false|null => parse_url((string) $url, $component));
    when('trailingslashit')->alias(static fn ($value): string => rtrim((string) $value, '/\\').'/');
    when('is_serialized')->alias(static fn ($value): bool => is_string($value) && preg_match('/^(?:N;|[bidsaOCE]:)/', trim($value)) === 1);
});

afterEach(function (): void {
    $restore = repoint_loose('Patchwork\\restoreAll');
    $restore();
});

// eligible_verdicts() and verdicts()

it('treats an identical and a side-loaded file as eligible and nothing else', function (): void {
    expect(UAMS_MRA_Repoint::eligible_verdicts())->toBe(['identical', 'sideloaded'])
        ->and(UAMS_MRA_Repoint::eligible_verdicts(false))->toBe(['identical', 'sideloaded']);
});

it('adds a resized file, last, when it is opted in', function (): void {
    expect(UAMS_MRA_Repoint::eligible_verdicts(true))->toBe(['identical', 'sideloaded', 'resized']);
});

it('describes every verdict the plan can reach', function (): void {
    when('__')->returnArg();

    $verdicts = UAMS_MRA_Repoint::verdicts();

    expect(array_keys($verdicts))->toBe(['identical', 'resized', 'differs', 'ambiguous', 'nolocal', 'unreachable', 'sideloaded', 'fetchfailed', 'toobig', 'rejected', 'crashed'])
        ->and(array_filter($verdicts, static fn ($text): bool => $text === '' || ! is_string($text)))->toBe([]);
});

// time_budget()

it('spends sixty percent of the execution limit, never below five seconds', function (string $limit, int $expected): void {
    $before = ini_get('max_execution_time');
    ini_set('max_execution_time', $limit);

    try {
        when('apply_filters')->alias(static fn ($hook, $value) => $value);

        expect(repoint_call('time_budget'))->toBe($expected);
    } finally {
        ini_set('max_execution_time', (string) $before);
    }
})->with([
    'sixty seconds' => ['60', 36],
    'rounds down' => ['33', 19],
    'the floor of five' => ['4', 5],
    'exactly at the floor' => ['9', 5],
    'one over the floor' => ['10', 6],
    'no limit assumes thirty' => ['0', 18],
    'one second is still a limit' => ['1', 5],
    'two seconds' => ['2', 5],
]);

it('hands the filter a whole number of seconds and gives back a whole number', function (): void {
    $seen = null;
    $before = ini_get('max_execution_time');
    ini_set('max_execution_time', '33');

    try {
        when('apply_filters')->alias(static function ($hook, $value) use (&$seen): float {
            $seen = $value;

            return 7.9;
        });

        expect(repoint_call('time_budget'))->toBe(7)
            ->and($seen)->toBe(19);
    } finally {
        ini_set('max_execution_time', (string) $before);
    }
});

it('lets the time budget filter have the last word', function (): void {
    when('apply_filters')->alias(static fn ($hook, $value) => $hook === 'uams_mra_repoint_time_budget' ? '7' : $value);

    expect(repoint_call('time_budget'))->toBe(7);
});

// parse_size()

it('reads the dimensions a URL asks for', function (string $url, ?array $expected): void {
    expect(repoint_call('parse_size', $url))->toBe($expected);
})->with([
    'a plain size' => ['https://o.example.edu/uploads/2020/01/hero-300x200.jpg', [300, 200]],
    'a short suffix after the size' => ['https://o.example.edu/uploads/2020/01/hero-300x200-pnw.jpg', [300, 200]],
    'a four-letter suffix' => ['https://o.example.edu/uploads/2020/01/hero-300x200-abcd.png', [300, 200]],
    'five-digit sides' => ['https://o.example.edu/uploads/2020/01/hero-12345x54321.jpg', [12345, 54321]],
    'query string ignored' => ['https://o.example.edu/uploads/2020/01/hero-300x200.jpg?ver=2', [300, 200]],
    'case ignored' => ['https://o.example.edu/uploads/2020/01/HERO-300X200.JPG', [300, 200]],
    'the original' => ['https://o.example.edu/uploads/2020/01/hero.jpg', null],
    'six-digit width' => ['https://o.example.edu/uploads/2020/01/hero-123456x200.jpg', null],
    'six-digit height' => ['https://o.example.edu/uploads/2020/01/hero-300x123456.jpg', null],
    'five-letter suffix' => ['https://o.example.edu/uploads/2020/01/hero-300x200-abcde.jpg', null],
    'no extension' => ['https://o.example.edu/uploads/2020/01/hero-300x200', null],
    'one-letter extension' => ['https://o.example.edu/uploads/2020/01/hero-300x200.j', null],
    'six-letter extension' => ['https://o.example.edu/uploads/2020/01/hero-300x200.abcdef', null],
    'a size in a directory only' => ['https://o.example.edu/uploads/hero-300x200/hero.jpg', null],
    'digits that are not a size' => ['https://o.example.edu/uploads/2020/01/hero-300.jpg', null],
]);

// original_url()

it('names the upload a derivative was made from', function (string $url, string $expected): void {
    expect(UAMS_MRA_Repoint::original_url($url))->toBe($expected);
})->with([
    'a sized derivative' => ['https://o.example.edu/uploads/2020/01/hero-300x200.jpg', 'https://o.example.edu/uploads/2020/01/hero.jpg'],
    'a sized derivative with a suffix' => ['https://o.example.edu/uploads/2020/01/hero-300x200-pnw.png', 'https://o.example.edu/uploads/2020/01/hero.png'],
    'a scaled original' => ['https://o.example.edu/uploads/2020/01/hero-scaled.jpg', 'https://o.example.edu/uploads/2020/01/hero.jpg'],
    'a scaled original, any case' => ['https://o.example.edu/uploads/2020/01/hero-SCALED.JPG', 'https://o.example.edu/uploads/2020/01/hero.JPG'],
    'both a size and scaled' => ['https://o.example.edu/uploads/2020/01/hero-scaled-300x200.jpg', 'https://o.example.edu/uploads/2020/01/hero.jpg'],
    'the original already' => ['https://o.example.edu/uploads/2020/01/hero.jpg', 'https://o.example.edu/uploads/2020/01/hero.jpg'],
    'a size in the directory is left alone' => ['https://o.example.edu/uploads/a-300x200/hero.jpg', 'https://o.example.edu/uploads/a-300x200/hero.jpg'],
    'six digits is not a size' => ['https://o.example.edu/uploads/2020/01/hero-123456x200.jpg', 'https://o.example.edu/uploads/2020/01/hero-123456x200.jpg'],
    'five letters is not a suffix' => ['https://o.example.edu/uploads/2020/01/hero-300x200-abcde.jpg', 'https://o.example.edu/uploads/2020/01/hero-300x200-abcde.jpg'],
    'a bare name' => ['hero-300x200.jpg', 'hero.jpg'],
    'a scaled word that is not the suffix' => ['https://o.example.edu/uploads/2020/01/scaled-hero.jpg', 'https://o.example.edu/uploads/2020/01/scaled-hero.jpg'],
]);

// can_resize()

it('resizes anything that is not an image', function (mixed $dimensions): void {
    expect(repoint_call('can_resize', $dimensions))->toBeTrue();
})->with([
    'false' => [false],
    'empty' => [[]],
    'no width' => [[0, 100]],
    'no height' => [[100, 0]],
]);

it('weighs an image by its decompressed size against the memory still free', function (string $limit, array $dimensions, bool $expected): void {
    when('wp_convert_hr_to_bytes')->justReturn((int) $limit);

    expect(repoint_call('can_resize', $dimensions))->toBe($expected);
})->with([
    'a small image under a large limit' => ['4294967296', [100, 100], true],
    'an image too large for the limit' => ['134217728', [20000, 20000], false],
    'a limit already spent' => ['1', [100, 100], false],
]);

it('assumes five hundred and twelve megabytes when there is no memory limit', function (int $limit): void {
    when('wp_convert_hr_to_bytes')->justReturn($limit);

    // The edge: 512 MB less what is in use, times 0.7, against pixels * 4 * 2.5.
    $free = max(0, 512 * 1048576 - memory_get_usage(true));
    $under = (int) floor(($free * 0.7) / 10) - 1;
    $over = (int) ceil(($free * 0.7) / 10) + 10000;

    expect(repoint_call('can_resize', [$under, 1]))->toBeTrue()
        ->and(repoint_call('can_resize', [$over, 1]))->toBeFalse();
})->with([
    'a limit of minus one' => [-1],
    'a limit of zero' => [0],
]);

it('refuses an image whose decompressed size is exactly seventy percent of the free memory', function (): void {
    $free = 1000;

    when('wp_convert_hr_to_bytes')->justReturn(memory_get_usage(true) + $free);

    // pixels * 4 * 2.5 is 700, which is not below 700.
    expect(70 * 4 * 2.5)->toBe($free * 0.7)
        ->and(repoint_call('can_resize', [70, 1]))->toBeFalse()
        ->and(repoint_call('can_resize', [69, 1]))->toBeTrue();
});

it('reads the dimensions as numbers, so text that is not a number weighs nothing', function (): void {
    when('wp_convert_hr_to_bytes')->justReturn(4294967296);

    expect(repoint_call('can_resize', ['abc', 'def']))->toBeTrue();
});

// replace_variants() and holds_url() given a missing URL

it('leaves a value alone when the source URL is empty or only a scheme', function (mixed $from): void {
    $text = 'plain text with https://elsewhere.example/a.jpg in it';

    expect(UAMS_MRA_Repoint::replace_variants($text, $from, 'https://new.example.edu/a.jpg'))->toBe($text)
        ->and(UAMS_MRA_Repoint::replace_variants(['k' => $text], $from, 'https://new.example.edu/a.jpg'))->toBe(['k' => $text])
        ->and(UAMS_MRA_Repoint::replace_variants(serialize(['k' => $text]), $from, 'https://new.example.edu/a.jpg'))->toBe(serialize(['k' => $text]))
        ->and(UAMS_MRA_Repoint::holds_url($text, $from))->toBeFalse();
})->with([
    'an empty string' => [''],
    'null' => [null],
    'https scheme only' => ['https:'],
    'http scheme only' => ['HTTP:'],
]);

it('treats a missing target as empty text instead of failing', function (): void {
    expect(UAMS_MRA_Repoint::replace_variants('https://old.example.edu/a.jpg', 'https://old.example.edu/a.jpg', repoint_loose(null)))->toBe('');
});

// is_unreadable() and holds_url()

it('calls a serialized value unreadable only when it will not read back', function (mixed $value, bool $expected): void {
    expect(UAMS_MRA_Repoint::is_unreadable($value))->toBe($expected);
})->with([
    'a stored false with padding' => [" b:0;\n", false],
    'a truncated array' => ['a:2:{s:1:"a";', true],
    'a bad length prefix' => ['s:9:"abc";', true],
    'a serialized null' => ['N;', false],
    'a number' => [5, false],
    'an object' => [new stdClass, false],
]);

it('reads a serialized value that has whitespace around it', function (): void {
    $stored = "\n  ".serialize(['a' => 'b'])."  \n";

    expect(UAMS_MRA_Repoint::is_unreadable($stored))->toBeFalse();
});

it('never calls a value that is not a string unreadable, whatever is_serialized says', function (): void {
    when('is_serialized')->justReturn(true);

    expect(UAMS_MRA_Repoint::is_unreadable(false))->toBeFalse();
});

it('reads a malformed value without leaving an error behind', function (): void {
    error_clear_last();

    UAMS_MRA_Repoint::is_unreadable('a:2:{s:1:"a";');

    expect(error_get_last())->toBeNull();
});

it('sees a URL left in a value, in whatever form it is stored', function (mixed $value, bool $expected): void {
    expect(UAMS_MRA_Repoint::holds_url($value, 'https://old.example.edu/a/b.jpg'))->toBe($expected);
})->with([
    'plain text' => ['<img src="https://old.example.edu/a/b.jpg">', true],
    'http' => ['http://old.example.edu/a/b.jpg', true],
    'scheme-less' => ['//old.example.edu/a/b.jpg', true],
    'json-escaped' => ['https:\\/\\/old.example.edu\\/a\\/b.jpg', true],
    'inside an array' => [['x' => ['https://old.example.edu/a/b.jpg']], true],
    'inside serialized text' => [serialize(['u' => 'https://old.example.edu/a/b.jpg']), true],
    'only the front of a longer file' => ['https://old.example.edu/a/b.jpg.webp', false],
    'a different file' => ['https://old.example.edu/a/c.jpg', false],
    'nothing' => ['', false],
]);

// replace_urls_in_string()

it('replaces a URL only where it ends', function (string $subject, string $expected): void {
    $result = repoint_call('replace_urls_in_string', $subject, ['https://o.example.edu/a.jpg'], ['NEW']);

    expect($result)->toBe($expected);
})->with([
    'at the end' => ['https://o.example.edu/a.jpg', 'NEW'],
    'before a quote' => ['"https://o.example.edu/a.jpg"', '"NEW"'],
    'before a space' => ['https://o.example.edu/a.jpg here', 'NEW here'],
    'before a sentence full stop' => ['see https://o.example.edu/a.jpg.', 'see NEW.'],
    'before a query' => ['https://o.example.edu/a.jpg?v=1', 'NEW?v=1'],
    'before a word character' => ['https://o.example.edu/a.jpgx', 'https://o.example.edu/a.jpgx'],
    'before an underscore' => ['https://o.example.edu/a.jpg_old', 'https://o.example.edu/a.jpg_old'],
    'before a percent escape' => ['https://o.example.edu/a.jpg%20copy', 'https://o.example.edu/a.jpg%20copy'],
    'before a second extension' => ['https://o.example.edu/a.jpg.webp', 'https://o.example.edu/a.jpg.webp'],
    'twice in one string' => ['https://o.example.edu/a.jpg and https://o.example.edu/a.jpg', 'NEW and NEW'],
    'not there' => ['nothing to see', 'nothing to see'],
]);

it('runs each needle in order against the result of the one before', function (): void {
    $result = repoint_call('replace_urls_in_string', 'one', ['one', 'two'], ['two', 'three']);

    expect($result)->toBe('three');
});

it('matches a needle as text, not as a pattern', function (): void {
    $result = repoint_call('replace_urls_in_string', 'a.b?c#d aXb', ['a.b?c#d'], ['Z']);

    expect($result)->toBe('Z aXb');
});

it('uses the replacement as given, even when it looks like a back-reference', function (): void {
    $result = repoint_call('replace_urls_in_string', 'x', ['x'], ['$0 \\1 $1']);

    expect($result)->toBe('$0 \\1 $1');
});

it('hands back the subject untouched when there are no needles', function (): void {
    expect(repoint_call('replace_urls_in_string', 'text', [], []))->toBe('text');
});

// deep_replace()

it('replaces inside a string, an array, an object and values nested in them', function (): void {
    $object = new stdClass;
    $object->src = 'A';
    $object->list = ['A', ['A']];

    $result = repoint_call('deep_replace', ['k' => 'A', 'n' => ['A', 3, null, true], 'o' => $object], ['A'], ['B']);

    expect($result['k'])->toBe('B')
        ->and($result['n'])->toBe(['B', 3, null, true])
        ->and($result['o'])->toBe($object)
        ->and($object->src)->toBe('B')
        ->and($object->list)->toBe(['B', ['B']]);
});

it('leaves a scalar that is not a string alone', function (mixed $value): void {
    expect(repoint_call('deep_replace', $value, ['1'], ['2']))->toBe($value);
})->with([
    'an integer' => [1],
    'a float' => [1.5],
    'true' => [true],
    'null' => [null],
]);

it('keeps array keys as they were', function (): void {
    $result = repoint_call('deep_replace', ['A' => 'A', 5 => 'A'], ['A'], ['B']);

    expect($result)->toBe(['A' => 'B', 5 => 'B']);
});

it('stops going deeper after ten levels', function (): void {
    $nest = static function (int $levels): array {
        $data = ['leaf' => 'A'];

        for ($i = 0; $i < $levels; $i++) {
            $data = ['next' => $data];
        }

        return $data;
    };

    $leaf = static function (array $data): string {
        while (isset($data['next'])) {
            $data = $data['next'];
        }

        return $data['leaf'];
    };

    // Depth starts at zero: a leaf string at depth 10 is still replaced, at depth 11 it is not.
    expect($leaf(repoint_call('deep_replace', $nest(9), ['A'], ['B'])))->toBe('B')
        ->and($leaf(repoint_call('deep_replace', $nest(10), ['A'], ['B'])))->toBe('A');
});

it('stops going deeper after ten levels through objects as well', function (): void {
    $nest = static function (int $levels): object {
        $data = new stdClass;
        $data->leaf = 'A';

        for ($i = 0; $i < $levels; $i++) {
            $wrap = new stdClass;
            $wrap->next = $data;
            $data = $wrap;
        }

        return $data;
    };

    $leaf = static function (object $data): string {
        while (isset($data->next)) {
            $data = $data->next;
        }

        return $data->leaf;
    };

    expect($leaf(repoint_call('deep_replace', $nest(9), ['A'], ['B'])))->toBe('B')
        ->and($leaf(repoint_call('deep_replace', $nest(10), ['A'], ['B'])))->toBe('A');
});

it('does not touch an object it cannot read', function (): void {
    $incomplete = unserialize('O:11:"MissingKind":1:{s:3:"url";s:1:"A";}');

    $result = repoint_call('deep_replace', $incomplete, ['A'], ['B']);

    expect($result)->toBe($incomplete)
        ->and(serialize($result))->toBe('O:11:"MissingKind":1:{s:3:"url";s:1:"A";}');
});

// remote_size()

/**
 * Stand in for the HEAD request.
 *
 * @param  array<string, mixed>|WP_Error|null  $response  What wp_safe_remote_head() returns.
 */
function repoint_head(mixed $response, ?array &$seen = null): void
{
    when('home_url')->justReturn('https://site.example.edu');
    when('apply_filters')->alias(static fn ($hook, $value) => $value);
    when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof WP_Error);
    when('wp_remote_retrieve_response_code')->alias(static fn ($r) => $r['response']['code'] ?? '');
    when('wp_remote_retrieve_header')->alias(static fn ($r, $header) => $r['headers'][$header] ?? '');

    when('wp_safe_remote_head')->alias(static function (string $url, array $args) use ($response, &$seen) {
        $seen = ['url' => $url, 'args' => $args];

        return $response;
    });
}

/**
 * A response the way WordPress shapes one.
 *
 * @return array<string, mixed>
 */
function repoint_response(int $code, mixed $length = null): array
{
    return ['response' => ['code' => $code], 'headers' => $length === null ? [] : ['content-length' => $length]];
}

beforeEach(function (): void {
    if (! class_exists('WP_Error')) {
        class_alias(RepointFakeError::class, 'WP_Error');
    }

    if (! defined('MB_IN_BYTES')) {
        define('MB_IN_BYTES', 1048576);
    }

    if (! defined('UAMS_MRA_VERSION')) {
        define('UAMS_MRA_VERSION', '9.9.9');
    }
});

it('reads the size of a remote file from its content length', function (): void {
    repoint_head(repoint_response(200, '12345'), $seen);

    expect(repoint_call('remote_size', MATCH_URL))->toBe(12345);
});

it('asks for the HEAD with a timeout, a redirect limit, ssl verification and an identifying agent', function (): void {
    repoint_head(repoint_response(200, '1'), $seen);

    repoint_call('remote_size', MATCH_URL);

    expect($seen['url'])->toBe(MATCH_URL)
        ->and($seen['args'])->toBe([
            'timeout' => 15,
            'redirection' => 3,
            'sslverify' => true,
            'user-agent' => 'UAMS-Media-Reference-Audit/'.UAMS_MRA_VERSION.' (+https://site.example.edu)',
        ]);
});

it('lets the request arguments be filtered, with the URL', function (): void {
    expectApplied('uams_mra_repoint_request_args')->once()->with(Mockery::type('array'), MATCH_URL);
    expectApplied('uams_mra_render_sslverify')->once()->with(true);

    when('home_url')->justReturn('https://site.example.edu');
    when('is_wp_error')->justReturn(true);
    when('wp_safe_remote_head')->justReturn(new WP_Error);

    repoint_call('remote_size', MATCH_URL);
});

it('reports a file it cannot read as null', function (mixed $response): void {
    repoint_head($response === 'error' ? new WP_Error : $response);

    expect(repoint_call('remote_size', MATCH_URL))->toBeNull();
})->with([
    'a transport error' => ['error'],
    'a not found' => [repoint_response(404, '10')],
    'a redirect' => [repoint_response(301, '10')],
    'a server error' => [repoint_response(500, '10')],
    'no content length' => [repoint_response(200)],
    'an empty content length' => [repoint_response(200, '')],
    'a content length that is false' => [repoint_response(200, false)],
]);

it('reads a response code that arrives as text', function (): void {
    repoint_head(repoint_response(200, '5'), $seen);
    when('wp_remote_retrieve_response_code')->justReturn('200');

    expect(repoint_call('remote_size', MATCH_URL))->toBe(5);
});

it('reads a content length that is a number, not text, as a number', function (): void {
    repoint_head(repoint_response(200, 77), $seen);

    expect(repoint_call('remote_size', MATCH_URL))->toBe(77);
});

it('reads a zero length as zero, not as missing', function (): void {
    repoint_head(repoint_response(200, '0'), $seen);

    expect(repoint_call('remote_size', MATCH_URL))->toBe(0);
});

it('takes the first value of a content length sent twice', function (): void {
    repoint_head(repoint_response(200, ['500', '900']), $seen);

    expect(repoint_call('remote_size', MATCH_URL))->toBe(500);
});

// local_original_size() and local_size_file()

it('does not measure a file it cannot read', function (): void {
    [$dir] = repoint_scratch();
    file_put_contents($dir.'locked.jpg', 'abcdef');
    chmod($dir.'locked.jpg', 0000);

    try {
        when('get_attached_file')->justReturn($dir.'locked.jpg');
        clearstatcache();

        // A file with no permissions, as a user other than root sees one. Under plain PHPUnit the mutant
        // that checks only the path returns 6 here; Pest's mutation stream wrapper cannot stat such a file.
        expect(repoint_call('local_original_size', 1))->toBe(0);
    } finally {
        chmod($dir.'locked.jpg', 0600);
        repoint_clean($dir);
    }
})->skip(PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0), 'A file with no permissions is still readable on Windows and to root.');

it('measures the attachment file on disk, and says zero when it cannot', function (): void {
    [$dir] = repoint_scratch();
    file_put_contents($dir.'orig.jpg', str_repeat('x', 321));

    try {
        when('get_attached_file')->alias(static fn ($id) => match ($id) {
            1 => $dir.'orig.jpg',
            2 => $dir.'missing.jpg',
            3 => $dir,
            default => false,
        });

        expect(repoint_call('local_original_size', 1))->toBe(321)
            ->and(repoint_call('local_original_size', 2))->toBe(0)
            ->and(repoint_call('local_original_size', 3))->toBe(0)
            ->and(repoint_call('local_original_size', 4))->toBe(0);
    } finally {
        repoint_clean($dir);
    }
});

it('finds the generated size that matches the dimensions', function (): void {
    [$dir] = repoint_scratch();
    file_put_contents($dir.'hero-300x200.jpg', str_repeat('x', 50));
    file_put_contents($dir.'hero-150x150.jpg', str_repeat('x', 20));

    try {
        when('wp_get_attachment_metadata')->justReturn(['sizes' => [
            'thumb' => ['file' => 'hero-150x150.jpg', 'width' => 150, 'height' => 150],
            'medium' => ['file' => 'hero-300x200.jpg', 'width' => 300, 'height' => 200],
        ]]);
        when('get_attached_file')->justReturn($dir.'hero.jpg');
        when('wp_get_attachment_url')->justReturn('https://site.example.edu/wp-content/uploads/2020/01/hero.jpg');

        expect(repoint_call('local_size_file', 7, 300, 200))->toBe([
            'bytes' => 50,
            'url' => 'https://site.example.edu/wp-content/uploads/2020/01/hero-300x200.jpg',
        ])->and(repoint_call('local_size_file', 7, 150, 150)['bytes'])->toBe(20);
    } finally {
        repoint_clean($dir);
    }
});

it('finds no generated size when the metadata, the dimensions or the file do not line up', function (mixed $meta, int $width, int $height): void {
    [$dir] = repoint_scratch();
    file_put_contents($dir.'hero-300x200.jpg', 'x');

    try {
        when('wp_get_attachment_metadata')->justReturn($meta);
        when('get_attached_file')->justReturn($dir.'hero.jpg');
        when('wp_get_attachment_url')->justReturn('https://site.example.edu/u/hero.jpg');

        expect(repoint_call('local_size_file', 7, $width, $height))->toBeNull();
    } finally {
        repoint_clean($dir);
    }
})->with([
    'no metadata' => [false, 300, 200],
    'metadata that is not a list' => ['x', 300, 200],
    'no sizes' => [[], 300, 200],
    'empty sizes' => [['sizes' => []], 300, 200],
    'a size with no file' => [['sizes' => [['width' => 300, 'height' => 200]]], 300, 200],
    'a size with an empty file name' => [['sizes' => [['file' => '', 'width' => 300, 'height' => 200]]], 300, 200],
    'a different width' => [['sizes' => [['file' => 'hero-300x200.jpg', 'width' => 301, 'height' => 200]]], 300, 200],
    'a different height' => [['sizes' => [['file' => 'hero-300x200.jpg', 'width' => 300, 'height' => 201]]], 300, 200],
    'a width only' => [['sizes' => [['file' => 'hero-300x200.jpg', 'width' => 300]]], 300, 200],
    'a height only' => [['sizes' => [['file' => 'hero-300x200.jpg', 'height' => 200]]], 300, 200],
    'a file that is not on disk' => [['sizes' => [['file' => 'gone.jpg', 'width' => 300, 'height' => 200]]], 300, 200],
    'a directory where the file should be' => [['sizes' => [['file' => '.', 'width' => 300, 'height' => 200]]], 300, 200],
]);

it('reads dimensions that are stored as text', function (): void {
    [$dir] = repoint_scratch();
    file_put_contents($dir.'a.jpg', 'xx');

    try {
        when('wp_get_attachment_metadata')->justReturn(['sizes' => [['file' => 'a.jpg', 'width' => '300', 'height' => '200']]]);
        when('get_attached_file')->justReturn($dir.'hero.jpg');
        when('wp_get_attachment_url')->justReturn('https://site.example.edu/u/hero.jpg');

        expect(repoint_call('local_size_file', 7, 300, 200)['bytes'])->toBe(2)
            ->and(repoint_call('local_size_file', 7, 200, 300))->toBeNull()
            ->and(repoint_call('local_size_file', 7, 300, 300))->toBeNull();
    } finally {
        repoint_clean($dir);
    }
});

it('treats a missing width or height as zero, and only zero', function (int $width, int $height, bool $found): void {
    [$dir] = repoint_scratch();
    file_put_contents($dir.'a.jpg', 'xx');

    try {
        when('wp_get_attachment_metadata')->justReturn(['sizes' => [['file' => 'a.jpg']]]);
        when('get_attached_file')->justReturn($dir.'hero.jpg');
        when('wp_get_attachment_url')->justReturn('https://site.example.edu/u/hero.jpg');

        expect(repoint_call('local_size_file', 7, $width, $height) !== null)->toBe($found);
    } finally {
        repoint_clean($dir);
    }
})->with([
    'zero by zero' => [0, 0, true],
    'one by zero' => [1, 0, false],
    'zero by one' => [0, 1, false],
    'minus one by zero' => [-1, 0, false],
    'zero by minus one' => [0, -1, false],
]);

it('keeps looking after a size that does not fit', function (): void {
    [$dir] = repoint_scratch();
    file_put_contents($dir.'b.jpg', 'xxxx');

    try {
        when('wp_get_attachment_metadata')->justReturn(['sizes' => [
            ['width' => 300, 'height' => 200],
            ['file' => 'a.jpg', 'width' => 999, 'height' => 200],
            ['file' => 'gone.jpg', 'width' => 300, 'height' => 200],
            ['file' => 'b.jpg', 'width' => 300, 'height' => 200],
        ]]);
        when('get_attached_file')->justReturn($dir.'hero.jpg');
        when('wp_get_attachment_url')->justReturn('https://site.example.edu/u/hero.jpg');

        expect(repoint_call('local_size_file', 7, 300, 200))->toBe(['bytes' => 4, 'url' => 'https://site.example.edu/u/b.jpg']);
    } finally {
        repoint_clean($dir);
    }
});

// classify()

/**
 * Set up a fixture for classify().
 *
 * @param  list<int|string>  $candidates  What the index resolves the name to.
 * @param  array<string, mixed>  $opts  remote (?int), meta, original (file contents), url.
 */
function repoint_classify(array $candidates, array $opts = []): array
{
    [$dir] = repoint_scratch();
    $log = new stdClass;
    $log->names = null;
    $log->head = 0;

    repoint_redefine('UAMS_MRA_Index::resolve_paths', static function (array $names) use ($candidates, $log): array {
        $log->names = $names;

        return $candidates;
    });

    $original = $opts['original'] ?? null;

    if ($original !== null) {
        file_put_contents($dir.'hero.jpg', $original);
    }

    foreach ($opts['files'] ?? [] as $name => $contents) {
        file_put_contents($dir.$name, $contents);
    }

    when('home_url')->justReturn('https://site.example.edu');
    when('apply_filters')->alias(static fn ($hook, $value) => $value);
    when('is_wp_error')->justReturn(false);
    when('wp_safe_remote_head')->alias(static function () use ($opts, $log): array {
        $log->head++;

        return ['response' => ['code' => ($opts['remote'] ?? null) === null ? 404 : 200], 'headers' => ['content-length' => (string) ($opts['remote'] ?? '')]];
    });
    when('wp_remote_retrieve_response_code')->alias(static fn ($r) => $r['response']['code']);
    when('wp_remote_retrieve_header')->alias(static fn ($r, $h) => $r['headers'][$h] ?? '');
    when('get_attached_file')->justReturn($dir.'hero.jpg');
    when('wp_get_attachment_url')->justReturn(($opts['noUrl'] ?? false) ? false : 'https://site.example.edu/wp-content/uploads/2020/01/hero.jpg');
    when('wp_get_attachment_metadata')->justReturn($opts['meta'] ?? false);

    $result = repoint_call('classify', $opts['url'] ?? 'https://old.example.edu/wp-content/uploads/2020/01/hero.jpg', 'hero.jpg');

    repoint_clean($dir);

    return [$result, $log];
}

it('gives nolocal when the library has no file by that name, and never asks the other host', function (): void {
    [$result, $log] = repoint_classify([], ['remote' => 10]);

    expect($result)->toBe(['verdict' => 'nolocal', 'local_id' => 0, 'remote_bytes' => 0, 'local_bytes' => 0, 'target_url' => ''])
        ->and($log->names)->toBe(['hero.jpg'])
        ->and($log->head)->toBe(0);
});

it('gives ambiguous when several attachments share the name, and never asks the other host', function (): void {
    [$result, $log] = repoint_classify([5, 6], ['remote' => 10]);

    expect($result)->toBe(['verdict' => 'ambiguous', 'local_id' => 0, 'remote_bytes' => 0, 'local_bytes' => 0, 'target_url' => ''])
        ->and($log->head)->toBe(0);
});

it('gives unreachable when the other host does not answer, keeping the local match', function (): void {
    [$result] = repoint_classify([5], ['remote' => null, 'original' => 'abc']);

    expect($result)->toBe(['verdict' => 'unreachable', 'local_id' => 5, 'remote_bytes' => 0, 'local_bytes' => 0, 'target_url' => ''])
        ->and($result['verdict'])->not->toBe('nolocal');
});

it('gives identical when the original has the same size as the remote file', function (): void {
    [$result] = repoint_classify([5], ['remote' => 7, 'original' => 'abcdefg']);

    expect($result)->toBe([
        'verdict' => 'identical',
        'local_id' => 5,
        'remote_bytes' => 7,
        'local_bytes' => 7,
        'target_url' => 'https://site.example.edu/wp-content/uploads/2020/01/hero.jpg',
    ]);
});

it('gives differs when the original has another size', function (int $remote, string $original): void {
    [$result] = repoint_classify([5], ['remote' => $remote, 'original' => $original]);

    expect($result['verdict'])->toBe('differs')
        ->and($result['remote_bytes'])->toBe($remote)
        ->and($result['local_bytes'])->toBe(strlen($original))
        ->and($result['local_id'])->toBe(5)
        ->and($result['target_url'])->toBe('https://site.example.edu/wp-content/uploads/2020/01/hero.jpg');
})->with([
    'a larger remote file' => [9, 'abcdefg'],
    'a smaller remote file' => [3, 'abcdefg'],
]);

it('gives differs for an original that cannot be read, since zero bytes is not the remote size', function (): void {
    [$result] = repoint_classify([5], ['remote' => 7]);

    expect($result['verdict'])->toBe('differs')
        ->and($result['local_bytes'])->toBe(0);
});

it('does not take an empty remote file for a missing one', function (): void {
    [$result] = repoint_classify([5], ['remote' => 0, 'original' => '']);

    expect($result['verdict'])->toBe('identical')
        ->and($result['remote_bytes'])->toBe(0);
});

it('gives identical for a sized URL when the generated size has the same bytes', function (): void {
    [$result] = repoint_classify([5], [
        'url' => MATCH_URL,
        'remote' => 4,
        'original' => 'orig-original',
        'files' => ['hero-300x200.jpg' => 'wxyz'],
        'meta' => ['sizes' => [['file' => 'hero-300x200.jpg', 'width' => 300, 'height' => 200]]],
    ]);

    expect($result)->toBe([
        'verdict' => 'identical',
        'local_id' => 5,
        'remote_bytes' => 4,
        'local_bytes' => 4,
        'target_url' => 'https://site.example.edu/wp-content/uploads/2020/01/hero-300x200.jpg',
    ]);
});

it('gives resized for a sized URL when the generated size has other bytes', function (): void {
    [$result] = repoint_classify([5], [
        'url' => MATCH_URL,
        'remote' => 9,
        'original' => 'orig',
        'files' => ['hero-300x200.jpg' => 'wxyz'],
        'meta' => ['sizes' => [['file' => 'hero-300x200.jpg', 'width' => 300, 'height' => 200]]],
    ]);

    expect($result['verdict'])->toBe('resized')
        ->and($result['local_bytes'])->toBe(4)
        ->and($result['remote_bytes'])->toBe(9)
        ->and($result['target_url'])->toBe('https://site.example.edu/wp-content/uploads/2020/01/hero-300x200.jpg');
});

it('gives resized, aimed at the full size, when the library lacks the size the page asks for', function (): void {
    [$result] = repoint_classify([5], [
        'url' => MATCH_URL,
        'remote' => 4,
        'original' => 'abcd',
        'meta' => ['sizes' => []],
    ]);

    // Same byte count as the full size, and still resized: it is a different file from what the page serves.
    expect($result)->toBe([
        'verdict' => 'resized',
        'local_id' => 5,
        'remote_bytes' => 4,
        'local_bytes' => 4,
        'target_url' => 'https://site.example.edu/wp-content/uploads/2020/01/hero.jpg',
    ]);
});

it('gives unreachable for a sized URL the other host does not serve', function (): void {
    [$result] = repoint_classify([5], ['url' => MATCH_URL, 'remote' => null, 'original' => 'abcd']);

    expect($result['verdict'])->toBe('unreachable');
});

it('never reports identical for a URL whose name only looks like a size', function (): void {
    [$result] = repoint_classify([5], ['url' => 'https://old.example.edu/wp-content/uploads/2020/01/hero-300.jpg', 'remote' => 4, 'original' => 'abcd']);

    expect($result['verdict'])->toBe('identical')
        ->and($result['target_url'])->toBe('https://site.example.edu/wp-content/uploads/2020/01/hero.jpg');
});

it('reads the index result as a whole number', function (): void {
    [$result] = repoint_classify(['5'], ['remote' => 3, 'original' => 'abc']);

    expect($result['local_id'])->toBe(5);
});

it('gives an empty target when WordPress has no URL for the attachment', function (): void {
    [$result] = repoint_classify([5], ['remote' => 3, 'original' => 'abc', 'noUrl' => true]);
    [$sized] = repoint_classify([5], ['url' => MATCH_URL, 'remote' => 3, 'original' => 'abc', 'noUrl' => true, 'meta' => ['sizes' => []]]);

    expect($result['target_url'])->toBe('')
        ->and($sized['target_url'])->toBe('');
});

// counts() and applied_counts()

/**
 * Install a stand-in for $wpdb that records the query and returns what it was given.
 *
 * @param  array<int, array<string, mixed>>  $rows  Rows for get_results().
 * @param  array<string, mixed>|null  $row  Row for get_row().
 */
function repoint_wpdb(array $rows = [], ?array $row = null): object
{
    $db = new class($rows, $row)
    {
        public string $query = '';

        public mixed $format = null;

        public function __construct(private readonly array $rows, private readonly ?array $row) {}

        public function get_results(string $query, mixed $format = null): array
        {
            $this->query = $query;
            $this->format = $format;

            return $this->rows;
        }

        public function get_row(string $query, mixed $format = null): ?array
        {
            $this->query = $query;
            $this->format = $format;

            return $this->row;
        }
    };

    $GLOBALS['wpdb'] = $db;
    repoint_redefine('UAMS_MRA_Store::offsite_table', static fn (): string => 'wp_mra_offsite');
    when('ARRAY_A')->justReturn('ARRAY_A');

    return $db;
}

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

it('counts files and rows per verdict, for the rows not yet applied', function (): void {
    if (! defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    $db = repoint_wpdb([
        ['verdict' => 'identical', 'files' => '2', 'rows_affected' => '9'],
        ['verdict' => 'differs', 'files' => '1', 'rows_affected' => '1'],
    ]);

    expect(UAMS_MRA_Repoint::counts())->toBe([
        'identical' => ['files' => 2, 'rows' => 9],
        'differs' => ['files' => 1, 'rows' => 1],
    ])->and($db->query)->toContain('FROM wp_mra_offsite')
        ->and($db->query)->toContain("verdict <> ''")
        ->and($db->query)->toContain('applied = 0')
        ->and($db->query)->toContain('COUNT(DISTINCT url_hash) AS files')
        ->and($db->query)->toContain('COUNT(*) AS rows_affected')
        ->and($db->query)->toContain('GROUP BY verdict')
        ->and($db->format)->toBe(ARRAY_A);
});

it('counts nothing when no row has a verdict', function (): void {
    repoint_wpdb([]);

    expect(UAMS_MRA_Repoint::counts())->toBe([]);
});

it('counts the applied, skipped and failed rows as integers', function (): void {
    $db = repoint_wpdb([], ['applied' => '4', 'skipped' => '2', 'failed' => null]);

    expect(UAMS_MRA_Repoint::applied_counts())->toBe(['applied' => 4, 'skipped' => 2, 'failed' => 0])
        ->and($db->query)->toContain('FROM wp_mra_offsite')
        ->and($db->query)->toContain('applied = 1')
        ->and($db->query)->toContain('applied = 2')
        ->and($db->query)->toContain('applied = 3')
        ->and($db->format)->toBe(ARRAY_A);
});

it('reports no applied counts when the query gives no row', function (): void {
    repoint_wpdb([]);

    expect(UAMS_MRA_Repoint::applied_counts())->toBe([]);
});

/**
 * A stand-in for WP_Error, which WordPress defines and the Unit suite does not load.
 */
final class RepointFakeError {}
