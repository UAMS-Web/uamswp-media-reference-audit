<?php

/**
 * UAMS_MRA_Index::normalize(), with WordPress mocked.
 *
 * A file reported unreferenced while a page displays it is the one direction this
 * plugin must never be wrong in, so each name that must collapse, and each that
 * must not, is pinned here.
 *
 * @see includes/class-index.php
 */

declare(strict_types=1);

it('collapses a generated size back to the original file name', function (string $file, string $expected): void {
    [, $base_norm] = UAMS_MRA_Index::normalize($file);

    expect($base_norm)->toBe($expected);
})->with([
    'plain size' => ['2025/11/hero-768x432.jpg', 'hero.jpg'],
    'size with a crop marker' => ['2025/11/hero-768x432-cc.jpg', 'hero.jpg'],
    'size under -scaled' => ['2025/11/photo-scaled-1024x768.jpg', 'photo.jpg'],
    '-scaled alone' => ['2025/11/photo-scaled.jpg', 'photo.jpg'],
    '-rotated' => ['2025/11/photo-rotated.jpg', 'photo.jpg'],
    'edited image suffix' => ['2025/11/photo-e1700000000000.jpg', 'photo.jpg'],
    'upper case is folded' => ['2025/11/Hero-768X432.JPG', 'hero.jpg'],
]);

it('keeps every dot of a file name that carries dates or versions', function (string $file, string $expected): void {
    [, $base_norm] = UAMS_MRA_Index::normalize($file);

    expect($base_norm)->toBe($expected);
})->with([
    'dotted date' => ['2025/11/State-Plan-2.11.25-Final.pdf', 'state-plan-2.11.25-final.pdf'],
    'dotted version' => ['2025/11/report-v1.2.3.pdf', 'report-v1.2.3.pdf'],
]);

it('does not collapse a name that only looks like a derivative', function (string $file, string $expected): void {
    [, $base_norm] = UAMS_MRA_Index::normalize($file);

    expect($base_norm)->toBe($expected);
})->with([
    'a trailing marker with a digit' => ['2025/11/map-1920x1080-v2.jpg', 'map-1920x1080-v2.jpg'],
    'a marker longer than four letters' => ['2025/11/map-1920x1080-final.jpg', 'map-1920x1080-final.jpg'],
    'a size in the middle of the name' => ['2025/11/a-300x200-b-c.jpg', 'a-300x200-b-c.jpg'],
]);

it('keeps the year and month folders so a name used twice still matches the right file', function (): void {
    [$path_norm] = UAMS_MRA_Index::normalize('https://example.edu/wp-content/uploads/2025/11/hero-768x432.jpg');

    expect($path_norm)->toBe('2025/11/hero.jpg');
});

it('drops a query string and a fragment before it looks at the name', function (): void {
    [, $base_norm] = UAMS_MRA_Index::normalize('2025/11/hero-768x432.jpg?ver=3#top');

    expect($base_norm)->toBe('hero.jpg');
});

it('returns two empty strings for an empty path', function (): void {
    expect(UAMS_MRA_Index::normalize(''))->toBe(['', '']);
});
