<?php

/**
 * UAMS_MRA_Repoint::rewrite_row() against a real WordPress and database.
 *
 * The Unit suite pins the rewrite with core's helpers stubbed. These tests run it
 * through the real wp_update_post(), update_option() and the meta tables, which is
 * where slashing and serialized data go wrong.
 *
 * @see includes/class-repoint.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use ReflectionMethod;
use UAMS_MRA_Repoint;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;

final class RepointRewriteTest extends IntegrationTestCase
{
    private const OLD_URL = 'https://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf';

    private const NEW_URL = 'https://new.example.edu/wp-content/uploads/2024/05/report.pdf';

    /**
     * Sign in a user who holds unfiltered_html: an administrator, or a super administrator on multisite.
     *
     * The signed-in user decides what wp_update_post() filters, so a fixture saved
     * as anyone else would be changed before the test began.
     */
    public function set_up(): void
    {
        parent::set_up();

        $admin = self::factory()->user->create(['role' => 'administrator']);

        // On multisite only a super administrator holds unfiltered_html.
        if (is_multisite()) {
            grant_super_admin($admin);
        }

        wp_set_current_user($admin);
    }

    /**
     * Run the protected rewrite_row() for one stored row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rewrite(array $row): array
    {

        $method = new ReflectionMethod(UAMS_MRA_Repoint::class, 'rewrite_row');

        $result = $method->invoke(null, (object) ($row + ['url' => self::OLD_URL, 'target_url' => self::NEW_URL, 'source_key' => '']));

        $this->assertIsArray($result);

        return $result;
    }

    /**
     * Store a meta value exactly as given, which add_post_meta() would not do for a serialized-looking string.
     */
    private function insertRawMeta(int $post_id, string $value): int
    {
        global $wpdb;

        $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => 'uams_mra_test', 'meta_value' => $value]);

        return (int) $wpdb->insert_id;
    }

    private function rawMeta(int $meta_id): ?string
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $meta_id));
    }

    public function test_a_rewritten_post_keeps_the_backslashes_of_its_block_json(): void
    {
        $content = '<!-- wp:file {"href":"https:\/\/old.example.edu\/sites\/x\/wp-content\/uploads\/2020\/01\/report.pdf","title":"A \"quoted\" name"} -->';
        $expected = '<!-- wp:file {"href":"https:\/\/new.example.edu\/wp-content\/uploads\/2024\/05\/report.pdf","title":"A \"quoted\" name"} -->';

        // wp_insert_post() unslashes what it is given, so the fixture is slashed on the way in.
        $post_id = self::factory()->post->create(['post_content' => wp_slash($content)]);

        // Confirm the starting text is what the test means.
        $this->assertSame($content, get_post($post_id)->post_content);

        $result = $this->rewrite(['source_type' => 'content', 'source_pk' => $post_id]);

        $this->assertSame('applied', $result['status']);
        $this->assertSame($expected, get_post($post_id)->post_content);
    }

    public function test_a_page_is_left_alone_when_the_user_cannot_save_unfiltered_html(): void
    {
        $content = 'See <iframe src="'.self::OLD_URL.'"></iframe>';

        $post_id = self::factory()->post->create(['post_content' => $content]);

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $method = new ReflectionMethod(UAMS_MRA_Repoint::class, 'rewrite_row');
        $result = $method->invoke(null, (object) ['url' => self::OLD_URL, 'target_url' => self::NEW_URL, 'source_key' => '', 'source_type' => 'content', 'source_pk' => $post_id]);

        $this->assertIsArray($result);
        $this->assertSame('failed', $result['status']);
        $this->assertSame($content, get_post($post_id)->post_content);
    }

    public function test_a_rewritten_post_keeps_the_previous_text_as_a_revision(): void
    {
        $post_id = self::factory()->post->create(['post_content' => 'See '.self::OLD_URL]);

        // Revisions are only kept when the post type supports them and the post was saved once before.
        wp_update_post(['ID' => $post_id, 'post_content' => 'See '.self::OLD_URL.' now']);

        $this->rewrite(['source_type' => 'content', 'source_pk' => $post_id]);

        $texts = array_map(static fn ($revision) => $revision->post_content, wp_get_post_revisions($post_id));

        $this->assertContains('See '.self::OLD_URL.' now', $texts);
        $this->assertSame('See '.self::NEW_URL.' now', get_post($post_id)->post_content);
    }

    public function test_a_serialized_meta_value_is_rewritten_and_still_unserializes(): void
    {
        $post_id = self::factory()->post->create();
        $meta_id = $this->insertRawMeta($post_id, serialize(['url' => self::OLD_URL, 'id' => 7]));

        $result = $this->rewrite(['source_type' => 'postmeta', 'source_pk' => $meta_id, 'source_id' => $post_id]);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(['url' => self::NEW_URL, 'id' => 7], unserialize((string) $this->rawMeta($meta_id)));
    }

    public function test_a_serialized_meta_value_that_cannot_be_read_is_left_as_it_was(): void
    {
        $post_id = self::factory()->post->create();
        $corrupt = 'a:1:{s:3:"url";s:5:"'.self::OLD_URL.'";}';
        $meta_id = $this->insertRawMeta($post_id, $corrupt);

        $result = $this->rewrite(['source_type' => 'postmeta', 'source_pk' => $meta_id, 'source_id' => $post_id]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame($corrupt, $this->rawMeta($meta_id));
    }

    public function test_an_option_holding_an_array_is_rewritten_in_place(): void
    {
        update_option('uams_mra_test_option', ['logo' => self::OLD_URL, 'keep' => 'x']);

        $result = $this->rewrite(['source_type' => 'option', 'source_pk' => 0, 'source_key' => 'uams_mra_test_option']);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(['logo' => self::NEW_URL, 'keep' => 'x'], get_option('uams_mra_test_option'));
    }

    public function test_an_option_holding_an_object_is_rewritten_and_reported_applied(): void
    {
        $object = new \stdClass;
        $object->logo = self::OLD_URL;
        update_option('uams_mra_test_object', $object);

        $result = $this->rewrite(['source_type' => 'option', 'source_pk' => 0, 'source_key' => 'uams_mra_test_object']);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(self::NEW_URL, get_option('uams_mra_test_object')->logo);
    }

    public function test_an_option_whose_url_sits_in_a_private_property_is_failed_not_skipped(): void
    {
        update_option('uams_mra_test_private', new UamsMraPrivateHolder(self::OLD_URL));

        $result = $this->rewrite(['source_type' => 'option', 'source_pk' => 0, 'source_key' => 'uams_mra_test_private']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(self::OLD_URL, get_option('uams_mra_test_private')->url());
    }

    public function test_a_meta_value_holding_a_class_that_is_not_loaded_is_left_untouched_and_failed(): void
    {
        $post_id = self::factory()->post->create();
        $value = 'O:11:"MissingKind":1:{s:3:"url";s:'.strlen(self::OLD_URL).':"'.self::OLD_URL.'";}';
        $meta_id = $this->insertRawMeta($post_id, $value);

        $result = $this->rewrite(['source_type' => 'postmeta', 'source_pk' => $meta_id, 'source_id' => $post_id]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame($value, $this->rawMeta($meta_id));
    }

    public function test_a_meta_value_with_one_url_inside_an_object_and_one_outside_is_not_half_written(): void
    {
        $post_id = self::factory()->post->create();
        $inner = 'O:11:"MissingKind":1:{s:3:"url";s:'.strlen(self::OLD_URL).':"'.self::OLD_URL.'";}';
        $value = 'a:2:{s:3:"one";s:'.strlen(self::OLD_URL).':"'.self::OLD_URL.'";s:3:"two";'.$inner.'}';
        $meta_id = $this->insertRawMeta($post_id, $value);

        $result = $this->rewrite(['source_type' => 'postmeta', 'source_pk' => $meta_id, 'source_id' => $post_id]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame($value, $this->rawMeta($meta_id));
    }

    public function test_a_meta_value_with_no_url_in_it_is_still_skipped(): void
    {
        $post_id = self::factory()->post->create();
        $meta_id = $this->insertRawMeta($post_id, serialize(['other' => 'https://elsewhere.example/a.jpg']));

        $result = $this->rewrite(['source_type' => 'postmeta', 'source_pk' => $meta_id, 'source_id' => $post_id]);

        $this->assertSame('skipped', $result['status']);
    }

    public function test_a_revision_is_never_rewritten(): void
    {
        $post_id = self::factory()->post->create(['post_content' => self::OLD_URL]);

        $result = $this->rewrite(['source_type' => 'revision', 'source_pk' => $post_id]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(self::OLD_URL, get_post($post_id)->post_content);
    }
}

/**
 * An object whose URL sits in a private property, out of reach of the rewrite.
 */
final class UamsMraPrivateHolder
{
    public function __construct(private string $url) {}

    public function url(): string
    {
        return $this->url;
    }
}

// cspell:ignore unserializes
