<?php

/**
 * Repoint offsite URLs at the copy this library already holds.
 *
 * The narrow half of a media migration, and the half that needs no network
 * transfer: where a page serves Foo.pdf from the host this site moved off and
 * this library already contains Foo.pdf, the stored URL is rewritten to the
 * local attachment. Nothing is downloaded, no attachment is created, and no
 * duplicate appears.
 *
 * Two phases, both batched, and the first one only reads:
 *
 *   plan  -- for every offsite URL, find the local candidate and compare the
 *            two files by size over HTTP. Writes a verdict, changes nothing.
 *   apply -- rewrite the rows the plan cleared, one row at a time, re-reading
 *            each value first so a page edited since the scan is left alone.
 *
 * Only "identical" is eligible by default. "resized" is offered as an opt-in
 * because the evidence is weaker: the library has an image of that name and
 * WordPress has a size to match, but the bytes are not the same. Everything
 * else is reported and never touched.
 */
defined('ABSPATH') || exit;

/**
 * Plans and applies URL repointing.
 */
class UAMS_MRA_Repoint
{
    /**
     * Option holding the current job.
     */
    const STATE_OPTION = 'uams_mra_repoint_state';

    /**
     * Option holding the last fatal error a job died on.
     */
    const FATAL_OPTION = 'uams_mra_repoint_fatal';

    /* ---------------------------------------------------------------------
     * Fatal capture
     * ------------------------------------------------------------------ */

    /**
     * Record whatever kills the request, so it can be read without a log file.
     *
     * A fatal -- memory exhausted, a hook in another plugin blowing up, a call
     * to something that is not there -- ends the request without a response, so
     * the browser learns only that nothing came back. On a host with no shell
     * and no reachable error log, that is the end of the trail.
     *
     * A shutdown handler still runs after a fatal, and error_get_last() still
     * has it, so the message is parked in an option for the screen to show.
     *
     * @param  string  $context  What was being attempted.
     */
    public static function watch_for_fatal($context = ''): void
    {
        delete_option(self::FATAL_OPTION);

        register_shutdown_function(
            static function () use ($context): void {
                $error = error_get_last();

                if (! $error || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                    return;
                }

                update_option(
                    self::FATAL_OPTION,
                    [
                        'message' => $error['message'],
                        'file' => $error['file'],
                        'line' => (int) $error['line'],
                        'context' => $context,
                        'when' => time(),
                    ],
                    false
                );
            }
        );
    }

    /**
     * The last captured fatal, if there is one.
     */
    public static function last_fatal(): array
    {
        $fatal = get_option(self::FATAL_OPTION, []);

        return is_array($fatal) ? $fatal : [];
    }

    /**
     * Forget the recorded fatal.
     */
    public static function clear_fatal(): void
    {
        delete_option(self::FATAL_OPTION);
    }

    /**
     * What the current batch is about to attempt, for the fatal record.
     */
    public static function next_target(): string
    {
        $job = self::get_job();

        if (empty($job['queue'][0]['url'])) {
            return (string) ($job['phase'] ?? '');
        }

        return $job['phase'].': '.$job['queue'][0]['url'];
    }

    /**
     * Verdicts a person can act on, weakest last.
     *
     * @param  bool  $include_resized  Also treat a same-name-different-bytes image as eligible.
     */
    public static function eligible_verdicts($include_resized = false): array
    {
        // A side-loaded file was fetched from the very URL being replaced, so it
        // is at least as safe as an identical match and never needs opting in.
        $base = ['identical', 'sideloaded'];

        return $include_resized ? array_merge($base, ['resized']) : $base;
    }

    /**
     * Every verdict the plan can reach, with what it means.
     */
    public static function verdicts(): array
    {
        return [
            'identical' => __('The local file has the same name and the same size. Safe to repoint.', 'uams-mra'),
            'resized' => __('The library holds this image, but not at the same byte size. Look before including these.', 'uams-mra'),
            'differs' => __('Same filename, different file. Someone has to decide which one is wanted.', 'uams-mra'),
            'ambiguous' => __('More than one local attachment shares this filename, so there is no single right target.', 'uams-mra'),
            'nolocal' => __('Nothing in this library has this filename. Pull it over below.', 'uams-mra'),
            'unreachable' => __('The other host did not return the file, so the two could not be compared.', 'uams-mra'),
            'sideloaded' => __('Pulled into this library from the other host. Ready to repoint.', 'uams-mra'),
            'fetchfailed' => __('The download failed. Nothing was added.', 'uams-mra'),
            'toobig' => __('Larger than the size limit in Scan settings. Nothing was added.', 'uams-mra'),
            'rejected' => __('WordPress refused the file type on upload. Nothing was added.', 'uams-mra'),
            'crashed' => __('Handling this file killed the request outright. It has been set aside so the rest can run; it needs doing by hand.', 'uams-mra'),
        ];
    }

    /* ---------------------------------------------------------------------
     * Job state
     * ------------------------------------------------------------------ */
    /**
     * Current job.
     */
    public static function get_job(): array
    {
        $job = get_option(self::STATE_OPTION, []);

        return is_array($job) ? $job : [];
    }

    /**
     * Persist the job.
     *
     * @param  array  $job  Job.
     */
    protected static function put_job(array $job)
    {
        // Stamped on every batch so a job that stopped being driven can be told
        // apart from one still working.
        $job['updated'] = time();

        update_option(self::STATE_OPTION, $job, false);
    }

    /**
     * How long a job may sit untouched before it stops counting as running.
     *
     * A batch is one file at most, and the browser fires the next request the
     * moment one returns, so a real job never goes quiet for long. A gap past
     * this means the tab was closed or the request died -- and treating that as
     * "still running" forever would lock the scan out with nothing to click.
     *
     * @return int Seconds.
     */
    protected static function stall_timeout(): int
    {
        return (int) apply_filters('uams_mra_repoint_stall_timeout', 5 * MINUTE_IN_SECONDS);
    }

    /**
     * Throw the job away.
     */
    public static function discard(): void
    {
        delete_option(self::STATE_OPTION);
    }

    /**
     * Begin the read-only planning pass.
     *
     * @return array Job.
     */
    public static function start_plan(): array
    {
        global $wpdb;

        $t = UAMS_MRA_Store::offsite_table();

        // A previous plan is cleared rather than resumed. Content moves, and a
        // verdict from before the last scan is not evidence about now.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("UPDATE {$t} SET verdict = '', local_id = 0, remote_bytes = 0, local_bytes = 0, target_url = '', checked_at = '0000-00-00 00:00:00' WHERE applied = 0");

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT url_hash) FROM {$t} WHERE applied = 0");

        $job = [
            'phase' => 'plan',
            'total' => $total,
            'done' => 0,
            'applied' => 0,
            'failed' => 0,
            'skipped' => 0,
            'message' => __('Comparing each file with the copy in this library', 'uams-mra'),
        ];

        self::put_job($job);

        return $job;
    }

    /**
     * Begin the writing pass.
     *
     * @param  bool  $include_resized  Also repoint the weaker "resized" matches.
     * @return array Job.
     */
    public static function start_apply($include_resized = false): array
    {
        global $wpdb;

        if (UAMS_MRA_Store::offsite_needs_rescan()) {
            return self::stale_job();
        }

        $t = UAMS_MRA_Store::offsite_table();
        $verdicts = self::eligible_verdicts($include_resized);
        $in = "'".implode("','", array_map(esc_sql(...), $verdicts))."'";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE applied = 0 AND target_url <> '' AND verdict IN ({$in})");

        $job = [
            'phase' => 'apply',
            'resized' => $include_resized ? 1 : 0,
            'total' => $total,
            'done' => 0,
            'applied' => 0,
            'failed' => 0,
            'skipped' => 0,
            'message' => __('Rewriting stored URLs', 'uams-mra'),
        ];

        self::put_job($job);

        return $job;
    }

    /**
     * Is a repoint or side-load job part-way through.
     */
    public static function is_running(): bool
    {
        $job = self::get_job();

        if ($job === [] || 'done' === ($job['phase'] ?? 'done')) {
            return false;
        }

        $updated = (int) ($job['updated'] ?? 0);

        // No timestamp means it was written by an older version. Treat it as
        // stale rather than blocking on it indefinitely.
        if (! $updated) {
            return false;
        }

        return (time() - $updated) < self::stall_timeout();
    }

    /**
     * A finished job that did nothing, because the report is out of date.
     *
     * @return array Job.
     */
    protected static function stale_job(): array
    {
        $job = [
            'phase' => 'done',
            'total' => 0,
            'done' => 0,
            'applied' => 0,
            'failed' => 0,
            'skipped' => 0,
            'message' => __('This report was built by an older version and does not record which database row each URL sits in. Run a scan first.', 'uams-mra'),
        ];

        self::put_job($job);

        return $job;
    }

    /**
     * Run one batch.
     *
     * @return array Job.
     */
    public static function step()
    {
        $job = self::get_job();

        if ($job === [] || 'done' === ($job['phase'] ?? 'done')) {
            return $job;
        }

        if ($job['phase'] === 'plan') {
            $job = self::step_plan($job);
        } elseif ($job['phase'] === 'sideload') {
            $job = self::step_sideload($job);
        } else {
            $job = self::step_apply($job);
        }

        self::put_job($job);

        return $job;
    }

    /**
     * Batch size for whichever phase is running.
     */
    protected static function batch(): int
    {
        $settings = uams_mra_settings();
        $defaults = uams_mra_default_settings();

        return max(1, min(25, (int) ($settings['batch_repoint'] ?? $defaults['batch_repoint'])));
    }

    /* ---------------------------------------------------------------------
     * Plan
     * ------------------------------------------------------------------ */

    /**
     * Compare a batch of offsite URLs with the local library.
     *
     * @param  array  $job  Job.
     * @return array
     */
    protected static function step_plan($job)
    {
        global $wpdb;

        $t = UAMS_MRA_Store::offsite_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT url_hash, url, base_norm FROM {$t}
				 WHERE verdict = '' AND applied = 0
				 GROUP BY url_hash, url, base_norm
				 ORDER BY url_hash ASC LIMIT %d",
                self::batch()
            )
        );

        if (empty($rows)) {
            $job['phase'] = 'done';
            $job['message'] = __('Comparison finished', 'uams-mra');

            return $job;
        }

        foreach ($rows as $row) {
            $verdict = self::classify($row->url, $row->base_norm);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$t} SET verdict = %s, local_id = %d, remote_bytes = %d, local_bytes = %d, target_url = %s, checked_at = %s
					 WHERE url_hash = %s AND applied = 0",
                    $verdict['verdict'],
                    $verdict['local_id'],
                    $verdict['remote_bytes'],
                    $verdict['local_bytes'],
                    $verdict['target_url'],
                    current_time('mysql'),
                    $row->url_hash
                )
            );

            $job['done']++;
        }

        return $job;
    }

    /**
     * Decide what one offsite URL is, relative to this library.
     *
     * @param  string  $url  Offsite URL.
     * @param  string  $base_norm  Normalized filename.
     * @return array {verdict, local_id, remote_bytes, local_bytes, target_url}
     */
    protected static function classify($url, $base_norm): array
    {
        $out = [
            'verdict' => 'nolocal',
            'local_id' => 0,
            'remote_bytes' => 0,
            'local_bytes' => 0,
            'target_url' => '',
        ];

        $candidates = UAMS_MRA_Index::resolve_paths([$base_norm]);

        if ($candidates === []) {
            return $out;
        }

        if (count($candidates) > 1) {
            $out['verdict'] = 'ambiguous';

            return $out;
        }

        $local_id = (int) $candidates[0];
        $out['local_id'] = $local_id;
        $remote = self::remote_size($url);

        if ($remote === null) {
            $out['verdict'] = 'unreachable';

            return $out;
        }

        $out['remote_bytes'] = $remote;

        $sized = self::parse_size($url);

        if ($sized) {
            $match = self::local_size_file($local_id, $sized[0], $sized[1]);

            if ($match) {
                $out['local_bytes'] = $match['bytes'];
                $out['target_url'] = $match['url'];
                $out['verdict'] = ($match['bytes'] === $remote) ? 'identical' : 'resized';

                return $out;
            }

            // The library has the image but not that size. The full size is the
            // only honest target, and it is a different file from what the page
            // serves today, so this never counts as identical.
            $out['local_bytes'] = self::local_original_size($local_id);
            $out['target_url'] = (string) wp_get_attachment_url($local_id);
            $out['verdict'] = 'resized';

            return $out;
        }

        $out['local_bytes'] = self::local_original_size($local_id);
        $out['target_url'] = (string) wp_get_attachment_url($local_id);
        $out['verdict'] = ($out['local_bytes'] === $remote) ? 'identical' : 'differs';

        return $out;
    }

    /**
     * Size of a remote file, or null if it could not be read.
     *
     * @param  string  $url  URL.
     */
    protected static function remote_size($url): ?int
    {
        $args = apply_filters(
            'uams_mra_repoint_request_args',
            [
                'timeout' => 15,
                'redirection' => 3,
                'sslverify' => apply_filters('uams_mra_render_sslverify', true),
                'user-agent' => 'UAMS-Media-Reference-Audit/'.UAMS_MRA_VERSION.' (+'.home_url().')',
            ],
            $url
        );

        $response = wp_safe_remote_head($url, $args);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $length = wp_remote_retrieve_header($response, 'content-length');

        // A header sent twice comes back as a list; the first value is the one a client reads.
        if (is_array($length)) {
            $length = reset($length);
        }

        return ($length === '' || $length === false) ? null : (int) $length;
    }

    /**
     * Width and height a URL asks for, or null when it names the original.
     *
     * @param  string  $url  URL.
     * @return array|null {width, height}
     */
    protected static function parse_size($url): ?array
    {
        $base = basename(wp_parse_url($url, PHP_URL_PATH));

        if (preg_match('#-(\d{1,5})x(\d{1,5})(?:-[a-z]{1,4})?\.[a-zA-Z0-9]{2,5}$#i', $base, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return null;
    }

    /**
     * The generated size of a local attachment matching these dimensions.
     *
     * @param  int  $attachment_id  Attachment.
     * @param  int  $width  Width.
     * @param  int  $height  Height.
     * @return array|null {bytes, url}
     */
    protected static function local_size_file($attachment_id, $width, $height): ?array
    {
        $meta = wp_get_attachment_metadata($attachment_id);

        if (! is_array($meta) || empty($meta['sizes'])) {
            return null;
        }

        $file = get_attached_file($attachment_id);
        $dir = trailingslashit(dirname((string) $file));
        $base = (string) wp_get_attachment_url($attachment_id);
        $base = trailingslashit(dirname($base));

        foreach ($meta['sizes'] as $size) {
            if (empty($size['file'])) {
                continue;
            }

            if ((int) ($size['width'] ?? 0) !== $width || (int) ($size['height'] ?? 0) !== $height) {
                continue;
            }

            $path = $dir.$size['file'];

            if (! is_readable($path) || ! is_file($path)) {
                continue;
            }

            return [
                'bytes' => (int) filesize($path),
                'url' => $base.$size['file'],
            ];
        }

        return null;
    }

    /**
     * Size on disk of the attachment's own file.
     *
     * @param  int  $attachment_id  Attachment.
     */
    protected static function local_original_size($attachment_id): int
    {
        $path = get_attached_file($attachment_id);

        return ($path && is_readable($path) && is_file($path)) ? (int) filesize($path) : 0;
    }

    /**
     * How long one batch may spend before it has to hand control back.
     *
     * Downloading is the only thing this plugin does that can outrun PHP's
     * execution limit. Overrunning it kills the request mid-write and the
     * browser sees nothing but a dead connection, so a batch stops itself well
     * short and lets the next request pick up.
     *
     * @return int Seconds.
     */
    protected static function time_budget(): int
    {
        $max = (int) ini_get('max_execution_time');

        // Zero means no limit, which is common on CLI and rare on a web request.
        // Assume a conservative ceiling rather than trusting it.
        if ($max <= 0) {
            $max = 30;
        }

        return (int) apply_filters('uams_mra_repoint_time_budget', max(5, (int) floor($max * 0.6)));
    }

    /* ---------------------------------------------------------------------
     * Side-load
     * ------------------------------------------------------------------ */

    /**
     * Queue up the files this library does not have.
     *
     * Grouped by the *original* rather than by URL. A page that shows one photo
     * at three sizes stores three URLs pointing at three derivatives of one
     * upload; downloading each of them would put three attachments in the
     * library for one image. One original comes over, WordPress regenerates the
     * sizes, and all three URLs are pointed at it.
     *
     * @return array Job.
     */
    public static function start_sideload(): array
    {
        global $wpdb;

        if (UAMS_MRA_Store::offsite_needs_rescan()) {
            return self::stale_job();
        }

        $t = UAMS_MRA_Store::offsite_table();

        // 'crashed' rows are deliberately not picked up again here. Retrying them
        // is a decision a person makes, by running the comparison again.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results("SELECT url_hash, url FROM {$t} WHERE verdict = 'nolocal' AND applied = 0 GROUP BY url_hash, url");

        $groups = [];

        foreach ($rows as $row) {
            $original = self::original_url($row->url);

            $groups[$original] ??= [
                'url' => $original,
                'hashes' => [],
            ];

            $groups[$original]['hashes'][] = $row->url_hash;
        }

        $queue = array_values($groups);

        // Documents first, images last. Only an image sends WordPress off to
        // build thumbnails, which is where a big file exhausts PHP's memory. If
        // something is going to fall over it should do it once the safe bulk of
        // the work is already banked.
        usort(
            $queue,
            static function (array $a, array $b): int {
                $rank = static function ($url): int {
                    $ext = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

                    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff'], true) ? 1 : 0;
                };

                $diff = $rank($a['url']) - $rank($b['url']);

                return ($diff !== 0) ? $diff : strcmp($a['url'], $b['url']);
            }
        );

        $job = [
            'phase' => 'sideload',
            'queue' => $queue,
            'total' => count($groups),
            'done' => 0,
            'applied' => 0,
            'failed' => 0,
            'skipped' => 0,
            'message' => __('Pulling files into this library', 'uams-mra'),
        ];

        self::put_job($job);

        return $job;
    }

    /**
     * The URL of the upload a derivative was made from.
     *
     * @param  string  $url  URL.
     */
    public static function original_url($url): string
    {
        $parts = explode('/', $url);
        $base = array_pop($parts);

        $base = preg_replace('#-\d{1,5}x\d{1,5}(?:-[a-z]{1,4})?(\.[a-zA-Z0-9]{2,5})$#i', '$1', $base);
        $base = preg_replace('#-scaled(\.[a-zA-Z0-9]{2,5})$#i', '$1', $base);

        $parts[] = $base;

        return implode('/', $parts);
    }

    /**
     * Download a batch of originals and record where each URL should now point.
     *
     * @param  array  $job  Job.
     * @return array
     */
    protected static function step_sideload($job)
    {
        if (empty($job['queue'])) {
            $job['phase'] = 'done';
            $job['message'] = __('Nothing left to pull over', 'uams-mra');

            return $job;
        }

        $settings = uams_mra_settings();
        $defaults = uams_mra_default_settings();
        $limit = max(1, min(10, (int) ($settings['batch_sideload'] ?? $defaults['batch_sideload'])));
        $budget = self::time_budget();
        $started = microtime(true);
        $handled = 0;

        while ($handled < $limit && ! empty($job['queue'])) {
            // Stop before the execution limit rather than being killed by it.
            if ($handled > 0 && (microtime(true) - $started) > $budget) {
                break;
            }

            $group = array_shift($job['queue']);

            // Claim the file before touching it. If what follows takes the whole
            // request down -- a fatal, or the host killing the process outright,
            // where no handler of ours ever runs -- this mark is already
            // committed, so the next run steps over it instead of dying on the
            // same file forever. One bad file costs one file, not the queue.
            self::mark_hashes($group['hashes'], 'crashed', 0, '');

            // Commit the queue advance too, not just the database mark. Without
            // this the shifted queue dies with the request and the stored job
            // still has the file at its head, so resuming would hand it straight
            // back to whatever just killed it.
            self::put_job($job);

            try {
                $result = self::sideload_one($group['url'], $budget - (microtime(true) - $started));
            } catch (Throwable $e) {
                // One unreadable file must not take the whole run down with it.
                $result = new WP_Error('fetchfailed', $e->getMessage());
            }

            if (is_wp_error($result)) {
                self::mark_hashes($group['hashes'], $result->get_error_code(), 0, '');
                $job['failed']++;
            } else {
                self::point_group_at($group['hashes'], (int) $result);
                $job['applied']++;
            }

            $job['done']++;
            $handled++;
        }

        if (empty($job['queue'])) {
            $job['phase'] = 'done';
            $job['message'] = __('Finished pulling files over', 'uams-mra');
        }

        return $job;
    }

    /**
     * Fetch one file and put it in the media library.
     *
     * @param  string  $url  URL of the original.
     * @param  int|float  $timeout  Seconds left in this batch's budget.
     * @return int|WP_Error Attachment ID.
     */
    protected static function sideload_one($url, $timeout = 60): WP_Error|int
    {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        $settings = uams_mra_settings();
        $defaults = uams_mra_default_settings();
        $max = max(1, (int) ($settings['sideload_max_mb'] ?? $defaults['sideload_max_mb'])) * MB_IN_BYTES;

        $size = self::remote_size($url);

        if ($size !== null && $size > $max) {
            return new WP_Error('toobig', __('Larger than the configured limit.', 'uams-mra'));
        }

        $tmp = download_url($url, max(10, (int) $timeout));

        if (is_wp_error($tmp)) {
            return new WP_Error('fetchfailed', $tmp->get_error_message());
        }

        // A server that answers a HEAD without content-length can still hand back
        // something enormous, so the limit is enforced again on what arrived.
        if (filesize($tmp) > $max) {
            wp_delete_file($tmp);

            return new WP_Error('toobig', __('Larger than the configured limit.', 'uams-mra'));
        }

        $name = basename((string) wp_parse_url($url, PHP_URL_PATH));

        $file = [
            'name' => $name,
            'tmp_name' => $tmp,
        ];

        // Thumbnailing decompresses the whole image into memory, and PHP running
        // out mid-way is a fatal error no try/catch can see -- the request just
        // dies and the browser is left with nothing. So the cost is worked out
        // beforehand and the resizing skipped when it will not fit.
        wp_raise_memory_limit('image');

        $dimensions = @getimagesize($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $resizable = self::can_resize($dimensions);

        // wp_handle_sideload runs the same checks as the media screen, so a file
        // type this site does not allow is refused rather than written anywhere.
        $sideloaded = wp_handle_sideload($file, ['test_form' => false]);

        if (isset($sideloaded['error'])) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }

            return new WP_Error('rejected', $sideloaded['error']);
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $sideloaded['type'],
                'post_title' => sanitize_text_field(pathinfo($name, PATHINFO_FILENAME)),
                'post_content' => '',
                'post_status' => 'inherit',
            ],
            $sideloaded['file'],
            self::sideload_parent($url),
            true
        );

        if (is_wp_error($attachment_id)) {
            wp_delete_file($sideloaded['file']);

            return new WP_Error('rejected', $attachment_id->get_error_message());
        }

        if ($resizable) {
            wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $sideloaded['file']));
        } elseif ($dimensions) {
            // The file is in and usable at full size. Sizes can be rebuilt later
            // by a regeneration plugin, or by raising the memory limit and
            // re-saving, neither of which needs the old host to still exist.
            wp_update_attachment_metadata(
                $attachment_id,
                [
                    'file' => _wp_relative_upload_path($sideloaded['file']),
                    'width' => (int) $dimensions[0],
                    'height' => (int) $dimensions[1],
                    'sizes' => [],
                ]
            );
        } else {
            wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $sideloaded['file']));
        }

        return (int) $attachment_id;
    }

    /**
     * Will resizing this image fit in the memory PHP has left.
     *
     * An image editor holds the decompressed bitmap, which bears no relation to
     * the file size -- a 2 MB JPEG can be 90 MB of pixels. Four bytes a pixel
     * plus room for the source and the destination, against what is actually
     * still free.
     *
     * @param  array|false  $dimensions  Result of getimagesize(), or false for a non-image.
     * @return bool True to resize, false to skip it.
     */
    protected static function can_resize($dimensions): bool
    {
        // Not an image. Nothing to decompress, so nothing to weigh.
        if (empty($dimensions[0]) || empty($dimensions[1])) {
            return true;
        }

        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));

        // No limit set. Trust it, but not blindly on a huge image.
        if ($limit <= 0) {
            $limit = 512 * MB_IN_BYTES;
        }

        $free = max(0, $limit - memory_get_usage(true));
        $needed = (int) $dimensions[0] * (int) $dimensions[1] * 4 * 2.5;

        return $needed < ($free * 0.7);
    }

    /**
     * Which post to hang a newly pulled file off.
     *
     * The first published post whose content shows it, which is what the media
     * library would have recorded had someone uploaded it from that page. Zero
     * when only a custom field or an option refers to it, since inventing a
     * parent would be a claim about provenance nothing supports.
     *
     * @param  string  $url  Original URL.
     */
    protected static function sideload_parent($url): int
    {
        global $wpdb;

        $t = UAMS_MRA_Store::offsite_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $parent = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT source_id FROM {$t}
				 WHERE base_norm = %s AND source_type = 'content'
				 ORDER BY source_id ASC LIMIT 1",
                UAMS_MRA_Index::normalize($url)[1]
            )
        );

        return $parent ? (int) $parent : 0;
    }

    /**
     * Point every URL in a group at the attachment just created.
     *
     * @param  array  $hashes  URL hashes.
     * @param  int  $attachment_id  New attachment.
     */
    protected static function point_group_at(array $hashes, $attachment_id)
    {
        global $wpdb;

        $t = UAMS_MRA_Store::offsite_table();

        foreach ($hashes as $hash) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $url = $wpdb->get_var($wpdb->prepare("SELECT url FROM {$t} WHERE url_hash = %s LIMIT 1", $hash));

            $target = (string) wp_get_attachment_url($attachment_id);
            $sized = $url ? self::parse_size($url) : null;

            // A URL that asked for a thumbnail gets the matching regenerated
            // size where WordPress made one, so the page keeps its dimensions.
            if ($sized) {
                $match = self::local_size_file($attachment_id, $sized[0], $sized[1]);
                if ($match) {
                    $target = $match['url'];
                }
            }

            self::mark_hashes([$hash], 'sideloaded', $attachment_id, $target);
        }
    }

    /**
     * Write a verdict against every row sharing these URL hashes.
     *
     * @param  array  $hashes  URL hashes.
     * @param  string  $verdict  Verdict.
     * @param  int  $local  Local attachment ID.
     * @param  string  $target  Replacement URL.
     */
    protected static function mark_hashes(array $hashes, $verdict, $local, $target)
    {
        global $wpdb;

        if ($hashes === []) {
            return;
        }

        $t = UAMS_MRA_Store::offsite_table();

        foreach (array_chunk($hashes, 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $sql = "UPDATE {$t} SET verdict = %s, local_id = %d, target_url = %s, checked_at = %s
				WHERE url_hash IN ({$placeholders}) AND applied = 0";
            $args = array_merge([$verdict, (int) $local, $target, current_time('mysql')], $chunk);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare($sql, $args));
        }
    }

    /* ---------------------------------------------------------------------
     * Apply
     * ------------------------------------------------------------------ */

    /**
     * Rewrite a batch of stored URLs.
     *
     * @param  array  $job  Job.
     * @return array
     */
    protected static function step_apply($job)
    {
        global $wpdb;

        $t = UAMS_MRA_Store::offsite_table();
        $verdicts = self::eligible_verdicts(! empty($job['resized']));
        $in = "'".implode("','", array_map(esc_sql(...), $verdicts))."'";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$t}
				 WHERE applied = 0 AND target_url <> '' AND verdict IN ({$in})
				 ORDER BY offsite_id ASC LIMIT %d",
                self::batch()
            )
        );

        if (empty($rows)) {
            $job['phase'] = 'done';
            $job['message'] = __('Rewriting finished', 'uams-mra');

            return $job;
        }

        foreach ($rows as $row) {
            $result = self::rewrite_row($row);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare("UPDATE {$t} SET applied = %d WHERE offsite_id = %d", $result['applied'], (int) $row->offsite_id));

            $job['done']++;

            if ($result['status'] === 'applied') {
                $job['applied']++;
            } elseif ($result['status'] === 'failed') {
                $job['failed']++;
            } else {
                $job['skipped']++;
            }
        }

        return $job;
    }

    /**
     * Rewrite one stored value.
     *
     * Reads the value fresh rather than trusting the scan. If the URL is no
     * longer in it, the content changed after the scan and this row is left
     * alone rather than being written from a stale plan.
     *
     * @param  object  $row  Offsite row.
     * @return array {status, applied}
     */
    protected static function rewrite_row($row): array
    {
        global $wpdb;

        $from = (string) $row->url;
        $to = (string) $row->target_url;
        $type = (string) $row->source_type;
        $pk = (int) $row->source_pk;

        // Revisions are history. Rewriting them would edit what someone
        // restores a page *from*, which is not this tool's business.
        if ($type === 'revision' || $type === 'revision_meta') {
            return [
                'status' => 'skipped',
                'applied' => 2,
            ];
        }

        if ($to === '' || $from === $to) {
            return [
                'status' => 'skipped',
                'applied' => 2,
            ];
        }

        if ($type === 'content') {
            // wp_update_post() runs the content filters for whoever is signed in. A
            // user without unfiltered_html (a subsite administrator on multisite)
            // would have iframes, scripts and block markup stripped from every page
            // this rewrites, which is a change nobody asked for. Refuse the row.
            if (! current_user_can('unfiltered_html')) {
                return [
                    'status' => 'failed',
                    'applied' => 3,
                ];
            }

            $post = get_post($pk);

            if (! $post) {
                return [
                    'status' => 'failed',
                    'applied' => 3,
                ];
            }

            $content = self::replace_variants($post->post_content, $from, $to);
            $excerpt = self::replace_variants($post->post_excerpt, $from, $to);

            if ($content === $post->post_content && $excerpt === $post->post_excerpt) {
                return [
                    'status' => 'skipped',
                    'applied' => 2,
                ];
            }

            // wp_update_post rather than a direct write, so the previous content
            // is kept as a revision and the change can be undone from the editor.
            $updated = wp_update_post(
                [
                    'ID' => $pk,
                    // wp_update_post() expects slashed input and unslashes it, so
                    // unslashed text loses every backslash: the \/ and \" that block
                    // JSON carries would be saved as / and ".
                    'post_content' => wp_slash($content),
                    'post_excerpt' => wp_slash($excerpt),
                ],
                true
            );

            return is_wp_error($updated)
                ? [
                    'status' => 'failed',
                    'applied' => 3,
                ]
                : [
                    'status' => 'applied',
                    'applied' => 1,
                ];
        }

        if ($type === 'option') {
            $name = (string) $row->source_key;

            if ($name === '') {
                return [
                    'status' => 'failed',
                    'applied' => 3,
                ];
            }

            $value = get_option($name);
            $new = self::replace_variants($value, $from, $to);

            if ($new === $value) {
                return [
                    'status' => 'skipped',
                    'applied' => 2,
                ];
            }

            if (! update_option($name, $new)) {
                return [
                    'status' => 'failed',
                    'applied' => 3,
                ];
            }

            return [
                'status' => 'applied',
                'applied' => 1,
            ];
        }

        $meta = self::meta_target($type);

        if (! $meta) {
            return [
                'status' => 'skipped',
                'applied' => 2,
            ];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $current = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$meta['table']} WHERE {$meta['pk']} = %d", $pk));

        if ($current === null) {
            return [
                'status' => 'failed',
                'applied' => 3,
            ];
        }

        // A value that looks serialized but does not unserialize (a length prefix
        // broken by an earlier search and replace) reads back as false. Writing
        // that back would replace the stored value with `b:0;`, and meta keeps no
        // revision to restore from.
        if (self::is_unreadable($current)) {
            return [
                'status' => 'failed',
                'applied' => 3,
            ];
        }

        $new = self::replace_variants($current, $from, $to);

        if ($new === $current) {
            return [
                'status' => 'skipped',
                'applied' => 2,
            ];
        }

        $ok = $wpdb->update($meta['table'], ['meta_value' => $new], [$meta['pk'] => $pk]);

        if ($ok === false) {
            return [
                'status' => 'failed',
                'applied' => 3,
            ];
        }

        // Written straight to the row, so the cached copy has to be dropped by
        // hand or the old URL keeps being served from memory.
        wp_cache_delete((string) $row->source_id, $meta['cache']);

        return [
            'status' => 'applied',
            'applied' => 1,
        ];
    }

    /**
     * Table, primary key, and cache group for a meta source type.
     *
     * @param  string  $type  Source type.
     */
    protected static function meta_target($type): ?array
    {
        global $wpdb;

        $map = [
            'postmeta' => [$wpdb->postmeta, 'meta_id', 'post_meta'],
            'termmeta' => [$wpdb->termmeta, 'meta_id', 'term_meta'],
            'usermeta' => [$wpdb->usermeta, 'umeta_id', 'user_meta'],
            'commentmeta' => [$wpdb->commentmeta, 'meta_id', 'comment_meta'],
        ];

        if (! isset($map[$type])) {
            return null;
        }

        return [
            'table' => $map[$type][0],
            'pk' => $map[$type][1],
            'cache' => $map[$type][2],
        ];
    }

    /* ---------------------------------------------------------------------
     * Replacement
     * ------------------------------------------------------------------ */

    /**
     * Swap every form of a URL that could be stored.
     *
     * The same link reaches the database written several ways: with either
     * scheme, without one at all, and with its slashes escaped inside block
     * attributes and other JSON. A plain str_replace on the canonical form
     * misses most of them.
     *
     * Serialized values are unpicked first. Replacing inside the serialized
     * string would leave the byte-length prefixes describing the old URL, which
     * is how a search and replace corrupts a database.
     *
     * @param  mixed  $value  Stored value.
     * @param  string  $from  URL to replace.
     * @param  string  $to  Replacement.
     * @return mixed Same shape as $value.
     */
    public static function replace_variants($value, $from, $to)
    {
        $search = [];
        $replace = [];

        $from_bare = preg_replace('#^https?:#i', '', $from);
        $to_json = str_replace('/', '\\/', $to);

        // Every variant maps to the target exactly as WordPress gave it, scheme
        // included. Carrying the stored scheme across instead would rewrite an
        // old http:// link to http:// on a site served over https.
        //
        // Order matters: the scheme-less form is a substring of the schemed
        // ones, so it has to come last or it would match inside a URL already
        // rewritten and leave a mangled "https:https://" behind.
        foreach (['https:', 'http:', ''] as $scheme) {
            $f = $scheme.$from_bare;

            $search[] = $f;
            $replace[] = $to;

            // The JSON form, as block attributes and plenty of plugins store it.
            $search[] = str_replace('/', '\\/', $f);
            $replace[] = $to_json;
        }

        // Never turn an unreadable serialized value into serialize(false).
        if (self::is_unreadable($value)) {
            return $value;
        }

        $data = maybe_unserialize($value);
        $data = self::deep_replace($data, $search, $replace);

        // Round-trip exactly what came in. maybe_serialize() would drop the
        // wrapper from a serialized *scalar*, and anything that arrived already
        // unserialized -- an option, say -- has to go back the same way so the
        // caller's own update_option() can serialize it once.
        if (is_string($value) && is_serialized($value)) {
            return serialize($data); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
        }

        return $data;
    }

    /**
     * Whether a value looks serialized but cannot be read back.
     *
     * `maybe_unserialize()` returns false for it, which is also what a stored
     * `b:0;` returns, so the one real false is excluded.
     *
     * @param  mixed  $value  Stored value.
     */
    public static function is_unreadable($value): bool
    {
        return is_string($value)
            && is_serialized($value)
            && trim($value) !== 'b:0;'
            && maybe_unserialize($value) === false;
    }

    /**
     * Replace one URL's variants inside a string, only where the URL ends.
     *
     * Without the boundary `…/a.jpg` also matches the front of `…/a.jpg.webp`,
     * the file a WebP plugin keeps beside the original, and the rewrite would
     * point at a file the target does not have.
     *
     * @param  string  $subject  Text.
     * @param  array  $search  Needles, in the order they must run.
     * @param  array  $replace  Replacements, same order.
     */
    protected static function replace_urls_in_string($subject, array $search, array $replace): string
    {
        foreach ($search as $i => $needle) {
            $with = $replace[$i];
            $subject = (string) preg_replace_callback(
                '#'.preg_quote($needle, '#').'(?![\w%]|\.\w)#',
                static fn (): string => $with,
                $subject
            );
        }

        return $subject;
    }

    /**
     * Replace inside strings anywhere in a structure.
     *
     * @param  mixed  $data  Data.
     * @param  array  $search  Needles.
     * @param  array  $replace  Replacements.
     * @param  int  $depth  Recursion guard.
     * @return mixed
     */
    protected static function deep_replace($data, $search, $replace, $depth = 0)
    {
        if ($depth > 10) {
            return $data;
        }

        if (is_string($data)) {
            return self::replace_urls_in_string($data, $search, $replace);
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::deep_replace($value, $search, $replace, $depth + 1);
            }

            return $data;
        }

        if (is_object($data) && ! ($data instanceof __PHP_Incomplete_Class)) {
            foreach (get_object_vars($data) as $key => $value) {
                $data->$key = self::deep_replace($value, $search, $replace, $depth + 1);
            }

            return $data;
        }

        return $data;
    }

    /* ---------------------------------------------------------------------
     * Reporting
     * ------------------------------------------------------------------ */
    /**
     * Files per verdict, plus how many rows each would rewrite.
     */
    public static function counts(): array
    {
        global $wpdb;

        $t = UAMS_MRA_Store::offsite_table();

        $out = [];

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ($wpdb->get_results("SELECT verdict, COUNT(DISTINCT url_hash) AS files, COUNT(*) AS rows_affected FROM {$t} WHERE verdict <> '' AND applied = 0 GROUP BY verdict", ARRAY_A) as $row) {
            $out[$row['verdict']] = [
                'files' => (int) $row['files'],
                'rows' => (int) $row['rows_affected'],
            ];
        }

        return $out;
    }

    /**
     * How many rows have already been rewritten.
     */
    public static function applied_counts(): array
    {
        global $wpdb;

        $t = UAMS_MRA_Store::offsite_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row("SELECT
				SUM(CASE WHEN applied = 1 THEN 1 ELSE 0 END) AS applied,
				SUM(CASE WHEN applied = 2 THEN 1 ELSE 0 END) AS skipped,
				SUM(CASE WHEN applied = 3 THEN 1 ELSE 0 END) AS failed
			FROM {$t}", ARRAY_A);

        return is_array($row) ? array_map(intval(...), $row) : [];
    }
}
