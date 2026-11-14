<?php

/**
 * UAMS_MRA_Repoint::download_capped(): the size limit holds during the transfer.
 *
 * The request is answered through `pre_http_request`, which is given the same
 * arguments the HTTP API would send, so the test sees what limit was asked for
 * and can hand back a body of any size.
 *
 * @see includes/class-repoint.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use ReflectionMethod;
use UAMS_MRA_Repoint;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;
use WP_Error;

final class RepointDownloadTest extends IntegrationTestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $sent = [];

    /**
     * Answer the next request with a body of the given size and status.
     */
    private function answerWith(int $bytes, int $status = 200): void
    {
        add_filter('pre_http_request', function ($pre, array $args) use ($bytes, $status): array {
            $this->sent = $args;

            file_put_contents((string) $args['filename'], str_repeat('x', $bytes));

            return ['headers' => [], 'body' => '', 'response' => ['code' => $status, 'message' => ''], 'cookies' => [], 'filename' => $args['filename']];
        }, 10, 2);
    }

    private function download(int $max): string|WP_Error
    {
        $method = new ReflectionMethod(UAMS_MRA_Repoint::class, 'download_capped');

        $result = $method->invoke(null, 'https://old.example.edu/wp-content/uploads/a.bin', 10, $max);

        $this->assertTrue(is_string($result) || $result instanceof WP_Error);

        return $result;
    }

    public function test_the_request_asks_for_one_byte_past_the_limit(): void
    {
        $this->answerWith(10);

        $this->download(100);

        $this->assertSame(101, $this->sent['limit_response_size']);
        $this->assertTrue($this->sent['stream']);
    }

    public function test_a_file_within_the_limit_is_kept_and_its_path_returned(): void
    {
        $this->answerWith(100);

        $path = $this->download(100);

        $this->assertIsString($path);
        $this->assertSame(100, filesize($path));

        wp_delete_file($path);
    }

    public function test_a_file_over_the_limit_is_refused_and_nothing_is_left_behind(): void
    {
        $this->answerWith(101);

        $result = $this->download(100);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('toobig', $result->get_error_code());
        $this->assertFileDoesNotExist((string) $this->sent['filename']);
    }

    public function test_a_status_other_than_200_is_a_failed_fetch_and_leaves_no_file(): void
    {
        $this->answerWith(5, 404);

        $result = $this->download(100);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('fetchfailed', $result->get_error_code());
        $this->assertFileDoesNotExist((string) $this->sent['filename']);
    }

    public function test_a_transport_error_is_a_failed_fetch_and_leaves_no_file(): void
    {
        add_filter('pre_http_request', function ($pre, array $args): WP_Error {
            $this->sent = $args;

            return new WP_Error('http_request_failed', 'connection refused');
        }, 10, 2);

        $result = $this->download(100);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('fetchfailed', $result->get_error_code());
        $this->assertSame('connection refused', $result->get_error_message());
        $this->assertFileDoesNotExist((string) $this->sent['filename']);
    }
}
