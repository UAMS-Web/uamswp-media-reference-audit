<?php

/**
 * Test bootstrap (PHPUnit path, PHP 7.4-safe).
 *
 * The Unit suite runs with no WordPress: WordPress functions are mocked per
 * test with Brain Monkey. The Integration suite boots a real WordPress through
 * wp-phpunit (tests/Integration/bootstrap.php). Each suite runs in its own
 * process, and this file decides which one it is in.
 */

declare(strict_types=1);

/*
 * Runs at most once. phpunit.xml's `bootstrap` loads this file.
 */
if (defined('UAMSWP_MRA_TESTS_BOOTSTRAPPED')) {
    return;
}

define('UAMSWP_MRA_TESTS_BOOTSTRAPPED', true);

require_once dirname(__DIR__).'/vendor/autoload.php';

/**
 * Whether this process runs the Integration suite.
 *
 * True for `--testsuite=Integration` (either spelling) and for a run naming a
 * path under tests/Integration. Anything else is the Unit suite.
 *
 * @param  array  $argv
 * @return bool
 */
function uamswp_mra_tests_want_wordpress(array $argv)
{
    foreach ($argv as $index => $argument) {
        if (! is_string($argument)) {
            continue;
        }

        if ($argument === '--testsuite=Integration') {
            return true;
        }

        if ($argument === '--testsuite' && ($argv[$index + 1] ?? null) === 'Integration') {
            return true;
        }

        if (strpos(str_replace('\\', '/', $argument), 'tests/Integration') !== false) {
            return true;
        }
    }

    return false;
}

$argv = $_SERVER['argv'] ?? [];

if (uamswp_mra_tests_want_wordpress(is_array($argv) ? $argv : [])) {
    require __DIR__.'/Integration/bootstrap.php';

    return;
}

/*
 * The Unit suite.
 *
 * Patchwork first: it can only redefine functions declared in files loaded
 * after it, so loading it before the plugin is what lets a test mock one of the
 * plugin's own functions, not only WordPress'.
 */
require_once dirname(__DIR__).'/vendor/antecedent/patchwork/Patchwork.php';

/*
 * Plugin files open with `defined('ABSPATH') || exit;`. Define it so they load,
 * pointing at a directory that is not a WordPress install: code that requires a
 * core file through ABSPATH fails loudly here, which is the sign it belongs in
 * the Integration suite.
 */
if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir().'/uamswp_mra-tests-no-wordpress/');
}

/*
 * Load the files that DEFINE functions and classes, never the main plugin file:
 * it registers hooks at file scope, and Brain Monkey's add_action() and
 * add_filter() exist only inside a test. Hook registration is tested in the
 * Integration suite, or in a unit test that calls a registering function.
 * Composer-autoloaded classes need no line here.
 *
 * When the main file must load in the Unit suite (definitions and registrations
 * still mixed), wrap that require in a Brain Monkey session and stub every
 * WordPress function it calls at file scope; see the tests skill.
 */

if (! function_exists('plugin_dir_path')) {
    function plugin_dir_path($file)
    {
        return dirname($file).'/';
    }
}

if (! function_exists('plugin_dir_url')) {
    function plugin_dir_url($file)
    {
        return 'https://example.test/wp-content/plugins/'.basename(dirname($file)).'/';
    }
}

if (! function_exists('plugin_basename')) {
    function plugin_basename($file)
    {
        return basename(dirname($file)).'/'.basename($file);
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite()
    {
        return false;
    }
}

if (! function_exists('get_site_option')) {
    function get_site_option($key, $default = false)
    {
        return $default;
    }
}

if (! function_exists('get_option')) {
    function get_option($key, $default = false)
    {
        return $default;
    }
}

if (! function_exists('update_option')) {
    function update_option()
    {
        return true;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters($tag, $value)
    {
        return $value;
    }
}

if (! function_exists('add_action')) {
    function add_action()
    {
        return true;
    }
}

if (! function_exists('add_filter')) {
    function add_filter()
    {
        return true;
    }
}

if (! function_exists('remove_action')) {
    function remove_action()
    {
        return true;
    }
}

if (! function_exists('remove_filter')) {
    function remove_filter()
    {
        return true;
    }
}

if (! function_exists('do_action')) {
    function do_action()
    {
        return true;
    }
}

if (! function_exists('register_activation_hook')) {
    function register_activation_hook()
    {
        return true;
    }
}

if (! function_exists('register_deactivation_hook')) {
    function register_deactivation_hook()
    {
        return true;
    }
}

if (! function_exists('register_uninstall_hook')) {
    function register_uninstall_hook()
    {
        return true;
    }
}

if (! function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain()
    {
        return true;
    }
}

if (! function_exists('is_admin')) {
    function is_admin()
    {
        return false;
    }
}

if (! function_exists('wp_enqueue_script')) {
    function wp_enqueue_script()
    {
        return true;
    }
}

if (! function_exists('wp_enqueue_style')) {
    function wp_enqueue_style()
    {
        return true;
    }
}

if (! function_exists('wp_register_script')) {
    function wp_register_script()
    {
        return true;
    }
}

if (! function_exists('wp_register_style')) {
    function wp_register_style()
    {
        return true;
    }
}

if (! function_exists('wp_localize_script')) {
    function wp_localize_script()
    {
        return true;
    }
}

if (! function_exists('esc_html')) {
    function esc_html($text)
    {
        return $text;
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return $text;
    }
}

if (! function_exists('esc_url')) {
    function esc_url($text)
    {
        return $text;
    }
}

if (! function_exists('__')) {
    function __($text)
    {
        return $text;
    }
}

if (! function_exists('_e')) {
    function _e()
    {
        return true;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__($text)
    {
        return $text;
    }
}

if (! function_exists('esc_attr__')) {
    function esc_attr__($text)
    {
        return $text;
    }
}



// cspell:ignore ABSPATH autoloaded
