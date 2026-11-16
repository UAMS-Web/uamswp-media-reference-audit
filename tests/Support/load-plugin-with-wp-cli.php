<?php

/**
 * Loads the plugin file with WP_CLI defined, in a process of its own, and prints what the WP_CLI block did.
 *
 * The Integration bootstrap loads the plugin file without WP_CLI, so the block that registers
 * the command never runs there. This script runs the real file with just enough stand-ins for
 * WordPress to get through it, then prints one JSON object: the commands registered and the
 * files the process included.
 *
 * Run by PluginFileTest, from a directory that holds a decoy includes/class-cli.php, so a
 * require path that lost its plugin directory prefix would load the decoy and show.
 *
 * @see uamswp-media-reference-audit.php
 */

declare(strict_types=1);

$plugin = (string) ($argv[1] ?? '');

if (! is_file($plugin)) {
    fwrite(STDERR, "Pass the plugin file as the first argument.\n");

    exit(2);
}

// Names are kept in variables so static analysis does not read these stand-ins as the real WordPress and WP-CLI.
foreach (['ABSPATH' => __DIR__.'/', 'WP_CLI' => true, 'MB_IN_BYTES' => 1024 * 1024] as $name => $value) {
    define($name, $value);
}

/**
 * What the plugin file calls on WP-CLI, recorded.
 */
final class RecordedWpCli
{
    /** @var list<array{string, mixed}> */
    public static array $commands = [];

    public static function add_command(string $name, mixed $callable): void
    {
        self::$commands[] = [$name, $callable];
    }
}

$wpCli = 'WP_CLI';
$listTable = 'WP_List_Table';
class_alias(RecordedWpCli::class, $wpCli);
class_alias(stdClass::class, $listTable);

function get_file_data(string $file, array $headers): array
{
    return ['Version' => '0'];
}

function plugin_dir_path(string $file): string
{
    return dirname($file).'/';
}

function plugin_dir_url(string $file): string
{
    return 'https://example.test/';
}

function register_activation_hook(string $file, mixed $callback): void {}

function register_deactivation_hook(string $file, mixed $callback): void {}

function add_action(string $hook, mixed $callback): void {}

require $plugin;

echo json_encode(['commands' => RecordedWpCli::$commands, 'files' => get_included_files()], JSON_THROW_ON_ERROR);
