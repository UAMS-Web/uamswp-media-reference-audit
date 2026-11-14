<?php

/**
 * The version constant, as WordPress sets it when it loads the plugin.
 *
 * The Unit suite reads the main file as text. This one shows the loaded value:
 * the constant comes from the header through the real get_file_data(), and it is
 * the string the stored database version is compared with.
 *
 * @see uamswp-media-reference-audit.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;

final class PluginVersionTest extends IntegrationTestCase
{
    public function test_the_constant_is_the_version_the_header_names(): void
    {
        $header = get_plugin_data(UAMS_MRA_FILE, false, false)['Version'];

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', UAMS_MRA_VERSION);
        $this->assertSame($header, UAMS_MRA_VERSION);
    }
}
