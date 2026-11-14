<?php

/**
 * The floor lint: parse every shipped PHP file with the PHP that runs this script.
 *
 * Run it under the supported floor (PHP 8.2) so syntax the floor cannot parse
 * fails here: `LINT_PHP=/path/to/php8.2 composer lint:floor`. "Shipped" is what the
 * download keep-list in .gitattributes leaves in a branch archive.
 *
 * Exits 1 on a parse error, and 2 when it found no file to lint (a lint that
 * checked nothing proves nothing).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$php = getenv('LINT_PHP') ?: PHP_BINARY;

$list = [];
$status = 0;
exec('git -C '.escapeshellarg($root).' archive --worktree-attributes --format=tar HEAD | tar -t', $list, $status);

if ($status !== 0) {
    fwrite(STDERR, "Could not list the download archive.\n");
    exit(2);
}

$files = array_values(array_filter($list, static fn (string $path): bool => str_ends_with($path, '.php')));

if ($files === []) {
    fwrite(STDERR, "No shipped PHP files found; the keep-list may be empty.\n");
    exit(2);
}

$failed = 0;

foreach ($files as $file) {
    $output = [];
    exec(escapeshellarg($php).' -l '.escapeshellarg($root.'/'.$file).' 2>&1', $output, $code);

    if ($code !== 0) {
        $failed++;
        echo implode("\n", $output)."\n";
    }
}

echo sprintf("%d shipped PHP file(s) linted with %s, %d failed.\n", count($files), $php, $failed);

exit($failed > 0 ? 1 : 0);
