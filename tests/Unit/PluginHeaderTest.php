<?php

/**
 * The main plugin file's header and version constant, read as text so no WordPress is needed.
 *
 * Written to work unchanged in a plugin built from the template: the main file is found by
 * its header, and nothing here names the template's slug or prefix.
 *
 * @see uamswp-media-reference-audit.php
 */

declare(strict_types=1);

/**
 * The main plugin file: the one root `*.php` file whose header carries a `Plugin Name:` line.
 *
 * @return array{path: string, source: string}
 */
function plugin_header_test_main_file(): array
{
    $root = dirname(__DIR__, 2);
    $found = [];

    foreach (glob($root.'/*.php') ?: [] as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/^[ \t\/*#@]*Plugin Name:\s+\S/mi', substr($source, 0, 8192)) === 1) {
            $found[] = ['path' => $path, 'source' => $source];
        }
    }

    expect($found)->toHaveCount(1, 'Exactly one root PHP file carries a Plugin Name header.');

    return $found[0];
}

/**
 * A header field as get_file_data() reads it: the pattern WordPress runs over the first 8 KB.
 */
function plugin_header_test_field(string $source, string $field): ?string
{
    $pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*'.preg_quote($field, '/').':\s+(.*)$/mi';

    if (preg_match($pattern, substr($source, 0, 8192), $matches) !== 1) {
        return null;
    }

    return trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $matches[1]));
}

it('names the repository in the Plugin URI header', function (): void {
    $uri = plugin_header_test_field(plugin_header_test_main_file()['source'], 'Plugin URI');

    expect($uri)->toMatch('#^https://github\.com/UAMS-Web/[A-Za-z0-9._-]+$#');
});

it('credits UAMS Web Services as the author in the header', function (): void {
    $source = plugin_header_test_main_file()['source'];

    expect(plugin_header_test_field($source, 'Author'))->toBe('UAMS Web Services')
        ->and(plugin_header_test_field($source, 'Author URI'))->toBe('https://communications.uams.edu/web/');
});

it('writes the Version header as a three-segment release number', function (): void {
    $version = plugin_header_test_field(plugin_header_test_main_file()['source'], 'Version');

    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
});

it('reads the version constant from the header and does not write it a second time', function (): void {
    $source = plugin_header_test_main_file()['source'];

    expect($source)->toMatch("/define\(\s*'[A-Z0-9_]+_VERSION',\s*get_file_data\(__FILE__, \['Version' => 'Version'\]\)\['Version'\]\s*\);/");
    expect(preg_match("/define\(\s*'[A-Z0-9_]*_VERSION',\s*'/", $source))->toBe(0);
});

it('finds the header version within the first 8 KB, as core reads it', function (): void {
    $source = plugin_header_test_main_file()['source'];

    expect(strpos($source, 'Version:'))->toBeLessThan(8192)
        ->and(plugin_header_test_field($source, 'Version'))->not->toBeNull();
});
