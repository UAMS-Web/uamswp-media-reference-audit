<?php

/**
 * Base case for every Integration test.
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Support;

use WP_Post;
use WP_UnitTestCase;

/**
 * WordPress' test case, unchanged, on the PHPUnit 9.6 its test suite supports.
 *
 * Loaded by tests/Integration/bootstrap.php once WordPress is up; it cannot be
 * autoloaded earlier, because its parent does not exist until then.
 *
 * The Integration suite runs from its own Composer setup on PHPUnit 9.6, so it
 * needs no adapter: core's expectDeprecated() and checkRequirements() work as
 * shipped. A suite moved onto PHPUnit 10 or later would need one, and that move
 * waits on WordPress supporting it (UAMS-Web/uams-claude-skills#124).
 */
abstract class IntegrationTestCase extends WP_UnitTestCase
{
    /**
     * The fixture factory, returning IDs where core's returns `int|WP_Error`.
     *
     * A failed insert stops the test at the fixture (see `FixtureFactory`).
     */
    protected static function factory(): FixtureFactories
    {
        static $typed = null;

        return $typed ??= new FixtureFactories(parent::factory());
    }

    /**
     * A post the test has just made, read back, so its fields can be read without a null check.
     *
     * Where the post is missing the test fails here, naming the ID, instead of reading a property of `null`.
     */
    protected static function existingPost(int $id): WP_Post
    {
        $post = get_post($id);

        if (! $post instanceof WP_Post) {
            self::fail("Post {$id} does not exist.");
        }

        return $post;
    }
}

// cspell:ignore autoloaded
