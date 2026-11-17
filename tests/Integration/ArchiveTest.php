<?php

/**
 * UAMS_MRA_Archive against a real WordPress, a real uploads folder and real zip files.
 *
 * A job is kept in user meta and grows one part at a time, so the job, the zips and
 * the manifest inside each zip are all read back from where they were stored.
 *
 * @see includes/class-archive.php
 */

declare(strict_types=1);

namespace UamswpMediaReferenceAudit\Tests\Integration;

use UAMS_MRA_Archive;
use UAMS_MRA_Triage;
use UamswpMediaReferenceAudit\Tests\Support\IntegrationTestCase;
use WP_Error;
use ZipArchive;

/**
 * @phpstan-import-type ArchiveJob from UAMS_MRA_Archive
 */
final class ArchiveTest extends IntegrationTestCase
{
    /**
     * Files made on disk by a test, removed again afterwards.
     *
     * @var list<string>
     */
    private array $files = [];

    public function set_up(): void
    {
        parent::set_up();

        $this->wipe();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function tear_down(): void
    {
        $this->wipe();

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->files = [];

        parent::tear_down();
    }

    /**
     * Remove the archive folder and anything standing where it goes.
     */
    private function wipe(): void
    {
        $base = UAMS_MRA_Archive::base_dir();

        if (is_file($base)) {
            unlink($base);

            return;
        }

        UAMS_MRA_Archive::cleanup_all();

        foreach (['/.htaccess', '/index.php'] as $name) {
            if (is_file($base.$name)) {
                unlink($base.$name);
            }
        }

        if (is_dir($base)) {
            rmdir($base);
        }
    }

    /**
     * Make an attachment whose file really exists in uploads.
     *
     * @param  array<string, mixed>  $post
     */
    private function attachment(string $name, string $content, array $post = []): int
    {
        $upload = wp_upload_bits($name, null, $content);
        $this->assertSame(false, $upload['error'], 'the fixture file could not be written');
        $this->files[] = $upload['file'];

        $type = wp_check_filetype($name);

        return (int) wp_insert_attachment(
            array_merge(['post_title' => $name, 'post_mime_type' => $type['type'] ?: 'application/octet-stream', 'post_status' => 'inherit'], $post),
            $upload['file']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function job(): array
    {
        $job = UAMS_MRA_Archive::get_job();
        $this->assertIsArray($job);

        return $job;
    }

    /**
     * Run steps until the job is done, returning the final job.
     *
     * @return ArchiveJob
     */
    private function finish(int $limit = 20): array
    {
        for ($i = 0; $i < $limit; $i++) {
            $job = UAMS_MRA_Archive::step();
            $this->assertIsArray($job);

            if ($job['done']) {
                /** @var ArchiveJob $job */
                return $job;
            }
        }

        $this->fail('the job did not finish within '.$limit.' steps');
    }

    /**
     * @param  ArchiveJob  $job
     * @return list<string>
     */
    private function entries(array $job, int $part): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($job['dir'].'/'.UAMS_MRA_Archive::part_name($part)));
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();
        sort($names);

        return $names;
    }

    /**
     * @param  ArchiveJob  $job
     * @return list<list<string|null>>
     */
    private function manifest(array $job, int $part): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($job['dir'].'/'.UAMS_MRA_Archive::part_name($part)));
        $csv = $zip->getFromName('manifest.csv');
        $zip->close();
        $this->assertIsString($csv);

        $rows = [];

        foreach (preg_split('#\r\n|\n#', trim($csv)) ?: [] as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }

    /**
     * Hand a value over with its type hidden, as a caller outside this code may.
     */
    private function loose(mixed $value): mixed
    {
        return $value;
    }

    private function relative(int $id): string
    {
        $uploads = wp_get_upload_dir();

        return substr((string) get_attached_file($id), strlen(trailingslashit($uploads['basedir'])));
    }

    public function test_zipping_is_available_here(): void
    {
        $this->assertTrue(UAMS_MRA_Archive::available());
    }

    public function test_a_part_may_grow_to_200_megabytes_unless_a_filter_says_otherwise(): void
    {
        $this->assertSame(200 * MB_IN_BYTES, UAMS_MRA_Archive::part_max_bytes());

        add_filter('uams_mra_archive_part_bytes', static fn (): string => '5000');

        $this->assertSame(5000, UAMS_MRA_Archive::part_max_bytes());
    }

    public function test_a_step_may_add_25_files_or_48_megabytes_unless_a_filter_says_otherwise(): void
    {
        $this->assertSame(['bytes' => 48 * MB_IN_BYTES, 'files' => 25], UAMS_MRA_Archive::step_budget());

        add_filter('uams_mra_archive_step_budget', static fn (): array => ['bytes' => 10, 'files' => 2]);

        $this->assertSame(['bytes' => 10, 'files' => 2], UAMS_MRA_Archive::step_budget());
    }

    public function test_a_step_budget_that_a_filter_returns_as_a_single_value_is_still_an_array(): void
    {
        add_filter('uams_mra_archive_step_budget', static fn (): string => 'none');

        $this->assertSame(['none'], UAMS_MRA_Archive::step_budget());
    }

    public function test_every_job_lives_in_a_folder_of_its_own_under_uploads(): void
    {
        $uploads = wp_get_upload_dir();

        $this->assertSame(trailingslashit($uploads['basedir']).'uams-mra-archives', UAMS_MRA_Archive::base_dir());
    }

    public function test_part_names_carry_the_host_and_the_part_number(): void
    {
        $host = sanitize_file_name((string) wp_parse_url(home_url(), PHP_URL_HOST));

        $this->assertSame('uams-media-'.$host.'-part-3.zip', UAMS_MRA_Archive::part_name(3));
        /** @var int $three */
        $three = $this->loose('3');
        $this->assertSame('uams-media-'.$host.'-part-3.zip', UAMS_MRA_Archive::part_name($three));
    }

    public function test_there_is_no_job_until_one_is_started(): void
    {
        $this->assertNull(UAMS_MRA_Archive::get_job());

        update_user_meta(get_current_user_id(), UAMS_MRA_Archive::META_KEY, 'not a job');
        $this->assertNull(UAMS_MRA_Archive::get_job());

        update_user_meta(get_current_user_id(), UAMS_MRA_Archive::META_KEY, ['dir' => '/x']);
        $this->assertNull(UAMS_MRA_Archive::get_job());

        update_user_meta(get_current_user_id(), UAMS_MRA_Archive::META_KEY, ['id' => '']);
        $this->assertNull(UAMS_MRA_Archive::get_job());
    }

    public function test_a_job_belongs_to_the_user_who_started_it(): void
    {
        $id = $this->attachment('mine.txt', 'x');
        UAMS_MRA_Archive::start([$id]);
        $this->assertIsArray(UAMS_MRA_Archive::get_job());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->assertNull(UAMS_MRA_Archive::get_job());
    }

    public function test_starting_with_nothing_selected_is_refused(): void
    {
        // A null entry is passed on purpose, to show it is refused like a 0, though the declared parameter type does not admit one.
        /** @var list<array<int, mixed>> $selections */
        $selections = [[], [0], ['0', '', 'abc'], [0, null]];

        foreach ($selections as $selection) {
            /** @var array<int, int|string> $ids */
            $ids = $selection;
            $job = UAMS_MRA_Archive::start($ids);

            $this->assertInstanceOf(WP_Error::class, $job);
            $this->assertSame('uams_mra_empty', $job->get_error_code());
            $this->assertSame('Nothing is selected, so there is nothing to archive.', $job->get_error_message());
        }

        $this->assertNull(UAMS_MRA_Archive::get_job());
        $this->assertDirectoryDoesNotExist(UAMS_MRA_Archive::base_dir());
    }

    public function test_starting_makes_a_job_with_its_folder_and_keeps_it(): void
    {
        $before = time();
        $job = UAMS_MRA_Archive::start(['5', 5, 0, 'x', 7, '9abc']);

        $this->assertIsArray($job);
        $this->assertSame([5, 7, 9], $job['ids']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', $job['id']);
        $this->assertSame(UAMS_MRA_Archive::base_dir().'/'.$job['id'], $job['dir']);
        $this->assertDirectoryExists($job['dir']);
        $this->assertSame(0, $job['cursor']);
        $this->assertSame(1, $job['part']);
        $this->assertSame([], $job['parts']);
        $this->assertSame(0, $job['files']);
        $this->assertSame(0, $job['bytes']);
        $this->assertSame([], $job['skipped']);
        $this->assertFalse($job['done']);
        $this->assertGreaterThanOrEqual($before, $job['created']);
        $this->assertLessThanOrEqual(time(), $job['created']);
        $this->assertSame($job, UAMS_MRA_Archive::get_job());
    }

    public function test_two_jobs_get_different_folders_and_the_first_is_thrown_away(): void
    {
        $first = UAMS_MRA_Archive::start([1]);
        $this->assertIsArray($first);
        file_put_contents($first['dir'].'/leftover.txt', 'x');

        $second = UAMS_MRA_Archive::start([2]);

        $this->assertIsArray($second);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertDirectoryDoesNotExist($first['dir']);
        $this->assertDirectoryExists($second['dir']);
        $this->assertSame([2], $this->job()['ids']);
    }

    public function test_the_archive_folder_is_kept_off_the_web(): void
    {
        UAMS_MRA_Archive::start([1]);
        $base = UAMS_MRA_Archive::base_dir();

        $this->assertSame("Order Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n", file_get_contents($base.'/.htaccess'));
        $this->assertSame("<?php\n// Silence is golden.\n", file_get_contents($base.'/index.php'));
    }

    public function test_a_guard_file_that_is_already_there_is_left_alone(): void
    {
        wp_mkdir_p(UAMS_MRA_Archive::base_dir());
        file_put_contents(UAMS_MRA_Archive::base_dir().'/.htaccess', 'mine');
        file_put_contents(UAMS_MRA_Archive::base_dir().'/index.php', 'also mine');

        UAMS_MRA_Archive::start([1]);

        $this->assertSame('mine', file_get_contents(UAMS_MRA_Archive::base_dir().'/.htaccess'));
        $this->assertSame('also mine', file_get_contents(UAMS_MRA_Archive::base_dir().'/index.php'));
    }

    public function test_starting_fails_when_the_folder_cannot_be_made(): void
    {
        $base = UAMS_MRA_Archive::base_dir();
        wp_mkdir_p(dirname($base));
        file_put_contents($base, 'a file stands where the folder goes');

        $job = UAMS_MRA_Archive::start([1]);

        $this->assertInstanceOf(WP_Error::class, $job);
        $this->assertSame('uams_mra_mkdir', $job->get_error_code());
        $this->assertSame('Could not create the archive folder inside uploads. Check the folder is writable.', $job->get_error_message());
        $this->assertNull(UAMS_MRA_Archive::get_job());
    }

    public function test_stepping_without_a_job_is_refused(): void
    {
        $result = UAMS_MRA_Archive::step();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('uams_mra_no_job', $result->get_error_code());
        $this->assertSame('No archive is being built.', $result->get_error_message());
    }

    public function test_a_finished_job_is_returned_without_touching_anything(): void
    {
        $id = $this->attachment('done.txt', 'x');
        UAMS_MRA_Archive::start([$id]);
        $done = $this->finish();
        $zip = $done['dir'].'/'.UAMS_MRA_Archive::part_name(1);
        $modified = filemtime($zip);
        touch($zip, $modified - 100);
        clearstatcache();

        $again = UAMS_MRA_Archive::step();

        $this->assertSame($done, $again);
        clearstatcache();
        $this->assertSame($modified - 100, filemtime($zip));
    }

    public function test_a_step_fails_when_the_zip_cannot_be_opened(): void
    {
        $id = $this->attachment('blocked.txt', 'x');
        $job = UAMS_MRA_Archive::start([$id]);
        $this->assertIsArray($job);
        mkdir($job['dir'].'/'.UAMS_MRA_Archive::part_name(1));

        $result = UAMS_MRA_Archive::step();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('uams_mra_zip_open', $result->get_error_code());
        $this->assertSame('Could not open the archive file for writing.', $result->get_error_message());
        $this->assertSame(0, $this->job()['cursor']);
        $this->assertFalse($this->job()['done']);
    }

    public function test_an_archive_of_a_set_holds_exactly_those_files_and_a_manifest(): void
    {
        $a = $this->attachment('alpha.txt', str_repeat('alpha ', 50));
        $b = $this->attachment('beta.txt', str_repeat('beta ', 70));
        $c = $this->attachment('gamma.txt', 'gamma');
        $left_out = $this->attachment('delta.txt', 'delta');
        UAMS_MRA_Archive::start([$a, $b, $c]);

        $job = $this->finish();

        $this->assertTrue($job['done']);
        $this->assertSame(3, $job['files']);
        $this->assertSame(300 + 350 + 5, $job['bytes']);
        $this->assertSame(3, $job['cursor']);
        $this->assertSame([], $job['skipped']);
        $this->assertSame(
            [$this->relative($a), $this->relative($b), $this->relative($c), 'manifest.csv'],
            array_values(array_filter($this->entries($job, 1), static fn (string $n): bool => true))
        );
        $this->assertNotContains($this->relative($left_out), $this->entries($job, 1));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($job['dir'].'/'.UAMS_MRA_Archive::part_name(1)));
        $this->assertSame(str_repeat('beta ', 70), $zip->getFromName($this->relative($b)));
        $zip->close();
    }

    public function test_the_job_records_each_part_with_its_size_and_count(): void
    {
        $a = $this->attachment('one.txt', 'one');
        $b = $this->attachment('two.txt', 'two');
        UAMS_MRA_Archive::start([$a, $b]);

        $job = $this->finish();

        $path = $job['dir'].'/'.UAMS_MRA_Archive::part_name(1);
        $this->assertSame(
            [1 => ['part' => 1, 'name' => UAMS_MRA_Archive::part_name(1), 'bytes' => filesize($path), 'files' => 2]],
            $job['parts']
        );
    }

    public function test_a_missing_file_is_reported_rather_than_skipped_silently(): void
    {
        $present = $this->attachment('present.txt', 'here');
        $gone = $this->attachment('gone.txt', 'soon gone');
        unlink((string) get_attached_file($gone));
        $no_file = (int) wp_insert_attachment(['post_title' => 'no file', 'post_status' => 'inherit', 'post_mime_type' => 'text/plain']);
        UAMS_MRA_Archive::start([$gone, $present, $no_file, 999999]);

        $job = $this->finish();

        $this->assertSame([$gone, $no_file, 999999], $job['skipped']);
        $this->assertSame(1, $job['files']);
        $this->assertSame(4, $job['bytes']);
        $this->assertSame(4, $job['cursor']);
        $this->assertSame([$this->relative($present), 'manifest.csv'], $this->entries($job, 1));
        $this->assertCount(2, $this->manifest($job, 1));
    }

    public function test_a_job_whose_files_are_all_missing_still_finishes(): void
    {
        $gone = $this->attachment('lost.txt', 'x');
        unlink((string) get_attached_file($gone));
        UAMS_MRA_Archive::start([$gone]);

        $job = $this->finish();

        $this->assertTrue($job['done']);
        $this->assertSame([$gone], $job['skipped']);
        $this->assertSame(0, $job['files']);
        $this->assertSame(['attachment_id', 'path_in_zip', 'bytes', 'title', 'mime', 'uploaded', 'parent_id', 'alt_text', 'decision', 'note'], $this->manifest($job, 1)[0]);
    }

    public function test_a_step_stops_at_the_file_budget_and_the_next_one_carries_on(): void
    {
        $ids = [];

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $ids[] = $this->attachment($name.'.txt', $name);
        }

        add_filter('uams_mra_archive_step_budget', static fn (): array => ['bytes' => MB_IN_BYTES, 'files' => 2]);
        UAMS_MRA_Archive::start($ids);

        $first = UAMS_MRA_Archive::step();
        $this->assertIsArray($first);
        $this->assertSame(2, $first['cursor']);
        $this->assertSame(2, $first['files']);
        $this->assertFalse($first['done']);

        $second = UAMS_MRA_Archive::step();
        $this->assertIsArray($second);
        $this->assertSame(4, $second['cursor']);
        $this->assertFalse($second['done']);

        $third = UAMS_MRA_Archive::step();
        $this->assertIsArray($third);
        $this->assertSame(5, $third['cursor']);
        $this->assertTrue($third['done']);
        $this->assertSame(5, $third['files']);
        $this->assertSame(1, $third['part']);
        $this->assertCount(6, $this->manifest($third, 1));
    }

    public function test_the_manifest_in_a_part_keeps_every_earlier_row_across_steps(): void
    {
        $a = $this->attachment('first.txt', 'first');
        $b = $this->attachment('second.txt', 'second');
        add_filter('uams_mra_archive_step_budget', static fn (): array => ['bytes' => MB_IN_BYTES, 'files' => 1]);
        UAMS_MRA_Archive::start([$a, $b]);

        UAMS_MRA_Archive::step();
        $job = UAMS_MRA_Archive::step();

        $this->assertIsArray($job);
        $rows = $this->manifest($job, 1);
        $this->assertSame([(string) $a, (string) $b], [$rows[1][0], $rows[2][0]]);
        $this->assertCount(3, $rows);
        $this->assertSame(2, $job['parts'][1]['files']);
    }

    public function test_a_step_stops_at_the_byte_budget(): void
    {
        $ids = [];

        foreach (['a', 'b', 'c'] as $name) {
            $ids[] = $this->attachment($name.'.txt', str_repeat($name, 400));
        }

        add_filter('uams_mra_archive_step_budget', static fn (): array => ['bytes' => 500, 'files' => 25]);
        UAMS_MRA_Archive::start($ids);

        $first = UAMS_MRA_Archive::step();

        $this->assertIsArray($first);
        $this->assertSame(2, $first['cursor']);
        $this->assertSame(800, $first['bytes']);
        $this->assertFalse($first['done']);
    }

    public function test_a_step_stops_once_the_bytes_added_reach_the_budget_exactly(): void
    {
        $ids = [];

        foreach (['a', 'b'] as $name) {
            $ids[] = $this->attachment($name.'.txt', str_repeat($name, 400));
        }

        add_filter('uams_mra_archive_step_budget', static fn (): array => ['bytes' => 400, 'files' => 25]);
        UAMS_MRA_Archive::start($ids);

        $first = UAMS_MRA_Archive::step();

        $this->assertIsArray($first);
        $this->assertSame(1, $first['cursor']);
        $this->assertSame(400, $first['bytes']);
    }

    public function test_a_missing_file_does_not_end_the_step_that_meets_it(): void
    {
        $gone = $this->attachment('gone.txt', 'x');
        unlink((string) get_attached_file($gone));
        $present = $this->attachment('present.txt', 'here');
        UAMS_MRA_Archive::start([$gone, $present]);

        $job = UAMS_MRA_Archive::step();

        $this->assertIsArray($job);
        $this->assertTrue($job['done']);
        $this->assertSame(2, $job['cursor']);
        $this->assertSame([$gone], $job['skipped']);
        $this->assertSame(1, $job['files']);
    }

    public function test_a_step_of_exactly_the_file_budget_ends_the_job_when_nothing_is_left(): void
    {
        $a = $this->attachment('x.txt', 'x');
        $b = $this->attachment('y.txt', 'y');
        add_filter('uams_mra_archive_step_budget', static fn (): array => ['bytes' => MB_IN_BYTES, 'files' => 2]);
        UAMS_MRA_Archive::start([$a, $b]);

        $job = UAMS_MRA_Archive::step();

        $this->assertIsArray($job);
        $this->assertTrue($job['done']);
    }

    public function test_a_full_part_rolls_over_to_the_next_and_each_part_keeps_its_own_files(): void
    {
        $ids = [];

        foreach (['a', 'b', 'c'] as $name) {
            $ids[] = $this->attachment($name.'.jpg', random_bytes(600));
        }

        add_filter('uams_mra_archive_part_bytes', static fn (): int => 1000);
        UAMS_MRA_Archive::start($ids);

        $job = $this->finish();

        $this->assertSame(3, $job['part']);
        $this->assertSame([1, 2, 3], array_keys($job['parts']));
        $this->assertSame([$this->relative($ids[0]), 'manifest.csv'], $this->entries($job, 1));
        $this->assertSame([$this->relative($ids[1]), 'manifest.csv'], $this->entries($job, 2));
        $this->assertSame([$this->relative($ids[2]), 'manifest.csv'], $this->entries($job, 3));
        $this->assertSame(3, $job['files']);
        $this->assertSame(1800, $job['bytes']);

        foreach ([1, 2, 3] as $part) {
            $this->assertSame(1, $job['parts'][$part]['files']);
            $this->assertSame($part, $job['parts'][$part]['part']);
            $this->assertSame(UAMS_MRA_Archive::part_name($part), $job['parts'][$part]['name']);
        }
    }

    public function test_a_part_takes_files_until_the_next_would_pass_the_cap(): void
    {
        $ids = [];

        foreach (['a', 'b', 'c'] as $name) {
            $ids[] = $this->attachment($name.'.jpg', random_bytes(300));
        }

        // Three files of 300 bytes fit under a cap that the manifest and zip overhead would pass if counted wrongly.
        add_filter('uams_mra_archive_part_bytes', static fn (): int => 900);
        UAMS_MRA_Archive::start($ids);

        $job = $this->finish();

        $this->assertSame(1, $job['part']);
        $this->assertSame([$this->relative($ids[0]), $this->relative($ids[1]), $this->relative($ids[2]), 'manifest.csv'], $this->entries($job, 1));
    }

    public function test_a_file_larger_than_a_part_still_goes_into_an_empty_one(): void
    {
        $big = $this->attachment('big.jpg', random_bytes(600));
        add_filter('uams_mra_archive_part_bytes', static fn (): int => 10);
        UAMS_MRA_Archive::start([$big]);

        $job = $this->finish();

        $this->assertSame(1, $job['part']);
        $this->assertSame(1, $job['files']);
        $this->assertSame([$this->relative($big), 'manifest.csv'], $this->entries($job, 1));
    }

    public function test_a_part_that_refuses_a_file_on_a_later_step_rolls_over_without_stalling(): void
    {
        $a = $this->attachment('a.jpg', random_bytes(600));
        $b = $this->attachment('b.jpg', random_bytes(600));
        add_filter('uams_mra_archive_part_bytes', static fn (): int => 1000);
        add_filter('uams_mra_archive_step_budget', static fn (): array => ['bytes' => MB_IN_BYTES, 'files' => 1]);
        UAMS_MRA_Archive::start([$a, $b]);

        $first = UAMS_MRA_Archive::step();
        $this->assertIsArray($first);
        $this->assertSame(1, $first['part']);

        $second = UAMS_MRA_Archive::step();
        $this->assertIsArray($second);
        // The second file does not fit beside the first, so this step adds nothing and moves on to part 2.
        $this->assertSame(2, $second['part']);
        $this->assertSame(1, $second['cursor']);
        $this->assertFalse($second['done']);

        $third = UAMS_MRA_Archive::step();
        $this->assertIsArray($third);
        $this->assertSame(2, $third['part']);
        $this->assertTrue($third['done']);
    }

    public function test_the_parts_of_a_job_are_kept_in_order(): void
    {
        $id = $this->attachment('late.txt', 'x');
        $job = UAMS_MRA_Archive::start([$id]);
        $this->assertIsArray($job);
        $job['parts'] = [3 => ['part' => 3, 'name' => 'three', 'bytes' => 0, 'files' => 0]];
        update_user_meta(get_current_user_id(), UAMS_MRA_Archive::META_KEY, $job);

        $done = UAMS_MRA_Archive::step();

        $this->assertIsArray($done);
        $this->assertSame([1, 3], array_keys($done['parts']));
    }

    public function test_no_stream_is_left_open_after_a_step(): void
    {
        $id = $this->attachment('leak.txt', 'x');
        UAMS_MRA_Archive::start([$id]);
        $before = count(get_resources('stream'));

        UAMS_MRA_Archive::step();

        $this->assertSame($before, count(get_resources('stream')));
    }

    public function test_the_extension_is_read_in_any_case(): void
    {
        $uploads = wp_get_upload_dir();
        $path = trailingslashit($uploads['basedir']).'SHOUT.JPG';
        wp_mkdir_p($uploads['basedir']);
        file_put_contents($path, str_repeat('compress me ', 400));
        $this->files[] = $path;
        $id = (int) wp_insert_attachment(['post_title' => 'shout', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg']);
        update_post_meta($id, '_wp_attached_file', 'SHOUT.JPG');
        UAMS_MRA_Archive::start([$id]);

        $job = $this->finish();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($job['dir'].'/'.UAMS_MRA_Archive::part_name(1)));
        $stat = $zip->statName('SHOUT.JPG');
        $zip->close();
        $this->assertIsArray($stat);
        $this->assertSame(ZipArchive::CM_STORE, $stat['comp_method']);
    }

    public function test_formats_that_are_already_compressed_are_stored_and_the_rest_are_deflated(): void
    {
        $text = str_repeat('compress me ', 400);
        $ids = ['plain.txt' => $this->attachment('plain.txt', $text)];

        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'mov', 'mp3', 'zip', 'pdf', 'docx', 'pptx', 'xlsx', 'JPG'] as $ext) {
            $ids['file.'.$ext] = $this->attachment('file.'.$ext, $text);
        }

        UAMS_MRA_Archive::start(array_values($ids));
        $job = $this->finish();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($job['dir'].'/'.UAMS_MRA_Archive::part_name(1)));

        foreach ($ids as $name => $id) {
            $stat = $zip->statName($this->relative($id));
            $this->assertIsArray($stat, $name);
            $this->assertSame($name === 'plain.txt' ? ZipArchive::CM_DEFLATE : ZipArchive::CM_STORE, $stat['comp_method'], $name);
        }

        $zip->close();
    }

    public function test_a_file_outside_uploads_goes_in_under_its_id_and_name(): void
    {
        $path = sys_get_temp_dir().'/uams-mra-outside-'.wp_generate_password(6, false).'.txt';
        file_put_contents($path, 'outside');
        $this->files[] = $path;
        $id = (int) wp_insert_attachment(['post_title' => 'outside', 'post_status' => 'inherit', 'post_mime_type' => 'text/plain'], $path);
        UAMS_MRA_Archive::start([$id]);

        $job = $this->finish();

        $this->assertSame([$id.'-'.basename($path), 'manifest.csv'], $this->entries($job, 1));
        $this->assertSame($id.'-'.basename($path), $this->manifest($job, 1)[1][1]);
    }

    public function test_a_file_that_only_shares_the_uploads_prefix_is_not_taken_for_one_inside_it(): void
    {
        $uploads = wp_get_upload_dir();
        $sibling = rtrim($uploads['basedir'], '/').'-sibling';
        wp_mkdir_p($sibling);
        $path = $sibling.'/near.txt';
        file_put_contents($path, 'near');
        $this->files[] = $path;
        $id = (int) wp_insert_attachment(['post_title' => 'near', 'post_status' => 'inherit', 'post_mime_type' => 'text/plain']);
        // Core would store this path relative to uploads, so the absolute path is set as stored.
        update_post_meta($id, '_wp_attached_file', $path);
        UAMS_MRA_Archive::start([$id]);

        $job = $this->finish();

        $this->assertSame([$id.'-near.txt', 'manifest.csv'], $this->entries($job, 1));
        unlink($path);
        rmdir($sibling);
    }

    public function test_the_manifest_describes_each_file_for_a_restore(): void
    {
        $parent = self::factory()->post->create(['post_title' => 'Parent page']);
        $id = $this->attachment('restore.txt', 'twelve bytes', [
            'post_title' => 'A "quoted", title',
            'post_date' => '2023-02-03 04:05:06',
            'post_parent' => $parent,
        ]);
        update_post_meta($id, '_wp_attachment_image_alt', 'Alt, with "quotes"');
        UAMS_MRA_Triage::set($id, 'keep', 'ask Pat, then "decide"');
        $plain = $this->attachment('plain.txt', 'p');
        UAMS_MRA_Archive::start([$id, $plain]);

        $job = $this->finish();
        $rows = $this->manifest($job, 1);

        $this->assertSame(['attachment_id', 'path_in_zip', 'bytes', 'title', 'mime', 'uploaded', 'parent_id', 'alt_text', 'decision', 'note'], $rows[0]);
        $this->assertSame(
            [(string) $id, $this->relative($id), '12', 'A "quoted", title', 'text/plain', '2023-02-03 04:05:06', (string) $parent, 'Alt, with "quotes"', 'keep', 'ask Pat, then "decide"'],
            $rows[1]
        );
        $this->assertSame((string) $plain, $rows[2][0]);
        $this->assertSame('0', $rows[2][6]);
        $this->assertSame('', $rows[2][7]);
        $this->assertSame('', $rows[2][8]);
        $this->assertSame('', $rows[2][9]);
    }

    public function test_the_manifest_has_blank_fields_for_a_post_that_cannot_be_read(): void
    {
        $id = $this->attachment('orphan.txt', 'o');
        global $wpdb;
        // The file record outlives its post row, as after a partial delete.
        $wpdb->delete($wpdb->posts, ['ID' => $id]);
        clean_post_cache($id);
        UAMS_MRA_Archive::start([$id]);

        $job = $this->finish();

        $this->assertSame([(string) $id, $this->relative($id), '1', '', '', '', '0', '', '', ''], $this->manifest($job, 1)[1]);
    }

    public function test_a_part_that_already_holds_a_zip_without_a_manifest_starts_the_manifest_afresh(): void
    {
        $id = $this->attachment('keep.txt', 'k');
        $job = UAMS_MRA_Archive::start([$id]);
        $this->assertIsArray($job);
        $seed = new ZipArchive;
        $seed->open($job['dir'].'/'.UAMS_MRA_Archive::part_name(1), ZipArchive::CREATE);
        $seed->addFromString('other.txt', 'other');
        $seed->close();

        $done = $this->finish();

        $this->assertCount(2, $this->manifest($done, 1));
        $this->assertSame(1, $done['parts'][1]['files']);
    }

    public function test_a_manifest_with_blank_lines_is_read_back_without_them(): void
    {
        $a = $this->attachment('a.txt', 'a');
        $b = $this->attachment('b.txt', 'b');
        $job = UAMS_MRA_Archive::start([$a, $b]);
        $this->assertIsArray($job);
        $seed = new ZipArchive;
        $seed->open($job['dir'].'/'.UAMS_MRA_Archive::part_name(1), ZipArchive::CREATE);
        $seed->addFromString('manifest.csv', "header\r\n  \r\n9,seeded\r\n\t\r\n10,more\r\n");
        $seed->close();

        $done = $this->finish();
        $rows = $this->manifest($done, 1);

        $this->assertSame(['9', 'seeded'], $rows[1]);
        $this->assertSame(['10', 'more'], $rows[2]);
        $this->assertSame(5, count($rows));
        $this->assertSame(4, $done['parts'][1]['files']);
    }

    public function test_discarding_removes_the_job_its_files_and_its_record(): void
    {
        $id = $this->attachment('d.txt', 'd');
        UAMS_MRA_Archive::start([$id]);
        $job = $this->finish();
        mkdir($job['dir'].'/nested/deeper', 0777, true);
        file_put_contents($job['dir'].'/nested/deeper/x.txt', 'x');

        UAMS_MRA_Archive::discard();

        $this->assertDirectoryDoesNotExist($job['dir']);
        $this->assertNull(UAMS_MRA_Archive::get_job());
        $this->assertDirectoryExists(UAMS_MRA_Archive::base_dir());
    }

    public function test_discarding_with_no_job_does_nothing(): void
    {
        UAMS_MRA_Archive::discard();

        $this->assertNull(UAMS_MRA_Archive::get_job());
    }

    public function test_discarding_never_removes_anything_outside_the_archive_folder(): void
    {
        $outside = sys_get_temp_dir().'/uams-mra-outside-dir-'.wp_generate_password(6, false);
        mkdir($outside);
        file_put_contents($outside.'/keep.txt', 'keep');

        foreach ([$outside, UAMS_MRA_Archive::base_dir(), ''] as $dir) {
            wp_mkdir_p(UAMS_MRA_Archive::base_dir());
            update_user_meta(get_current_user_id(), UAMS_MRA_Archive::META_KEY, ['id' => 'x', 'dir' => $dir]);

            UAMS_MRA_Archive::discard();

            $this->assertNull(UAMS_MRA_Archive::get_job());
        }

        $this->assertFileExists($outside.'/keep.txt');
        $this->assertDirectoryExists(UAMS_MRA_Archive::base_dir());
        unlink($outside.'/keep.txt');
        rmdir($outside);
    }

    public function test_discarding_a_job_with_no_folder_recorded_only_forgets_it(): void
    {
        update_user_meta(get_current_user_id(), UAMS_MRA_Archive::META_KEY, ['id' => 'x']);

        UAMS_MRA_Archive::discard();

        $this->assertNull(UAMS_MRA_Archive::get_job());
    }

    public function test_cleaning_up_everything_removes_every_job_folder_and_keeps_the_base(): void
    {
        $base = UAMS_MRA_Archive::base_dir();
        wp_mkdir_p($base.'/one/inner');
        wp_mkdir_p($base.'/two');
        file_put_contents($base.'/one/inner/x.zip', 'x');
        file_put_contents($base.'/index.php', 'guard');

        UAMS_MRA_Archive::cleanup_all();

        $this->assertDirectoryDoesNotExist($base.'/one');
        $this->assertDirectoryDoesNotExist($base.'/two');
        $this->assertFileExists($base.'/index.php');
        $this->assertDirectoryExists($base);
    }

    public function test_cleaning_up_with_no_archive_folder_does_nothing(): void
    {
        $this->assertDirectoryDoesNotExist(UAMS_MRA_Archive::base_dir());

        UAMS_MRA_Archive::cleanup_all();
        UAMS_MRA_Archive::cleanup();

        $this->assertDirectoryDoesNotExist(UAMS_MRA_Archive::base_dir());
    }

    public function test_the_daily_cleanup_removes_only_folders_older_than_a_day(): void
    {
        $base = UAMS_MRA_Archive::base_dir();
        wp_mkdir_p($base.'/old');
        wp_mkdir_p($base.'/almost');
        wp_mkdir_p($base.'/fresh');
        touch($base.'/old', time() - UAMS_MRA_Archive::TTL - 60);
        touch($base.'/almost', time() - UAMS_MRA_Archive::TTL + 60);
        file_put_contents($base.'/note.txt', 'a file, not a job folder');
        touch($base.'/note.txt', time() - UAMS_MRA_Archive::TTL - 60);

        UAMS_MRA_Archive::cleanup();

        $this->assertDirectoryDoesNotExist($base.'/old');
        $this->assertDirectoryExists($base.'/almost');
        $this->assertDirectoryExists($base.'/fresh');
        $this->assertFileExists($base.'/note.txt');
        unlink($base.'/note.txt');
    }

    public function test_a_finished_archive_lives_for_a_day(): void
    {
        $this->assertSame(DAY_IN_SECONDS, UAMS_MRA_Archive::TTL);
        $this->assertSame('uams-mra-archives', UAMS_MRA_Archive::DIR);
        $this->assertSame('_uams_mra_archive', UAMS_MRA_Archive::META_KEY);
        $this->assertSame('uams_mra_cleanup_archives', UAMS_MRA_Archive::CLEANUP_HOOK);
    }
}
