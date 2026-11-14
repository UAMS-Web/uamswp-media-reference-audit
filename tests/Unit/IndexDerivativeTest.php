<?php

/**
 * UAMS_MRA_Index::derivative_patterns() and the edges of normalize(), with WordPress mocked.
 *
 * IndexNormalizeTest.php pins which names collapse. This file pins the filter that
 * can extend the list, and the path handling around the name: separators, encoded
 * characters, dot segments and the stored length limits.
 *
 * @see includes/class-index.php
 */

declare(strict_types=1);

use function Brain\Monkey\Filters\expectApplied;

it('offers the four derivative patterns, in the order they are applied', function (): void {
    expect(UAMS_MRA_Index::derivative_patterns())->toBe([
        '#-\d{1,5}x\d{1,5}(?:-[a-z]{1,4})?$#i',
        '#-scaled$#i',
        '#-rotated$#i',
        '#-e\d{10,}$#i',
    ]);
});

it('lets a filter replace the pattern list and applies what it returns', function (): void {
    expectApplied('uams_mra_derivative_patterns')
        ->twice()
        ->andReturn(['#-copy$#i']);

    expect(UAMS_MRA_Index::derivative_patterns())->toBe(['#-copy$#i'])
        ->and(UAMS_MRA_Index::normalize('2025/11/photo-copy.jpg')[1])->toBe('photo.jpg');
});

it('wraps a filter result that is not an array in one', function (): void {
    expectApplied('uams_mra_derivative_patterns')
        ->once()
        ->andReturn('not a list');

    expect(UAMS_MRA_Index::derivative_patterns())->toBe(['not a list']);
});

it('applies the patterns in order, so a size comes off before -scaled beneath it', function (): void {
    expect(UAMS_MRA_Index::normalize('2025/11/photo-scaled-2560x1440.jpg')[1])->toBe('photo.jpg');
});

it('collapses suffixes stacked in either order', function (string $file): void {
    expect(UAMS_MRA_Index::normalize($file)[1])->toBe('photo.jpg');
})->with([
    'an edit stamp after -scaled' => ['2025/11/photo-scaled-e1700000000000.jpg'],
    'a size after an edit stamp after -scaled' => ['2025/11/photo-scaled-e1700000000000-300x200.jpg'],
    '-rotated after -scaled' => ['2025/11/photo-scaled-rotated.jpg'],
    '-scaled after an edit stamp' => ['2025/11/photo-e1700000000000-scaled.jpg'],
    'a size after -rotated after -scaled' => ['2025/11/photo-scaled-rotated-100x100.jpg'],
]);

it('stops when a pattern list can no longer shorten the name', function (): void {
    expectApplied('uams_mra_derivative_patterns')
        ->once()
        ->andReturn(['#-x$#']);

    expect(UAMS_MRA_Index::normalize('2025/11/a-x-x-x.jpg')[1])->toBe('a.jpg');
});

it('reads a backslash as a separator, whatever the platform', function (string $file, array $expected): void {
    expect(UAMS_MRA_Index::normalize($file))->toBe($expected);
})->with([
    'backslash folders' => ['2025\\11\\a-300x200.jpg', ['2025/11/a.jpg', 'a.jpg']],
    'an encoded backslash' => ['2025%5C11%5Ca.jpg', ['2025/11/a.jpg', 'a.jpg']],
    'a Windows path' => ['C:\\uploads\\2025\\11\\a.jpg', ['2025/11/a.jpg', 'a.jpg']],
    'the slash-escaped form of block JSON' => ['2025\\/11\\/a.jpg', ['2025/11/a.jpg', 'a.jpg']],
    'mixed separators' => ['2025/11\\a.jpg', ['2025/11/a.jpg', 'a.jpg']],
]);

it('removes each pattern once and only at the end of the name', function (string $file, string $expected): void {
    expect(UAMS_MRA_Index::normalize($file)[1])->toBe($expected);
})->with([
    'a size with a one letter marker goes' => ['2025/11/a-300x200-b.jpg', 'a.jpg'],
    'a size in the middle stays' => ['2025/11/a-300x200-b-c.jpg', 'a-300x200-b-c.jpg'],
    '-scaled in the middle stays' => ['2025/11/a-scaled-b.jpg', 'a-scaled-b.jpg'],
    '-rotated in the middle stays' => ['2025/11/a-rotated-b.jpg', 'a-rotated-b.jpg'],
    'an edit stamp with nine digits stays' => ['2025/11/a-e170000000.jpg', 'a-e170000000.jpg'],
    'an edit stamp with ten digits goes' => ['2025/11/a-e1700000000.jpg', 'a.jpg'],
    'a five digit size goes' => ['2025/11/a-12345x54321.jpg', 'a.jpg'],
    'a six digit size stays' => ['2025/11/a-123456x1.jpg', 'a-123456x1.jpg'],
    'a four letter marker goes' => ['2025/11/a-300x200-abcd.jpg', 'a.jpg'],
    'a five letter marker stays' => ['2025/11/a-300x200-abcde.jpg', 'a-300x200-abcde.jpg'],
    'a name with no extension' => ['2025/11/readme-300x200', 'readme'],
]);

it('folds case, decodes percent escapes and reads the slash-escaped form', function (): void {
    expect(UAMS_MRA_Index::normalize('2025/11/My%20File-300X200.JPG'))->toBe(['2025/11/my file.jpg', 'my file.jpg'])
        ->and(UAMS_MRA_Index::normalize('2025\\/11\\/a.jpg'))->toBe(['2025/11/a.jpg', 'a.jpg']);
});

it('trims whitespace and slashes from both ends', function (): void {
    expect(UAMS_MRA_Index::normalize("  /2025/11/a.jpg/ \t\n"))->toBe(['2025/11/a.jpg', 'a.jpg']);
});

it('keeps only the last two folders, and drops dot segments', function (string $file, string $expected): void {
    expect(UAMS_MRA_Index::normalize($file)[0])->toBe($expected);
})->with([
    'a full URL path' => ['https://example.edu/wp-content/uploads/2025/11/a.jpg', '2025/11/a.jpg'],
    'one folder' => ['2025/a.jpg', '2025/a.jpg'],
    'no folder' => ['a.jpg', 'a.jpg'],
    'parent segments are dropped' => ['uploads/../2025/a.jpg', 'uploads/2025/a.jpg'],
    'current segments are dropped' => ['uploads/./2025/a.jpg', 'uploads/2025/a.jpg'],
    'three folders keep the last two' => ['a/b/c/d.jpg', 'b/c/d.jpg'],
    'a doubled slash leaves no empty segment' => ['2025//11/a.jpg', '2025/11/a.jpg'],
]);

it('limits the stored path to 255 characters and the stored name to 191', function (): void {
    $name = str_repeat('a', 300).'.jpg';

    [$path, $base] = UAMS_MRA_Index::normalize('2025/11/'.$name);

    expect(mb_strlen($path))->toBe(255)
        ->and(mb_strlen($base))->toBe(191)
        ->and($base)->toBe(str_repeat('a', 191));
});

it('counts characters, not bytes, when it limits the stored name', function (): void {
    [, $base] = UAMS_MRA_Index::normalize(str_repeat("\u{e9}", 200).'.jpg');

    expect(mb_strlen($base))->toBe(191);
});

it('returns two empty strings for input that is only separators', function (string $file): void {
    expect(UAMS_MRA_Index::normalize($file))->toBe(['', '']);
})->with(['', '/', ' / ', '?ver=1', '#top']);

it('accepts a value that is not a string', function (): void {
    expect(UAMS_MRA_Index::normalize(null))->toBe(['', ''])
        ->and(UAMS_MRA_Index::normalize(123))->toBe(['123', '123']);
});

it('splits a backslash inside a folder name into segments', function (): void {
    expect(UAMS_MRA_Index::normalize('a\\b/c.jpg'))->toBe(['a/b/c.jpg', 'c.jpg']);
});

it('raises no deprecation for an empty or missing value', function (): void {
    $raised = [];
    set_error_handler(static function (int $level, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });

    try {
        UAMS_MRA_Index::normalize(null);
    } finally {
        restore_error_handler();
    }

    expect($raised)->toBe([]);
});
