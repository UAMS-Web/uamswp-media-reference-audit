<?php

/**
 * UAMS_MRA_Scanner::extract_foreign_upload_urls(), with WordPress mocked.
 *
 * It reads uploads URLs that point at another site, which is what a half-finished
 * migration looks like from the inside.
 *
 * @see includes/class-scanner.php
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\when;

beforeEach(function (): void {
    when('wp_get_upload_dir')->justReturn(['baseurl' => 'https://site.example.edu/wp-content/uploads']);
    when('wp_parse_url')->alias(static fn ($url, $component = -1): string|int|array|false|null => parse_url((string) $url, $component));
    when('home_url')->justReturn('https://site.example.edu');
    when('site_url')->justReturn('https://site.example.edu');
    when('uams_mra_settings')->justReturn(['extra_paths' => '']);
});

it('reports a file served from another host', function (): void {
    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls(
        '<img src="https://old.example.edu/sites/x/wp-content/uploads/2020/01/hero-300x200.jpg">'
    );

    expect($found)->toHaveCount(1)
        ->and($found[0]['host'])->toBe('old.example.edu')
        ->and($found[0]['base_norm'])->toBe('hero.jpg')
        ->and($found[0]['url'])->toBe('https://old.example.edu/sites/x/wp-content/uploads/2020/01/hero-300x200.jpg');
});

it("does not report a file under this site's own uploads", function (): void {
    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls(
        '<img src="https://site.example.edu/wp-content/uploads/2020/01/hero.jpg">'
    );

    expect($found)->toBe([]);
});

it('gives a scheme-relative URL the https scheme', function (): void {
    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls('url(//cdn.example.net/wp-content/uploads/2020/01/b.png)');

    expect($found[0]['url'])->toBe('https://cdn.example.net/wp-content/uploads/2020/01/b.png');
});

it('reads a slash-escaped URL stored inside block JSON', function (): void {
    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls(
        '{"url":"https:\\/\\/old.example.edu\\/wp-content\\/uploads\\/2020\\/01\\/c.jpg"}'
    );

    expect($found)->toHaveCount(1)
        ->and($found[0]['host'])->toBe('old.example.edu')
        ->and($found[0]['base_norm'])->toBe('c.jpg');
});

it('keeps the whole name of a file with dots in it', function (): void {
    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls(
        'https://old.example.edu/wp-content/uploads/2020/01/State-Plan-2.11.25-Final.pdf'
    );

    expect($found[0]['base_norm'])->toBe('state-plan-2.11.25-final.pdf');
});

it('lists a URL once however often it appears', function (): void {
    $url = 'https://old.example.edu/wp-content/uploads/2020/01/a.jpg';

    expect(UAMS_MRA_Scanner::extract_foreign_upload_urls("{$url} {$url} \"{$url}\""))->toHaveCount(1);
});

it("treats a prefix claimed in the settings as this site's own", function (): void {
    when('uams_mra_settings')->justReturn(['extra_paths' => "cdn-offload/media\n"]);

    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls('https://cdn.example.net/cdn-offload/media/wp-content/uploads/2020/01/a.jpg');

    expect($found)->toBe([]);
});

it('returns an empty list for text with no uploads URL in it', function (): void {
    expect(UAMS_MRA_Scanner::extract_foreign_upload_urls('<p>No media here, only https://example.edu/page</p>'))->toBe([]);
});

it('reports another host that serves the same uploads layout as this site', function (): void {
    // Two installs both store files under /wp-content/uploads: the path alone does
    // not make a URL ours, and a migrated site's old host looks exactly like this.
    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls('https://old.example.edu/wp-content/uploads/2020/01/a.jpg');

    expect($found)->toHaveCount(1)
        ->and($found[0]['host'])->toBe('old.example.edu');
});

it("counts a URL on this site's home host as its own when uploads are served from a CDN", function (): void {
    when('wp_get_upload_dir')->justReturn(['baseurl' => 'https://cdn.example.net/wp-content/uploads']);

    $found = UAMS_MRA_Scanner::extract_foreign_upload_urls(
        'https://site.example.edu/wp-content/uploads/2020/01/a.jpg https://cdn.example.net/wp-content/uploads/2020/01/b.jpg'
    );

    expect($found)->toBe([]);
});
