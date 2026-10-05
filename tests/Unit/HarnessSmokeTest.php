<?php

/**
 * First unit test pinning harness load for UAMSWP Media Reference Audit.
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Unit;

use UamswpMediaReferenceAudit\Tests\Support\UnitTestCase;

final class HarnessSmokeTest extends UnitTestCase
{
    public function test_harness_loads_plugin_surface(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2).'/uamswp-media-reference-audit.php');
        $this->assertTrue(true);
    }
}
