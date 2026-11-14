<?php

/**
 * UAMS_MRA_Repoint::replace_variants(), with WordPress mocked.
 *
 * The rewrite runs over stored posts, meta and options, so a wrong byte here is
 * a corrupted database. Each form a URL is stored in is pinned, and the serialized
 * case is shown against the naive replacement it exists to avoid.
 *
 * @see includes/class-repoint.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

use function Brain\Monkey\Functions\when;

const OLD_URL = 'https://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf';
const NEW_URL = 'https://new.example.edu/wp-content/uploads/2024/05/report.pdf';

/**
 * WordPress' own is_serialized() and maybe_unserialize(), reduced to what they decide.
 *
 * maybe_unserialize() is `@unserialize( trim( $data ) )` when is_serialized() is true,
 * which returns false for a value that does not unserialize (wp-includes/functions.php).
 */
function wp_like_is_serialized($value): bool
{
    return is_string($value) && preg_match('/^(?:N;|[bidsaOCE]:)/', trim($value)) === 1;
}

function wp_like_maybe_unserialize($value)
{
    if (! wp_like_is_serialized($value)) {
        return $value;
    }

    set_error_handler(static fn (): bool => true);

    try {
        return unserialize(trim($value));
    } finally {
        restore_error_handler();
    }
}

beforeEach(function (): void {
    when('is_serialized')->alias(wp_like_is_serialized(...));
    when('maybe_unserialize')->alias(wp_like_maybe_unserialize(...));
});

it('rewrites every stored form of the URL to the target as given', function (string $stored): void {
    $result = UAMS_MRA_Repoint::replace_variants('<a href="'.$stored.'">x</a>', OLD_URL, NEW_URL);

    expect($result)->toBe('<a href="'.NEW_URL.'">x</a>');
})->with([
    'https' => [OLD_URL],
    'http' => ['http://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf'],
    'scheme-less' => ['//old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf'],
]);

it('rewrites the slash-escaped form with the target escaped the same way', function (): void {
    $stored = '{"url":"https:\\/\\/old.example.edu\\/sites\\/x\\/wp-content\\/uploads\\/2020\\/01\\/report.pdf"}';

    $result = UAMS_MRA_Repoint::replace_variants($stored, OLD_URL, NEW_URL);

    expect($result)->toBe('{"url":"https:\\/\\/new.example.edu\\/wp-content\\/uploads\\/2024\\/05\\/report.pdf"}');
});

it('never leaves a doubled scheme behind', function (): void {
    $result = UAMS_MRA_Repoint::replace_variants(
        implode(' ', [OLD_URL, 'http://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf', '//old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf']),
        OLD_URL,
        NEW_URL,
    );

    expect($result)->toBe(implode(' ', [NEW_URL, NEW_URL, NEW_URL]))
        ->and($result)->not->toContain('https:https:')
        ->and($result)->not->toContain('http://');
});

it('returns the value untouched, byte for byte, when the URL is absent', function (string $value): void {
    expect(UAMS_MRA_Repoint::replace_variants($value, OLD_URL, NEW_URL))->toBe($value);
})->with([
    'plain' => ['nothing to see here'],
    'serialized' => [serialize(['a' => 'https://elsewhere.example/b.jpg', 'n' => 5])],
]);

it('keeps the byte-length prefixes of a serialized value correct', function (): void {
    $stored = serialize(['url' => OLD_URL, 'id' => 7]);

    $result = UAMS_MRA_Repoint::replace_variants($stored, OLD_URL, NEW_URL);

    // The result has to unserialize to exactly the intended structure.
    expect(unserialize($result))->toBe(['url' => NEW_URL, 'id' => 7]);

    // Control: the replacement this method exists to avoid really does corrupt the value.
    // If this stopped failing, the test above would no longer be showing anything.
    $naive = str_replace(OLD_URL, NEW_URL, $stored);
    expect(wp_like_maybe_unserialize($naive))->toBeFalse();
});

it('rewrites inside nested arrays and objects', function (): void {
    $object = new stdClass;
    $object->src = OLD_URL;
    $object->list = [OLD_URL, 'keep'];

    $result = unserialize(UAMS_MRA_Repoint::replace_variants(serialize(['deep' => ['deeper' => $object]]), OLD_URL, NEW_URL));

    expect($result['deep']['deeper']->src)->toBe(NEW_URL)
        ->and($result['deep']['deeper']->list)->toBe([NEW_URL, 'keep']);
});

it('keeps a serialized scalar serialized', function (): void {
    $result = UAMS_MRA_Repoint::replace_variants(serialize(OLD_URL), OLD_URL, NEW_URL);

    expect($result)->toBe(serialize(NEW_URL));
});

it('hands an array that arrived unserialized back as an array', function (): void {
    $result = UAMS_MRA_Repoint::replace_variants(['url' => OLD_URL], OLD_URL, NEW_URL);

    expect($result)->toBe(['url' => NEW_URL]);
});

it('points an old http link at the https target', function (): void {
    $result = UAMS_MRA_Repoint::replace_variants('http://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf', OLD_URL, NEW_URL);

    expect($result)->toStartWith('https://');
});

it('leaves a URL alone when it is only the front of a longer one', function (string $stored): void {
    expect(UAMS_MRA_Repoint::replace_variants($stored, OLD_URL, NEW_URL))->toBe($stored);
})->with([
    'a WebP file kept beside the original' => [OLD_URL.'.webp'],
    'a longer file name' => [OLD_URL.'x'],
    'an encoded continuation' => [OLD_URL.'%20copy'],
]);

it('still rewrites a URL that ends at a quote, a query, a bracket or the end of a sentence', function (string $after): void {
    $result = UAMS_MRA_Repoint::replace_variants('see '.OLD_URL.$after, OLD_URL, NEW_URL);

    expect($result)->toBe('see '.NEW_URL.$after);
})->with(['"', '?ver=2', ')', ' next', '.', '']);

it('hands back a serialized value it cannot read instead of turning it into false', function (): void {
    // The length prefix says 5 where the string is longer: what a naive search and replace leaves.
    $corrupt = 'a:1:{s:3:"url";s:5:"'.OLD_URL.'";}';

    expect(UAMS_MRA_Repoint::is_unreadable($corrupt))->toBeTrue()
        ->and(UAMS_MRA_Repoint::replace_variants($corrupt, OLD_URL, NEW_URL))->toBe($corrupt);
});

it('does not mistake a stored false, or an ordinary value, for an unreadable one', function (mixed $value): void {
    expect(UAMS_MRA_Repoint::is_unreadable($value))->toBeFalse();
})->with([
    'a stored false' => ['b:0;'],
    'a readable array' => [serialize(['a' => 1])],
    'plain text' => ['plain'],
    'an array that arrived unserialized' => [['a' => 1]],
]);

/**
 * Run the protected rewrite_row() against a stored row.
 *
 * @param  array<string, mixed>  $row
 */
function rewrite_row(array $row): array
{
    $method = new ReflectionMethod(UAMS_MRA_Repoint::class, 'rewrite_row');

    return $method->invoke(null, (object) $row);
}

it('passes slashed text to wp_update_post so block JSON keeps its backslashes', function (): void {
    $stored = '<!-- wp:image {"url":"https:\\/\\/old.example.edu\\/sites\\/x\\/wp-content\\/uploads\\/2020\\/01\\/report.pdf"} -->';
    $captured = null;

    when('current_user_can')->justReturn(true);
    when('get_post')->justReturn((object) ['post_content' => $stored, 'post_excerpt' => '']);
    when('wp_slash')->alias(static fn ($value) => is_string($value) ? addslashes($value) : $value);
    when('is_wp_error')->justReturn(false);
    when('wp_update_post')->alias(function (array $postarr) use (&$captured): int {
        $captured = $postarr;

        return 5;
    });

    $result = rewrite_row(['url' => OLD_URL, 'target_url' => NEW_URL, 'source_type' => 'content', 'source_pk' => 5]);

    // wp_update_post() runs wp_unslash() over what it is given, so what it must receive
    // is the intended text with every backslash doubled.
    $intended = '<!-- wp:image {"url":"https:\\/\\/new.example.edu\\/wp-content\\/uploads\\/2024\\/05\\/report.pdf"} -->';

    expect($result['status'])->toBe('applied')
        ->and(stripslashes($captured['post_content']))->toBe($intended)
        ->and($captured['post_content'])->toBe(addslashes($intended));
});

it('reports a meta value it cannot read as failed and writes nothing', function (): void {
    $corrupt = 'a:1:{s:3:"url";s:5:"'.OLD_URL.'";}';

    $GLOBALS['wpdb'] = new class($corrupt)
    {
        public int $updates = 0;

        public string $postmeta = 'wp_postmeta';

        public string $termmeta = 'wp_termmeta';

        public string $usermeta = 'wp_usermeta';

        public string $commentmeta = 'wp_commentmeta';

        public function __construct(private readonly string $value) {}

        public function prepare(string $query, mixed ...$args): string
        {
            return $query;
        }

        public function get_var(string $query): string
        {
            return $this->value;
        }

        public function update(string $table, array $data, array $where): int
        {
            $this->updates++;

            return 1;
        }
    };

    $result = rewrite_row(['url' => OLD_URL, 'target_url' => NEW_URL, 'source_type' => 'postmeta', 'source_pk' => 9, 'source_id' => 3]);

    expect($result)->toBe(['status' => 'failed', 'applied' => 3])
        ->and($GLOBALS['wpdb']->updates)->toBe(0);

    unset($GLOBALS['wpdb']);
});
