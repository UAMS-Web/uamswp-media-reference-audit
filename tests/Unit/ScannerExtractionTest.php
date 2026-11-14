<?php

/**
 * UAMS_MRA_Scanner's extraction helpers, with WordPress mocked and the index replaced.
 *
 * These read text and stored values and decide which files they name. The index
 * lookups they end in are replaced with a recorder, so each test shows what was
 * asked of the index and what came back, without a database.
 *
 * @see includes/class-scanner.php
 */

declare(strict_types=1);

use function Brain\Monkey\Filters\expectApplied;
use function Brain\Monkey\Functions\when;

/**
 * Call a protected static method of the scanner.
 */
function scanner_call(string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod(UAMS_MRA_Scanner::class, $method))->invoke(null, ...$args);
}

/**
 * Redefine a method through Patchwork, which is loaded at run time and unknown to static analysis.
 */
function scanner_redefine(string $target, callable $replacement): void
{
    $redefine = scanner_loose('Patchwork\\redefine');
    $redefine($target, $replacement);
}

/**
 * Pass a value of any type to a typed parameter, as WordPress does with stored data.
 */
function scanner_loose(mixed $value): mixed
{
    return $value;
}

/**
 * Replace the index lookups with a recorder over a fixed set of known attachments.
 *
 * @param  list<int>  $known  Attachment IDs the index "has".
 * @param  array<string, list<int>>  $paths  Upload path => IDs, for resolve_paths().
 * @param  array<string, list<int>>  $names  Normalized name => IDs, for resolve_map().
 * @return object{filtered: list<array>, resolved: list<array>, mapped: list<array>}
 */
function scanner_index(array $known = [], array $paths = [], array $names = []): object
{
    $log = new class
    {
        public array $filtered = [];

        public array $resolved = [];

        public array $mapped = [];
    };

    scanner_redefine('UAMS_MRA_Index::filter_attachment_ids', static function (array $ids) use ($known, $log): array {
        $log->filtered[] = $ids;

        return array_values(array_unique(array_filter(array_map(intval(...), $ids), static fn (int $id): bool => in_array($id, $known, true))));
    });

    scanner_redefine('UAMS_MRA_Index::resolve_paths', static function (array $found) use ($paths, $log): array {
        $log->resolved[] = $found;
        $ids = [];

        foreach ($found as $path) {
            $ids = array_merge($ids, $paths[$path] ?? []);
        }

        return $ids;
    });

    scanner_redefine('UAMS_MRA_Index::resolve_map', static function (array $keys) use ($names, $log): array {
        $log->mapped[] = $keys;

        return array_intersect_key($names, array_flip($keys));
    });

    return $log;
}

beforeEach(function (): void {
    when('wp_get_upload_dir')->justReturn(['baseurl' => 'https://site.example.edu/wp-content/uploads']);
    when('wp_parse_url')->alias(static fn ($url, $component = -1): string|int|array|false|null => parse_url((string) $url, $component));
    when('home_url')->justReturn('https://site.example.edu');
    when('site_url')->justReturn('https://site.example.edu');
    when('uams_mra_settings')->justReturn(['extra_paths' => '']);
    when('is_serialized')->alias(static fn ($value): bool => is_string($value) && preg_match('/^(?:N;|[bidsaOCE]:)/', trim($value)) === 1);
});

afterEach(function (): void {
    $restore = scanner_loose('Patchwork\\restoreAll');
    $restore();
});

// extract_paths()

it('finds an upload path after any prefix, whatever the case, and keeps its dots', function (): void {
    $found = UAMS_MRA_Scanner::extract_paths('<a href="https://site.example.edu/WP-Content/Uploads/2025/11/State-Plan-2.11.25-Final.pdf">x</a>');

    expect($found)->toBe(['2025/11/State-Plan-2.11.25-Final.pdf']);
});

it('reads a slash-escaped path and the numeric entities for a slash', function (string $stored): void {
    expect(UAMS_MRA_Scanner::extract_paths($stored))->toBe(['2025/11/a.jpg']);
})->with([
    'json escaped' => ['{"src":"https:\\/\\/site.example.edu\\/wp-content\\/uploads\\/2025\\/11\\/a.jpg"}'],
    'decimal entity' => ['/ wp-content&#047;uploads&#047;2025&#047;11&#047;a.jpg'],
    'hex entity' => ['/ wp-content&#x2F;uploads&#x2F;2025&#x2F;11&#x2F;a.jpg'],
]);

it('does not read a path whose only slashes are entities, because the slash check runs first', function (): void {
    expect(UAMS_MRA_Scanner::extract_paths('wp-content&#047;uploads&#047;2025&#047;a.jpg'))->toBe([]);
});

it('returns nothing for text without a slash, or without the prefix', function (string $content): void {
    expect(UAMS_MRA_Scanner::extract_paths($content))->toBe([]);
})->with(['', 'no separators at all wp-content.uploads', 'a/b/c.jpg and other/places.png']);

it('stops a path at the characters that end a URL', function (string $stored): void {
    expect(UAMS_MRA_Scanner::extract_paths($stored))->toBe(['2025/11/a.jpg']);
})->with([
    'double quote' => ['wp-content/uploads/2025/11/a.jpg"'],
    'single quote' => ["wp-content/uploads/2025/11/a.jpg'"],
    'angle bracket' => ['wp-content/uploads/2025/11/a.jpg<'],
    'space' => ['wp-content/uploads/2025/11/a.jpg more'],
    'parenthesis' => ['url(wp-content/uploads/2025/11/a.jpg)'],
    'square bracket' => ['[wp-content/uploads/2025/11/a.jpg]'],
    'brace' => ['{wp-content/uploads/2025/11/a.jpg}'],
    'comma' => ['wp-content/uploads/2025/11/a.jpg,b'],
    'semicolon' => ['wp-content/uploads/2025/11/a.jpg;b'],
    'query string' => ['wp-content/uploads/2025/11/a.jpg?ver=3'],
]);

it('needs a file extension of two to five characters', function (string $stored, array $expected): void {
    expect(UAMS_MRA_Scanner::extract_paths($stored))->toBe($expected);
})->with([
    'one character' => ['wp-content/uploads/2025/a.j', []],
    'two characters' => ['wp-content/uploads/2025/a.gz', ['2025/a.gz']],
    'five characters' => ['wp-content/uploads/2025/a.jpeg1', ['2025/a.jpeg1']],
    'a folder with no file' => ['wp-content/uploads/2025/11/', []],
]);

it('lists each path once and every path in a page', function (): void {
    $found = UAMS_MRA_Scanner::extract_paths('wp-content/uploads/a.jpg wp-content/uploads/a.jpg wp-content/uploads/b.png');

    expect($found)->toBe(['a.jpg', 'b.png']);
});

it('drops the paths of a child site, and only those that start with sites/', function (): void {
    $found = UAMS_MRA_Scanner::extract_paths('wp-content/uploads/sites/4/2025/a.jpg wp-content/uploads/Sites/5/b.jpg wp-content/uploads/mysites/c.jpg wp-content/uploads/2025/sites/d.jpg');

    expect($found)->toBe(['mysites/c.jpg', '2025/sites/d.jpg']);
});

it('reads every prefix it is given and takes a prefix as plain text', function (): void {
    $found = UAMS_MRA_Scanner::extract_paths('cdn.example/media/a.jpg wpXcontent/b.jpg a.b/c.png', ['media', 'wp.content', 'a.b']);

    expect($found)->toBe(['a.jpg', 'c.png']);
});

it('takes the prefixes of the current site when none are given', function (): void {
    when('uams_mra_settings')->justReturn(['extra_paths' => "offload/media\n"]);

    expect(UAMS_MRA_Scanner::extract_paths('offload/media/a.jpg wp-content/uploads/b.jpg'))->toBe(['b.jpg', 'a.jpg']);
});

it('reads a null as no text when looking for paths', function (): void {
    expect(UAMS_MRA_Scanner::extract_paths(scanner_loose(null)))->toBe([]);
});

// upload_path_prefixes(), own_uploads_path(), own_hosts()

it('names the uploads path of this site, then the extra paths of the settings', function (): void {
    when('uams_mra_settings')->justReturn(['extra_paths' => "  cdn/media/ \r\n/offload/\n\n   \r\nwp-content/uploads\n"]);

    expect(UAMS_MRA_Scanner::upload_path_prefixes())->toBe(['wp-content/uploads', 'cdn/media', 'offload']);
});

it('leaves out an uploads path that is empty', function (): void {
    when('wp_get_upload_dir')->justReturn(['baseurl' => 'https://site.example.edu']);
    when('uams_mra_settings')->justReturn(['extra_paths' => 'media']);

    expect(UAMS_MRA_Scanner::upload_path_prefixes())->toBe(['media']);
});

it('reports the uploads path with no slash at either end', function (string $baseurl, string $expected): void {
    when('wp_get_upload_dir')->justReturn(['baseurl' => $baseurl]);

    expect(scanner_call('own_uploads_path'))->toBe($expected);
})->with([
    'ordinary' => ['https://site.example.edu/wp-content/uploads', 'wp-content/uploads'],
    'trailing slash' => ['https://site.example.edu/wp-content/uploads/', 'wp-content/uploads'],
    'no path' => ['https://site.example.edu', ''],
    'a network site' => ['https://site.example.edu/wp-content/uploads/sites/4', 'wp-content/uploads/sites/4'],
]);

it('lists the hosts of the uploads, home and site URLs once each, lower case', function (): void {
    when('wp_get_upload_dir')->justReturn(['baseurl' => 'https://CDN.Example.net/wp-content/uploads']);
    when('home_url')->justReturn('https://Home.example.edu');
    when('site_url')->justReturn('https://Site.example.edu/wp');

    expect(scanner_call('own_hosts'))->toBe(['cdn.example.net', 'home.example.edu', 'site.example.edu']);

    when('wp_get_upload_dir')->justReturn(['baseurl' => 'https://one.example.edu/wp-content/uploads']);
    when('home_url')->justReturn('https://one.example.edu');
    when('site_url')->justReturn('https://two.example.edu');

    expect(scanner_call('own_hosts'))->toBe(['one.example.edu', 'two.example.edu']);
});

it('skips a URL with no host', function (): void {
    when('wp_get_upload_dir')->justReturn(['baseurl' => '/wp-content/uploads']);
    when('home_url')->justReturn('');

    expect(scanner_call('own_hosts'))->toBe(['site.example.edu']);
});

// extract_ids_from_content()

it('reads the attachment IDs a classic editor leaves in post content', function (string $content, array $expected): void {
    expect(UAMS_MRA_Scanner::extract_ids_from_content($content))->toBe($expected);
})->with([
    'image class' => ['<img class="alignnone wp-image-12">', [12]],
    'image class upper case' => ['<img class="WP-IMAGE-13">', [13]],
    'attachment page link' => ['<a rel="attachment wp-att-14">', [14]],
    'caption shortcode' => ['[caption id="attachment_15" align="aligncenter"]', [15]],
    'attachment_id attribute' => ['[x attachment_id="16"]', [16]],
    'attachment id with a dash and a colon' => ['{attachment-id: 17}', [17]],
    'attachmentid unquoted' => ['attachmentid=18', [18]],
    'attachment id single quoted' => ["attachment_id = '19'", [19]],
]);

it('reads the ID lists of a shortcode', function (string $content, array $expected): void {
    expect(UAMS_MRA_Scanner::extract_ids_from_content($content))->toBe($expected);
})->with([
    'a gallery' => ['[gallery ids="1,2,3"]', [1, 2, 3]],
    'spaces and commas' => ["[gallery ids='4 , 5 6']", [4, 5, 6]],
    'padded list' => ['[gallery ids=" 7,8 "]', [7, 8]],
    'upper case attribute' => ['[gallery IDS="9"]', [9]],
    'a word that only ends in ids' => ['[x paids="10"]', []],
]);

it('reads the IDs a block comment carries as JSON', function (): void {
    $content = '<!-- wp:image {"id":21,"sizeSlug":"large"} --><figure></figure><!-- /wp:image -->'
        .'<!-- wp:gallery {"ids":[22, 23,24],"columns":2} -->'
        ."<!--   wp:cover {\n \"id\" : 25\n} -->";

    expect(UAMS_MRA_Scanner::extract_ids_from_content($content))->toBe([21, 22, 23, 24, 25]);
});

it('reads an empty ID list in a block as nothing', function (): void {
    expect(UAMS_MRA_Scanner::extract_ids_from_content('<!-- wp:gallery {"ids":[]} -->'))->toBe([]);
});

it('ignores block-style JSON outside a block comment and comments that are not blocks', function (): void {
    expect(UAMS_MRA_Scanner::extract_ids_from_content('{"id":30,"ids":[31]} <!-- note {"id":32} --> <!-- wp:image -->'))->toBe([]);
});

it('lists each ID once, drops zero, and returns integers', function (): void {
    $found = UAMS_MRA_Scanner::extract_ids_from_content('wp-image-5 wp-image-5 wp-att-5 wp-image-0 [gallery ids="0,5,06"]');

    expect($found)->toBe([5, 6]);
});

it('returns nothing for text with no ID in it', function (mixed $content): void {
    expect(UAMS_MRA_Scanner::extract_ids_from_content($content))->toBe([]);
})->with(['', 'plain words', null]);

// scan_meta_value()

it('takes a featured image as strong evidence and stops there', function (): void {
    $index = scanner_index(known: [7, 9], paths: ['a.jpg' => [9]]);

    $hits = UAMS_MRA_Scanner::scan_meta_value('_thumbnail_id', ' 7 ');

    expect($hits)->toBe([7 => 2])
        ->and($index->filtered)->toBe([[7]])
        ->and($index->resolved)->toBe([]);
});

it('does not read a featured image that is not a number as one', function (string $value): void {
    $index = scanner_index(known: [7]);

    expect(UAMS_MRA_Scanner::scan_meta_value('_thumbnail_id', $value))->toBe([])
        ->and($index->filtered)->toBe([[]]);
})->with(['text' => ['seven'], 'empty' => ['']]);

it('takes an upload URL in any value as strong evidence', function (): void {
    $index = scanner_index(paths: ['2025/11/a.jpg' => [3, 4]]);

    $hits = UAMS_MRA_Scanner::scan_meta_value('hero', 'see wp-content/uploads/2025/11/a.jpg');

    expect($hits)->toBe([3 => 2, 4 => 2])
        ->and($index->resolved)->toBe([['2025/11/a.jpg']]);
});

it('does not ask the index about paths when the value has none', function (): void {
    $index = scanner_index();

    UAMS_MRA_Scanner::scan_meta_value('hero', 'nothing here');

    expect($index->resolved)->toBe([]);
});

it('takes the id keys of page builders as strong evidence', function (string $value): void {
    $index = scanner_index(known: [40]);

    expect(UAMS_MRA_Scanner::scan_meta_value('layout', $value))->toBe([40 => 2])
        ->and($index->filtered[0])->toContain('40');
})->with([
    'id' => ['{"id":40}'],
    'ids' => ['{"ids":40}'],
    'image_id' => ['{"image_id": 40}'],
    'attachment_id' => ['{"attachment_id":40}'],
    'thumbnail_id' => ['{"thumbnail_id":40}'],
    'media_id' => ['{"media_id":40}'],
    'upper case key' => ['{"MEDIA_ID":40}'],
]);

it('reads the ID lists of a JSON ids key', function (): void {
    $index = scanner_index(known: [41, 42, 43]);

    expect(UAMS_MRA_Scanner::scan_meta_value('layout', '{"ids":[ 41, 42,43 ]}', false))->toBe([41 => 2, 42 => 2, 43 => 2])
        ->and($index->filtered)->toBe([['41', '42', '43']]);
});

it('does not read JSON keys from a value with no quote in it', function (): void {
    $index = scanner_index(known: [40]);

    expect(UAMS_MRA_Scanner::scan_meta_value('layout', 'id:40', false))->toBe([])
        ->and($index->filtered)->toBe([]);
});

it('does not take a key that only ends in one of the names', function (): void {
    scanner_index(known: [40]);

    expect(UAMS_MRA_Scanner::scan_meta_value('layout', '{"post_id":40}', false))->toBe([]);
});

it('takes a bare number or a list of numbers as weak evidence', function (string $value, array $expected): void {
    scanner_index(known: [5, 6, 7]);

    expect(UAMS_MRA_Scanner::scan_meta_value('field', $value))->toBe($expected);
})->with([
    'a number' => ['5', [5 => 1]],
    'a padded number' => [" 5\n", [5 => 1]],
    'a list' => ['5,6, 7', [5 => 1, 6 => 1, 7 => 1]],
    'a number that is not an attachment' => ['8', []],
    'text' => ['five', []],
]);

it('reads at most eleven digits as a number and fewer than 500 characters as a list', function (): void {
    $eleven = str_repeat('1', 11);
    $twelve = str_repeat('1', 12);

    scanner_index(known: [(int) $eleven, (int) $twelve]);

    expect(UAMS_MRA_Scanner::scan_meta_value('field', $eleven))->toBe([(int) $eleven => 1]);
    // Twelve digits is too long for the single-number branch and is read by the list branch instead.
    expect(UAMS_MRA_Scanner::scan_meta_value('field', $twelve))->toBe([(int) $twelve => 1]);

    $short = implode(',', array_fill(0, 100, '1')).','.str_repeat(' ', 100);
    $long = str_repeat('1,', 250);

    expect(strlen(trim($short)))->toBeLessThan(500)
        ->and(strlen(trim($long)))->toBe(500);

    scanner_index(known: [1]);

    expect(UAMS_MRA_Scanner::scan_meta_value('field', $short))->toBe([1 => 1])
        ->and(UAMS_MRA_Scanner::scan_meta_value('field', $long))->toBe([]);
});

it('reads the numbers inside a serialized array as weak evidence', function (): void {
    scanner_index(known: [5, 6, 7, 8]);

    $value = serialize(['logo' => '5', 'text' => '6x', 'deep' => ['image' => 7], 8, 'count' => 99]);

    expect(UAMS_MRA_Scanner::scan_meta_value('field', $value))->toBe([5 => 1, 7 => 1, 8 => 1]);
});

it('reads nothing from a serialized value that is not an array or cannot be read', function (string $value): void {
    $index = scanner_index(known: [5]);

    expect(UAMS_MRA_Scanner::scan_meta_value('field', $value))->toBe([])
        ->and($index->filtered)->toBe([[]]);
})->with([
    'a serialized string' => [serialize('5')],
    'an object' => ['O:7:"Missing":0:{}'],
]);

it('never lets weak evidence replace strong evidence for the same file', function (): void {
    scanner_index(known: [5], paths: ['a.jpg' => [5]]);

    expect(UAMS_MRA_Scanner::scan_meta_value('field', 'wp-content/uploads/a.jpg 5'))->toBe([5 => 2]);

    scanner_index(known: [5]);

    expect(UAMS_MRA_Scanner::scan_meta_value('field', '5'))->toBe([5 => 1]);
});

it('refuses the weak match to a key for another object, and when the caller says so, but keeps strong evidence', function (): void {
    $index = scanner_index(known: [5, 6], paths: ['a.jpg' => [6]]);

    expect(UAMS_MRA_Scanner::scan_meta_value('parent_id', '5'))->toBe([])
        ->and(UAMS_MRA_Scanner::scan_meta_value('field', '5', false))->toBe([])
        ->and(UAMS_MRA_Scanner::scan_meta_value('source_user_id', 'wp-content/uploads/a.jpg 5'))->toBe([6 => 2])
        ->and($index->filtered)->toBe([]);
});

it('reads a number as text when scanning meta', function (): void {
    scanner_index(known: [5]);

    expect(UAMS_MRA_Scanner::scan_meta_value('field', scanner_loose(5)))->toBe([5 => 1]);
});

// collect_ids_from_array()

it('collects a number held under a numeric key or under a key that reads like media', function (): void {
    $found = scanner_call('collect_ids_from_array', [
        4,
        'logo' => '5',
        'my_Image_x' => 6,
        'photo' => '7',
        'gallery' => '8',
        'banner' => '9',
        'background' => '10',
        'header' => '11',
        'file' => '12',
        'thumb' => '13',
        'icon' => '14',
        'img' => '15',
        'media' => '16',
        'attachment' => '17',
        'id' => '18',
    ]);

    expect($found)->toBe([4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18]);
});

it('leaves out a number under a key that says nothing about media', function (): void {
    expect(scanner_call('collect_ids_from_array', ['count' => 5, 'size' => '6']))->toBe([]);
});

it('leaves out anything that is not a whole number of eleven digits or fewer', function (): void {
    $found = scanner_call('collect_ids_from_array', [
        'id_a' => '',
        'id_b' => 'x5',
        'id_c' => '5.5',
        'id_d' => 5.5,
        'id_e' => '123456789012',
        'id_f' => str_repeat('1', 11),
        'id_g' => '-5',
        'id_h' => null,
        'id_i' => new stdClass,
        'id_j' => ' 6 ',
    ]);

    expect($found)->toBe([(int) str_repeat('1', 11), 6]);
});

it('reads a true as the number one', function (): void {
    expect(scanner_call('collect_ids_from_array', ['logo' => true, 'icon' => false]))->toBe([1]);
});

it('walks nested arrays to the sixth level and no further', function (): void {
    $deep = ['logo' => '5'];

    for ($i = 0; $i < 6; $i++) {
        $deep = ['n' => $deep];
    }

    // Six wrappers put the value at depth 6, which is still read.
    expect(scanner_call('collect_ids_from_array', $deep))->toBe([5]);

    // One more wrapper puts it at depth 7, which is not.
    expect(scanner_call('collect_ids_from_array', ['n' => $deep]))->toBe([]);
});

it('returns nothing from a depth past the limit and keeps reading siblings after a nested array', function (): void {
    expect(scanner_call('collect_ids_from_array', ['logo' => '5'], 7))->toBe([])
        ->and(scanner_call('collect_ids_from_array', ['logo' => '5'], 6))->toBe([5])
        ->and(scanner_call('collect_ids_from_array', ['a' => ['b' => '5'], 'logo' => '6']))->toBe([6]);
});

// is_foreign_id_key(), foreign_id_key_pattern(), source_allows_numeric_match(), skipped_meta_keys()

it('names a key that holds some other object ID', function (mixed $key, bool $expected): void {
    expect(scanner_call('is_foreign_id_key', $key))->toBe($expected);
})->with([
    'user_id' => ['user_id', true],
    'a prefix' => ['source_user_id', true],
    'upper case' => ['AUTHOR_ID', true],
    'parent_id' => ['parent_id', true],
    'order_id' => ['order_id', true],
    'entry_id' => ['entry_id', true],
    'a name that only ends in one' => ['superuser_id', false],
    'a key that could hold media' => ['image_id', false],
    'attachment_id' => ['attachment_id', false],
    'user_id with a suffix' => ['user_id_x', false],
    'a number' => [5, false],
]);

it('lets a filter replace the key pattern, and an empty pattern turns the check off', function (): void {
    expectApplied('uams_mra_foreign_id_key_pattern')->twice()->andReturn('#^custom$#', '');

    expect(scanner_call('is_foreign_id_key', 'custom'))->toBeTrue()
        ->and(scanner_call('is_foreign_id_key', 'user_id'))->toBeFalse();
});

it('allows the weak numeric match for every source type except usermeta', function (string $type, bool $expected): void {
    expect(scanner_call('source_allows_numeric_match', $type))->toBe($expected);
})->with([
    'postmeta' => ['postmeta', true],
    'revision_meta' => ['revision_meta', true],
    'option' => ['option', true],
    'termmeta' => ['termmeta', true],
    'commentmeta' => ['commentmeta', true],
    'usermeta' => ['usermeta', false],
    'unknown' => ['network', false],
    'empty' => ['', false],
]);

it('lets a filter switch a source type on', function (): void {
    expectApplied('uams_mra_numeric_match_sources')->once()->andReturn(['usermeta' => true]);

    expect(scanner_call('source_allows_numeric_match', 'usermeta'))->toBeTrue();
});

it('skips the meta that describes the attachment itself and this plugin triage, as a lookup', function (): void {
    $keys = scanner_call('skipped_meta_keys');

    foreach (['_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_wp_attachment_image_alt', '_wp_attachment_is_custom_header', '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_uams_mra_disposition', '_uams_mra_note'] as $key) {
        expect($keys)->toHaveKey($key);
    }

    expect($keys)->toHaveCount(14)
        ->and($keys)->not->toHaveKey('_thumbnail_id')
        ->and($keys['_edit_lock'])->toBe(5);
});

it('lets a filter add a skipped meta key', function (): void {
    expectApplied('uams_mra_skipped_meta_keys')->once()->andReturnUsing(static fn (array $keys): array => [...$keys, 'extra']);

    expect(scanner_call('skipped_meta_keys'))->toHaveKey('extra');
});

// group_by_host(), extract_offsite_uploads(), collect_offsite()

it('flips an ID to host map into host to IDs, as integers', function (): void {
    expect(scanner_call('group_by_host', ['4' => 'old.example.edu', 5 => 'cdn.example.net', 6 => 'old.example.edu']))
        ->toBe(['old.example.edu' => [4, 6], 'cdn.example.net' => [5]]);

    expect(scanner_call('group_by_host', []))->toBe([]);
});

it('reports the attachments a page serves from another host, the first host seen per file name', function (): void {
    $index = scanner_index(names: ['a.jpg' => [3, 4], 'b.jpg' => [5]]);

    $out = scanner_call('extract_offsite_uploads', '<img src="https://old.example.edu/wp-content/uploads/2020/a-300x200.jpg"><img src="https://cdn.example.net/wp-content/uploads/2021/a.jpg"><img src="https://cdn.example.net/wp-content/uploads/b.jpg"><img src="https://x.example/wp-content/uploads/none.jpg">');

    expect($out)->toBe([3 => 'old.example.edu', 4 => 'old.example.edu', 5 => 'cdn.example.net'])
        ->and($index->mapped)->toBe([['a.jpg', 'b.jpg', 'none.jpg']]);
});

it('asks the index nothing when a page serves nothing from elsewhere', function (): void {
    $index = scanner_index();

    expect(scanner_call('extract_offsite_uploads', '<img src="https://site.example.edu/wp-content/uploads/a.jpg">'))->toBe([])
        ->and($index->mapped)->toBe([]);
});

it('returns an empty list when none of the files elsewhere are in the library', function (): void {
    scanner_index();

    expect(scanner_call('extract_offsite_uploads', 'https://old.example.edu/wp-content/uploads/a.jpg'))->toBe([]);
});

it('turns the foreign uploads URLs in content into rows for the offsite report', function (): void {
    $rows = scanner_call('collect_offsite', 'x https://old.example.edu/wp-content/uploads/2020/a-300x200.jpg y //cdn.example.net/wp-content/uploads/b.png', 'content', 12, 'A page');

    expect($rows)->toBe([
        [
            'url' => 'https://old.example.edu/wp-content/uploads/2020/a-300x200.jpg',
            'host' => 'old.example.edu',
            'base_norm' => 'a.jpg',
            'source_type' => 'content',
            'source_id' => 12,
            'source_pk' => 12,
            'source_key' => '',
            'source_label' => 'A page',
        ],
        [
            'url' => 'https://cdn.example.net/wp-content/uploads/b.png',
            'host' => 'cdn.example.net',
            'base_norm' => 'b.png',
            'source_type' => 'content',
            'source_id' => 12,
            'source_pk' => 12,
            'source_key' => '',
            'source_label' => 'A page',
        ],
    ]);
});

it('names the exact row and key to write back to when it is given them', function (): void {
    $rows = scanner_call('collect_offsite', 'https://old.example.edu/wp-content/uploads/a.jpg', 'postmeta', 12, 'A page', 77, 'hero');

    expect($rows[0]['source_id'])->toBe(12)
        ->and($rows[0]['source_pk'])->toBe(77)
        ->and($rows[0]['source_key'])->toBe('hero');
});

it('returns no rows for content with no foreign uploads URL', function (): void {
    expect(scanner_call('collect_offsite', 'https://site.example.edu/wp-content/uploads/a.jpg', 'content', 1, 'x'))->toBe([]);
});

// scan_meta_value(): what is handed to the index

it('hands the index a whole number as an integer and a longer list as text', function (string $value, array $expected): void {
    $index = scanner_index(known: [1]);

    UAMS_MRA_Scanner::scan_meta_value('field', $value);

    expect($index->filtered)->toBe($expected);
})->with([
    'eleven digits' => [str_repeat('7', 11), [[77777777777]]],
    'twelve digits' => [str_repeat('7', 12), [['777777777777']]],
    'a padded number' => [" 5\n", [[5]]],
    'a list of 499 characters' => [str_repeat('1,', 249).'1', [array_fill(0, 250, '1')]],
    'a list of 500 characters' => [str_repeat('1,', 250), [[]]],
]);

it('keeps a strong hit when the same file also matches weakly', function (): void {
    scanner_index(known: [5], paths: ['a.jpg' => [5]]);

    $value = serialize(['logo' => '5', 'url' => 'wp-content/uploads/a.jpg']);

    expect(UAMS_MRA_Scanner::scan_meta_value('field', $value))->toBe([5 => 2]);
});

// extract_foreign_upload_urls()

it('reads a foreign URL whose slashes are stored as entities', function (string $stored): void {
    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls($stored);

    expect($found)->toHaveCount(1)
        ->and($found[0]['path'])->toBe('2020/a.jpg');
})->with([
    'decimal entity' => ['https://old.example.edu/x/wp-content&#047;uploads&#047;2020&#047;a.jpg'],
    'hex entity' => ['https://old.example.edu/x/wp-content&#x2F;uploads&#x2F;2020&#x2F;a.jpg'],
]);

it('reports the host in lower case, the path as found, and the normalized name', function (): void {
    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls('https://OLD.Example.edu/x/wp-content/uploads/2020/Hero-300x200.JPG');

    expect($found)->toBe([[
        'url' => 'https://OLD.Example.edu/x/wp-content/uploads/2020/Hero-300x200.JPG',
        'host' => 'old.example.edu',
        'path' => '2020/Hero-300x200.JPG',
        'base_norm' => 'hero.jpg',
    ]]);
});

it('reports a foreign URL that names none of the claimed prefixes', function (): void {
    when('uams_mra_settings')->justReturn(['extra_paths' => 'media']);

    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls('https://cdn.example.net/other/wp-content/uploads/a.jpg');

    expect($found)->toHaveCount(1);
});

it('claims a URL by a whole path segment of a settings prefix, not a longer name', function (): void {
    when('uams_mra_settings')->justReturn(['extra_paths' => 'media']);

    expect(UAMS_MRA_Scanner::extract_foreign_upload_urls('https://cdn.example.net/mediax/wp-content/uploads/a.jpg'))->toHaveCount(1);
});

it('claims a URL by a settings prefix that ends a longer segment', function (): void {
    when('uams_mra_settings')->justReturn(['extra_paths' => 'media']);

    expect(UAMS_MRA_Scanner::extract_foreign_upload_urls('https://cdn.example.net/foo-media/wp-content/uploads/a.jpg'))->toBe([]);
});

it('claims a URL by a settings prefix even when its uploads layout also matches the site on another host', function (): void {
    when('uams_mra_settings')->justReturn(['extra_paths' => 'media']);

    expect(UAMS_MRA_Scanner::extract_foreign_upload_urls('https://cdn.example.net/media/wp-content/uploads/a.jpg'))->toBe([]);
});

it('reports each URL once, in the order found, with a list index from zero', function (): void {
    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls('https://a.example.net/wp-content/uploads/1.jpg https://b.example.net/wp-content/uploads/2.jpg https://a.example.net/wp-content/uploads/1.jpg https://c.example.net/wp-content/uploads/3.jpg');

    expect(array_keys($found))->toBe([0, 1, 2])
        ->and(array_column($found, 'host'))->toBe(['a.example.net', 'b.example.net', 'c.example.net']);
});

it('claims a URL by a settings prefix when the uploads path of the site is not in it', function (): void {
    when('wp_get_upload_dir')->justReturn(['baseurl' => 'https://site.example.edu/files']);
    when('uams_mra_settings')->justReturn(['extra_paths' => 'media']);

    expect(UAMS_MRA_Scanner::extract_foreign_upload_urls('https://cdn.example.net/media/wp-content/uploads/a.jpg'))->toBe([]);
});

it('offers the filter every source type, including the ones that are off', function (): void {
    expectApplied('uams_mra_numeric_match_sources')->once()->with([
        'postmeta' => true,
        'revision_meta' => true,
        'option' => true,
        'termmeta' => true,
        'commentmeta' => true,
        'usermeta' => false,
    ])->andReturn(['option' => true]);

    expect(scanner_call('source_allows_numeric_match', 'option'))->toBeTrue();
});
