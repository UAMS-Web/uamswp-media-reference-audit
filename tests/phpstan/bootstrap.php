<?php

/**
 * Constants PHPStan cannot discover on its own.
 *
 * The plugin header defines UAMS_MRA_URL from plugin_dir_url(), a function
 * call, so PHPStan does not register it from the define() and every use of it
 * in includes/ reports constant.notFound. The value here is a placeholder of
 * the right type; phpstan.neon.dist lists the constant under
 * dynamicConstantNames so the analysis treats it as any string.
 *
 * Analysis only. The plugin never loads this file.
 */
define('UAMS_MRA_URL', 'https://example.org/wp-content/plugins/uamswp-media-reference-audit/');
