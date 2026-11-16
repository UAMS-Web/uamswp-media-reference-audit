<?php

/**
 * A fixture factory that hands back IDs.
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Support;

use Closure;
use RuntimeException;
use WP_Error;
use WP_UnitTest_Factory;
use WP_UnitTest_Factory_For_Attachment;
use WP_UnitTest_Factory_For_Thing;

/**
 * One fixture type of WordPress' test factory, returning `int` where core returns `int|WP_Error`.
 *
 * Core's factories return a `WP_Error` when an insert fails, and the tests pass the result straight
 * to functions that take an ID. A failed fixture used to flow on as an object and fail somewhere
 * unrelated; here it stops the test where the fixture was made, naming the error.
 */
final class FixtureFactory
{
    /**
     * @param  Closure(): WP_UnitTest_Factory_For_Thing  $inner  Resolved on use: core builds the blog factory on multisite only.
     */
    public function __construct(private readonly Closure $inner) {}

    /**
     * Create one object and return its ID.
     *
     * @param  array<string, mixed>  $args  The fields core's factory takes for this type.
     */
    public function create(array $args = []): int
    {
        return self::id(($this->inner)()->create($args));
    }

    /**
     * Unwrap a factory result, or stop the test on a failed insert.
     */
    public static function id(int|WP_Error $result): int
    {
        if ($result instanceof WP_Error) {
            throw new RuntimeException('A test fixture could not be created: '.$result->get_error_message());
        }

        return $result;
    }
}

/**
 * The attachment factory, which also uploads a real file.
 */
final class AttachmentFixtureFactory
{
    /**
     * @param  Closure(): WP_UnitTest_Factory_For_Attachment  $inner  Resolved on use.
     */
    public function __construct(private readonly Closure $inner) {}

    /**
     * Create one attachment and return its ID.
     *
     * @param  array<string, mixed>  $args  The fields core's factory takes for an attachment.
     */
    public function create(array $args = []): int
    {
        return FixtureFactory::id(($this->inner)()->create($args));
    }

    /**
     * Upload a file into the media library and return the attachment ID.
     */
    public function create_upload_object(string $file, int $parent_post_id = 0): int
    {
        return FixtureFactory::id(($this->inner)()->create_upload_object($file, $parent_post_id));
    }
}

/**
 * The fixture types the Integration suite builds, each returning IDs.
 */
final class FixtureFactories
{
    public readonly FixtureFactory $post;

    public readonly FixtureFactory $user;

    public readonly FixtureFactory $term;

    public readonly FixtureFactory $comment;

    public readonly FixtureFactory $blog;

    public readonly AttachmentFixtureFactory $attachment;

    public function __construct(WP_UnitTest_Factory $factory)
    {
        $this->post = new FixtureFactory(static fn (): WP_UnitTest_Factory_For_Thing => $factory->post);
        $this->user = new FixtureFactory(static fn (): WP_UnitTest_Factory_For_Thing => $factory->user);
        $this->term = new FixtureFactory(static fn (): WP_UnitTest_Factory_For_Thing => $factory->term);
        $this->comment = new FixtureFactory(static fn (): WP_UnitTest_Factory_For_Thing => $factory->comment);
        $this->blog = new FixtureFactory(static fn (): WP_UnitTest_Factory_For_Thing => $factory->blog);
        $this->attachment = new AttachmentFixtureFactory(static fn (): WP_UnitTest_Factory_For_Attachment => $factory->attachment);
    }
}
