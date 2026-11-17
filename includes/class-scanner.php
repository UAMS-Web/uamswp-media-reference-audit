<?php

/**
 * Batched reference scanner.
 */
defined('ABSPATH') || exit;

/**
 * Walks every content source and records which attachments are referenced.
 *
 * @phpstan-type RenderStats array{pages: int, found: int, absent: int, error: int, offsite: int}
 * @phpstan-type ScanState array{step: string, cursor: int, processed: int, attachments: int, started: int, finished: int, site_queue: array<int, int>, render_queue: list<int>, settings: array<string, int|string>, counts: array<mixed>, message: string, render_total?: int, render_skipped?: int, render_capped?: int, render_stats?: RenderStats, render_token?: string}
 */
class UAMS_MRA_Scanner
{
    const STATE_OPTION = 'uams_mra_scan_state';

    /**
     * Ordered pipeline.
     *
     * @var list<string>
     */
    protected static $steps = [
        'index',
        'posts',
        'postmeta',
        'options',
        'termmeta',
        'usermeta',
        'commentmeta',
        'network',
        'finalize',
        'render',
    ];

    /**
     * Current scan state.
     *
     * @return array<string, mixed>
     */
    public static function get_state(): array
    {
        $state = get_option(self::STATE_OPTION, []);

        if (! is_array($state)) {
            return [];
        }

        // The option holds what `set_state()` stored, which is a scan state.
        /** @var ScanState $state */
        return $state;
    }

    /**
     * Persist scan state.
     *
     * @param  array  $state  State.
     * @param  array<string, mixed>  $state
     */
    public static function set_state($state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * Start a fresh scan.
     *
     * @return ScanState|WP_Error State.
     */
    public static function start(): WP_Error|array
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $posts_table */
        $posts_table = $wpdb->posts;

        // A scan empties the offsite table. Doing that under a running repoint
        // or download leaves that job writing verdicts into rows the scan has
        // just replaced, and the plan it was working from is gone.
        if (UAMS_MRA_Repoint::is_running()) {
            return new WP_Error(
                'uams_mra_busy',
                __('A repoint or download is still running on the Offsite Media screen. Let it finish, then scan.', 'uams-mra')
            );
        }

        UAMS_MRA_Store::reset();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is a `$wpdb` property.
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$posts_table} WHERE post_type = 'attachment'");

        $state = [
            'step' => 'index',
            'cursor' => 0,
            'processed' => 0,
            'attachments' => $total,
            'started' => time(),
            'finished' => 0,
            'site_queue' => [],
            'render_queue' => [],
            'settings' => uams_mra_settings(),
            'counts' => [],
            'message' => __('Building the file index', 'uams-mra'),
        ];

        self::set_state($state);

        return $state;
    }

    /**
     * Run one batch and advance state.
     *
     * @return array<string, mixed> State.
     */
    public static function step()
    {
        $state = self::get_state();

        // A stored state with no step key compares as not done whatever the default text is.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        if ($state === [] || 'done' === ($state['step'] ?? '')) {
            return $state;
        }

        // A stored state that is not empty and not done is a scan state.
        /** @var ScanState $state */
        $method = 'step_'.$state['step'];

        if (! method_exists(self::class, $method)) {
            return self::advance($state);
        }

        /** @var ScanState $state */
        $state = self::$method($state);

        self::set_state($state);

        return $state;
    }

    /**
     * Move to the next step in the pipeline.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function advance($state)
    {
        $position = array_search($state['step'], self::$steps, true);

        if ($position === false || ! isset(self::$steps[$position + 1])) {
            $state['step'] = 'done';
            $state['finished'] = time();
            $state['message'] = __('Scan complete', 'uams-mra');

            return $state;
        }

        $state['step'] = self::$steps[$position + 1];
        $state['cursor'] = 0;
        $state['message'] = self::step_label($state['step']);

        // Skip steps the settings have switched off.
        $settings = $state['settings'];
        $skip = [
            'termmeta' => empty($settings['include_termmeta']),
            'usermeta' => empty($settings['include_usermeta']),
            'commentmeta' => empty($settings['include_comments']),
            'network' => empty($settings['include_network']) || ! is_multisite(),
            'render' => empty($settings['include_render']),
        ];

        if (! empty($skip[$state['step']])) {
            return self::advance($state);
        }

        if ($state['step'] === 'render') {
            $state = self::build_render_queue($state);
            if (empty($state['render_queue'])) {
                return self::advance($state);
            }
        }

        if ($state['step'] === 'network') {
            $sites = get_sites(
                [
                    'number' => 500,
                    'fields' => 'ids',
                    'site__not_in' => [get_current_blog_id()],
                ]
            );
            // get_sites() with fields set to ids already returns a list of integers, so mapping and casting change nothing.
            // @pest-mutate-ignore:UnwrapArrayMap
            // @pest-mutate-ignore:RemoveArrayCast
            $state['site_queue'] = array_map(intval(...), (array) $sites);
            if (empty($state['site_queue'])) {
                return self::advance($state);
            }
        }

        return $state;
    }

    /**
     * Human label for a step.
     *
     * @param  string  $step  Step key.
     * @return string
     */
    public static function step_label($step)
    {
        $labels = [
            'index' => __('Building the file index', 'uams-mra'),
            'posts' => __('Reading post and page content', 'uams-mra'),
            'postmeta' => __('Reading custom fields and page builder data', 'uams-mra'),
            'options' => __('Reading theme settings and widgets', 'uams-mra'),
            'termmeta' => __('Reading category and tag fields', 'uams-mra'),
            'usermeta' => __('Reading user profile fields', 'uams-mra'),
            'commentmeta' => __('Reading comment fields', 'uams-mra'),
            'network' => __('Checking other sites in the network', 'uams-mra'),
            'finalize' => __('Measuring files and writing results', 'uams-mra'),
            'render' => __('Opening parent pages to see what they actually display', 'uams-mra'),
            'done' => __('Scan complete', 'uams-mra'),
        ];

        return $labels[$step] ?? $step;
    }

    /* ---------------------------------------------------------------------
     * Steps
     * ------------------------------------------------------------------ */

    /**
     * Build the filename index.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function step_index($state)
    {
        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $limit = (int) $state['settings']['batch_index'];

        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        [$done, $last_id] = UAMS_MRA_Index::build_batch((int) $state['cursor'], $limit);

        $state['cursor'] = $last_id;
        $state['processed'] += $done;

        if ($done < $limit) {
            return self::advance($state);
        }

        return $state;
    }

    /**
     * Scan post_content across every post type.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function step_posts($state)
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $posts_table */
        $posts_table = $wpdb->posts;

        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $limit = (int) $state['settings']['batch_posts'];
        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $cursor = (int) $state['cursor'];

        $exclude_revisions = empty($state['settings']['include_revisions'])
            ? "AND post_type != 'revision'"
            : '';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the fragment is one of two literals chosen from the scan settings.
        /** @var list<object{ID: string, post_type: string, post_title: string, post_status: string, post_content: string, post_excerpt: string}> $rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_type, post_title, post_status, post_content, post_excerpt
				 FROM {$posts_table}
				 WHERE ID > %d AND post_type != 'attachment' {$exclude_revisions}
				 ORDER BY ID ASC LIMIT %d",
                $cursor,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Without the early return the loop has no rows to read and the batch check below advances for the same reason, so the returned state is the same.
        // @pest-mutate-ignore:RemoveEarlyReturn
        if (empty($rows)) {
            return self::advance($state);
        }

        $refs = [];
        $offsite = [];
        $track = ! empty($state['settings']['include_offsite']);

        foreach ($rows as $row) {
            $cursor = (int) $row->ID;
            $content = $row->post_content."\n".$row->post_excerpt;

            // Content that is only whitespace holds no reference, so skipping it only saves the search.
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            // @pest-mutate-ignore:UnwrapTrim
            if (trim($content) === '') {
                continue;
            }

            $label = self::post_label($row);

            // Revisions are deliberately never rewritten, so recording their URLs
            // would list work that can never be done -- and every repoint saves a
            // revision holding the URLs it just removed, so the report would grow
            // each time it was acted on and never reach zero.
            if ($track && $row->post_type !== 'revision') {
                // collect_offsite() reads a numeric string as the same ID.
                // @pest-mutate-ignore:RemoveIntegerCast
                $offsite = array_merge(
                    $offsite,
                    self::collect_offsite($content, 'content', (int) $row->ID, $label)
                );
            }

            $ids = self::extract_ids_from_content($content);
            $paths = self::extract_paths($content);

            $strong = UAMS_MRA_Index::filter_attachment_ids($ids);
            $strong = array_merge($strong, UAMS_MRA_Index::resolve_paths($paths));

            // A repeated ID would be written twice, and the store ignores the second row as a duplicate.
            // @pest-mutate-ignore:UnwrapArrayUnique
            foreach (array_unique($strong) as $attachment_id) {
                // The value is stored in an integer column, so a numeric string is stored as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $refs[] = [
                    'attachment_id' => $attachment_id,
                    'source_type' => $row->post_type === 'revision' ? 'revision' : 'content',
                    'source_id' => (int) $row->ID,
                    'source_label' => $label,
                    'confidence' => 2,
                ];
            }
        }

        UAMS_MRA_Store::add_refs($refs);
        UAMS_MRA_Store::add_offsite($offsite);

        $state['cursor'] = $cursor;
        $state['processed'] += count($rows);

        if (count($rows) < $limit) {
            return self::advance($state);
        }

        return $state;
    }

    /**
     * Scan postmeta.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function step_postmeta($state)
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $posts_table */
        $posts_table = $wpdb->posts;
        /** @var literal-string $postmeta_table */
        $postmeta_table = $wpdb->postmeta;

        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $limit = (int) $state['settings']['batch_meta'];
        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $cursor = (int) $state['cursor'];

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names are `$wpdb` properties or `UAMS_MRA_Store` tables, and every value is bound through `prepare()`.
        /** @var list<object{meta_id: string, post_id: string, meta_key: string, meta_value: string, post_type: string|null, post_title: string|null, post_status: string|null}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_title, p.post_status
				 FROM {$postmeta_table} pm
				 LEFT JOIN {$posts_table} p ON p.ID = pm.post_id
				 WHERE pm.meta_id > %d
				 ORDER BY pm.meta_id ASC LIMIT %d",
                $cursor,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Without the early return the loop has no rows to read and the batch check below advances for the same reason, so the returned state is the same.
        // @pest-mutate-ignore:RemoveEarlyReturn
        if (empty($rows)) {
            return self::advance($state);
        }

        $skip_keys = self::skipped_meta_keys();
        $refs = [];
        $offsite = [];
        $track = ! empty($state['settings']['include_offsite']);

        foreach ($rows as $row) {
            $cursor = (int) $row->meta_id;

            if (isset($skip_keys[$row->meta_key])) {
                continue;
            }

            if ($row->post_type === 'revision' && empty($state['settings']['include_revisions'])) {
                continue;
            }

            // A value that is not an empty string is scanned and holds no reference, so skipping it only saves the search. A database value is already a string.
            // @pest-mutate-ignore:RemoveStringCast
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            if ((string) $row->meta_value === '') {
                continue;
            }

            $label = self::post_label($row);
            $label = $row->meta_key.' on '.$label;
            $source = $row->post_type === 'revision' ? 'revision_meta' : 'postmeta';

            if ($track && $source !== 'revision_meta') {
                // collect_offsite() reads a numeric string as the same ID.
                // @pest-mutate-ignore:RemoveIntegerCast
                $meta_id = (int) $row->meta_id;
                // The key reaches a string parameter, which coerces the same way.
                // @pest-mutate-ignore:RemoveStringCast
                $meta_key = (string) $row->meta_key;
                // The value is stored in an integer column, so a numeric string is stored as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $offsite = array_merge($offsite, self::collect_offsite($row->meta_value, $source, (int) $row->post_id, $label, $meta_id, $meta_key));
            }

            $hits = self::scan_meta_value(
                $row->meta_key,
                $row->meta_value,
                ! empty($state['settings']['include_numeric_meta'])
                    && self::source_allows_numeric_match($source)
            );

            foreach ($hits as $attachment_id => $confidence) {
                if ($attachment_id === (int) $row->post_id) {
                    continue;
                }

                // The value is stored in an integer column, so a numeric string is stored as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $refs[] = [
                    'attachment_id' => $attachment_id,
                    'source_type' => $source,
                    'source_id' => (int) $row->post_id,
                    'source_label' => $label,
                    'confidence' => $confidence,
                ];
            }
        }

        UAMS_MRA_Store::add_refs($refs);
        UAMS_MRA_Store::add_offsite($offsite);

        $state['cursor'] = $cursor;
        $state['processed'] += count($rows);

        if (count($rows) < $limit) {
            return self::advance($state);
        }

        return $state;
    }

    /**
     * Scan the options table for theme mods, widgets, logos, and icons.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function step_options($state)
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $options_table */
        $options_table = $wpdb->options;

        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $limit = (int) $state['settings']['batch_meta'];
        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $cursor = (int) $state['cursor'];

        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the patterns are constants with no user input; `%%` is a literal percent sign for `prepare()`.
        /** @var list<object{option_id: string, option_name: string, option_value: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_name, option_value
				 FROM {$options_table}
				 WHERE option_id > %d
				   AND option_name NOT LIKE '\_transient\_%%'
				   AND option_name NOT LIKE '\_site\_transient\_%%'
				   AND option_name NOT LIKE 'uams_mra\_%%'
				 ORDER BY option_id ASC LIMIT %d",
                $cursor,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

        // Without the early return the loop has no rows to read and the batch check below advances for the same reason, so the returned state is the same.
        // @pest-mutate-ignore:RemoveEarlyReturn
        if (empty($rows)) {
            return self::advance($state);
        }

        $refs = [];
        $offsite = [];
        $track = ! empty($state['settings']['include_offsite']);

        foreach ($rows as $row) {
            $cursor = (int) $row->option_id;

            // A value that is not an empty string is scanned and holds no reference, so skipping it only saves the search. A database value is already a string.
            // @pest-mutate-ignore:RemoveStringCast
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            if ((string) $row->option_value === '') {
                continue;
            }

            if ($track) {
                // The option row ID is stored in an integer column, so a numeric string is stored as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $option_pk = (int) $row->option_id;
                // The name reaches a string parameter, which coerces the same way.
                // @pest-mutate-ignore:RemoveStringCast
                $option_name = (string) $row->option_name;
                // The value is stored in an integer column, so a numeric string is stored as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $offsite = array_merge(
                    $offsite,
                    self::collect_offsite($row->option_value, 'option', (int) $row->option_id, 'Option: '.$row->option_name, $option_pk, $option_name)
                );
            }

            $hits = self::scan_meta_value(
                $row->option_name,
                $row->option_value,
                ! empty($state['settings']['include_numeric_meta'])
                    && self::source_allows_numeric_match('option')
            );

            foreach ($hits as $attachment_id => $confidence) {
                // The value is stored in an integer column, so a numeric string is stored as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $refs[] = [
                    'attachment_id' => $attachment_id,
                    'source_type' => 'option',
                    'source_id' => (int) $row->option_id,
                    'source_label' => 'Option: '.$row->option_name,
                    'confidence' => $confidence,
                ];
            }
        }

        UAMS_MRA_Store::add_refs($refs);
        UAMS_MRA_Store::add_offsite($offsite);

        $state['cursor'] = $cursor;
        $state['processed'] += count($rows);

        if (count($rows) < $limit) {
            return self::advance($state);
        }

        return $state;
    }

    /**
     * Scan term meta.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function step_termmeta($state)
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        return self::generic_meta_step($state, $wpdb->termmeta, 'meta_id', 'term_id', 'termmeta', 'Term');
    }

    /**
     * Scan user meta.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function step_usermeta($state)
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        return self::generic_meta_step($state, $wpdb->usermeta, 'umeta_id', 'user_id', 'usermeta', 'User');
    }

    /**
     * Scan comment meta.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function step_commentmeta($state)
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        return self::generic_meta_step($state, $wpdb->commentmeta, 'meta_id', 'comment_id', 'commentmeta', 'Comment');
    }

    /**
     * Shared scanner for the simpler meta tables.
     *
     * @param  array  $state  State.
     * @param  string  $table  Table name.
     * @param  string  $pk  Primary key column.
     * @param  string  $object_col  Object ID column.
     * @param  string  $source_type  Source type label.
     * @param  string  $prefix  Human prefix.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function generic_meta_step($state, $table, $pk, $object_col, $source_type, $prefix)
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $limit = (int) $state['settings']['batch_meta'];
        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $cursor = (int) $state['cursor'];

        /** @var literal-string $sql */
        $sql = "SELECT {$pk} AS pk, {$object_col} AS object_id, meta_key, meta_value
				FROM {$table} WHERE {$pk} > %d ORDER BY {$pk} ASC LIMIT %d";

        /** @var list<object{pk: string, object_id: string, meta_key: string, meta_value: string}> $rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $cursor, $limit));

        // Without the early return the loop has no rows to read and the batch check below advances for the same reason, so the returned state is the same.
        // @pest-mutate-ignore:RemoveEarlyReturn
        if (empty($rows)) {
            return self::advance($state);
        }

        $skip_keys = self::skipped_meta_keys();
        $refs = [];
        $offsite = [];
        $track = ! empty($state['settings']['include_offsite']);

        foreach ($rows as $row) {
            $cursor = (int) $row->pk;

            if (isset($skip_keys[$row->meta_key])) {
                continue;
            }

            // A value that is not an empty string is scanned and holds no reference, so skipping it only saves the search. A database value is already a string.
            // @pest-mutate-ignore:RemoveStringCast
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            if ((string) $row->meta_value === '') {
                continue;
            }

            // A numeric string prints the same digits.
            // @pest-mutate-ignore:RemoveIntegerCast
            $label = $prefix.' #'.(int) $row->object_id.': '.$row->meta_key;

            if ($track) {
                // collect_offsite() reads a numeric string as the same ID.
                // @pest-mutate-ignore:RemoveIntegerCast
                $object_id = (int) $row->object_id;
                // The row key is stored in an integer column, so a numeric string is stored as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $row_pk = (int) $row->pk;
                // The key reaches a string parameter, which coerces the same way.
                // @pest-mutate-ignore:RemoveStringCast
                $row_key = (string) $row->meta_key;
                $offsite = array_merge($offsite, self::collect_offsite($row->meta_value, $source_type, $object_id, $label, $row_pk, $row_key));
            }

            $hits = self::scan_meta_value(
                $row->meta_key,
                $row->meta_value,
                ! empty($state['settings']['include_numeric_meta'])
                    && self::source_allows_numeric_match($source_type)
            );

            foreach ($hits as $attachment_id => $confidence) {
                // The value is stored in an integer column, so a numeric string is stored as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $refs[] = [
                    'attachment_id' => $attachment_id,
                    'source_type' => $source_type,
                    'source_id' => (int) $row->object_id,
                    'source_label' => $label,
                    'confidence' => $confidence,
                ];
            }
        }

        UAMS_MRA_Store::add_refs($refs);
        UAMS_MRA_Store::add_offsite($offsite);

        $state['cursor'] = $cursor;
        $state['processed'] += count($rows);

        if (count($rows) < $limit) {
            return self::advance($state);
        }

        return $state;
    }

    /**
     * Scan sibling sites in a multisite network for URLs pointing at this site.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function step_network($state)
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $posts_table */
        $posts_table = $wpdb->posts;
        if (empty($state['site_queue'])) {
            return self::advance($state);
        }

        $target_prefix = $wpdb->prefix;
        $target_base = self::upload_path_prefixes();
        // switch_to_blog() and the stored reference read a numeric string as the same site ID.
        // @pest-mutate-ignore:RemoveIntegerCast
        $site_id = (int) $state['site_queue'][0];
        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $limit = (int) $state['settings']['batch_posts'];
        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $cursor = (int) $state['cursor'];

        switch_to_blog($site_id);

        UAMS_MRA_Store::set_prefix_override($target_prefix);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names are `$wpdb` properties or `UAMS_MRA_Store` tables, and every value is bound through `prepare()`.
        /** @var list<object{ID: string, post_type: string, post_title: string, post_status: string, post_content: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_type, post_title, post_status, post_content
				 FROM {$posts_table}
				 WHERE ID > %d AND post_type NOT IN ('attachment','revision')
				 ORDER BY ID ASC LIMIT %d",
                $cursor,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $refs = [];

        foreach ($rows as $row) {
            $cursor = (int) $row->ID;

            $paths = self::extract_paths($row->post_content, $target_base);
            if ($paths === []) {
                continue;
            }

            foreach (UAMS_MRA_Index::resolve_paths($paths) as $attachment_id) {
                // The value is stored in an integer column, so a numeric string is stored as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $refs[] = [
                    'attachment_id' => $attachment_id,
                    'source_type' => 'network',
                    'source_id' => (int) $row->ID,
                    'source_label' => get_bloginfo('name').': '.self::post_label($row),
                    'site_id' => $site_id,
                    'confidence' => 2,
                ];
            }
        }

        UAMS_MRA_Store::add_refs($refs);

        $exhausted = count($rows) < $limit;

        UAMS_MRA_Store::set_prefix_override(null);
        restore_current_blog();

        $state['processed'] += count($rows);

        if ($exhausted) {
            array_shift($state['site_queue']);
            $state['cursor'] = 0;
            if (empty($state['site_queue'])) {
                return self::advance($state);
            }

            return $state;
        }

        $state['cursor'] = $cursor;

        return $state;
    }

    /**
     * Write one batch of final results.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function step_finalize($state)
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $posts_table */
        $posts_table = $wpdb->posts;

        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $limit = (int) $state['settings']['batch_finalize'];
        // The setting or cursor reaches a %d placeholder, which reads a numeric string as the same integer, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveIntegerCast
        $cursor = (int) $state['cursor'];

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names are `$wpdb` properties or `UAMS_MRA_Store` tables, and every value is bound through `prepare()`.
        /** @var list<object{ID: string, post_parent: string, post_mime_type: string, post_date: string, post_title: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_parent, post_mime_type, post_date, post_title
				 FROM {$posts_table}
				 WHERE post_type = 'attachment' AND ID > %d
				 ORDER BY ID ASC LIMIT %d",
                $cursor,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (empty($rows)) {
            return self::advance($state);
        }

        /** @var list<string> $ids */
        $ids = wp_list_pluck($rows, 'ID');
        // The IDs reach a %d placeholder and get_post_meta(), which read a numeric string as the same integer.
        // @pest-mutate-ignore:UnwrapArrayMap
        $ids = array_map(intval(...), $ids);

        // One meta query for the whole batch instead of two per attachment.
        update_meta_cache('post', $ids);

        // Only the number of placeholders matters, because the start index of the filled array is dropped when the list is joined.
        // @pest-mutate-ignore:IncrementInteger
        // @pest-mutate-ignore:DecrementInteger
        /** @var literal-string $placeholders */
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $counts = [];
        /** @var literal-string $refs_table */
        $refs_table = UAMS_MRA_Store::refs_table();
        $sql = 'SELECT attachment_id, COUNT(*) AS total, SUM(CASE WHEN confidence >= 2 THEN 1 ELSE 0 END) AS strong
				   FROM '.$refs_table." WHERE attachment_id IN ({$placeholders}) GROUP BY attachment_id";
        /** @var list<object{attachment_id: string, total: string, strong: string}> $count_rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count_rows = $wpdb->get_results($wpdb->prepare($sql, $ids));
        foreach ($count_rows as $count_row) {
            // An array key reads a numeric string as the same integer.
            // @pest-mutate-ignore:RemoveIntegerCast
            $counted_id = (int) $count_row->attachment_id;
            // The count reaches a %d placeholder, which reads a numeric string as the same integer.
            // @pest-mutate-ignore:RemoveIntegerCast
            $counted_total = (int) $count_row->total;
            // The count reaches a %d placeholder, which reads a numeric string as the same integer.
            // @pest-mutate-ignore:RemoveIntegerCast
            $counted_strong = (int) $count_row->strong;
            $counts[$counted_id] = [$counted_total, $counted_strong];
        }

        $parent_statuses = [];
        // A zero or numeric string in the IN list matches nothing extra, so the list reaches the same parents.
        // @pest-mutate-ignore:UnwrapArrayMap
        // @pest-mutate-ignore:UnwrapArrayFilter
        /** @var list<string> $parent_values */
        $parent_values = wp_list_pluck($rows, 'post_parent');
        $parents = array_filter(array_map(intval(...), $parent_values));
        if ($parents !== []) {
            // A repeated ID in the IN list matches the same row, and the list keys are never read.
            // @pest-mutate-ignore:UnwrapArrayValues
            // @pest-mutate-ignore:UnwrapArrayUnique
            $parents = array_values(array_unique($parents));
            // Only the number of placeholders matters, because the start index of the filled array is dropped when the list is joined.
            // @pest-mutate-ignore:IncrementInteger
            // @pest-mutate-ignore:DecrementInteger
            /** @var literal-string $placeholders */
            $placeholders = implode(',', array_fill(0, count($parents), '%d'));
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholder list is built from `%d` and has one entry per integer bound.
            /** @var list<object{ID: string, post_status: string}> $parent_rows */
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $parent_rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_status FROM {$posts_table} WHERE ID IN ({$placeholders})", $parents));
            foreach ($parent_rows as $parent_row) {
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                // An array key reads a numeric string as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $parent_statuses[(int) $parent_row->ID] = $parent_row->post_status;
            }
        }

        /** @var list<literal-string> $values */
        $values = [];
        $args = [];
        $now = current_time('mysql');

        foreach ($rows as $row) {
            $cursor = (int) $row->ID;
            // The parent ID reaches a %d placeholder and an integer test, which read a numeric string the same way.
            // @pest-mutate-ignore:RemoveIntegerCast
            $parent = (int) $row->post_parent;
            // An array key reads a numeric string as the same integer.
            // @pest-mutate-ignore:RemoveIntegerCast
            $count = $counts[(int) $row->ID] ?? [0, 0];
            /** @var scalar|null $stored_file */
            // get_post_meta() reads a numeric string as the same ID.
            // @pest-mutate-ignore:RemoveIntegerCast
            $stored_file = get_post_meta((int) $row->ID, '_wp_attached_file', true);
            // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
            $file = (string) $stored_file;

            $values[] = '(%d,%d,%s,%d,%d,%d,%d,%s,%s,%s,%s,%s)';
            // The ID reaches a %d placeholder, which reads a numeric string as the same integer.
            // @pest-mutate-ignore:RemoveIntegerCast
            $args[] = (int) $row->ID;
            $args[] = $parent;
            $args[] = $parent ? ($parent_statuses[$parent] ?? 'missing') : '';
            $args[] = $parent && isset($parent_statuses[$parent]) ? 1 : 0;
            $args[] = $count[0];
            $args[] = $count[1];
            // attachment_bytes() reads a numeric string as the same ID.
            // @pest-mutate-ignore:RemoveIntegerCast
            $args[] = self::attachment_bytes((int) $row->ID, $file);
            // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
            $args[] = (string) $row->post_mime_type;
            // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
            $args[] = (string) $row->post_date;
            // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
            $args[] = mb_substr((string) $row->post_title, 0, 250);
            $args[] = mb_substr($file, 0, 250);
            $args[] = $now;
        }

        /** @var literal-string $results_table */
        $results_table = UAMS_MRA_Store::results_table();
        $sql = 'REPLACE INTO '.$results_table
            .' (attachment_id, parent_id, parent_status, is_attached, ref_count, ref_count_strict, bytes, mime, post_date, title, file, scanned_at) VALUES '
            .implode(',', $values);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the query is built from `UAMS_MRA_Store` table names and `%d` and `%s` placeholders only, and every value is bound through `prepare()`.
        /** @var string $statement */
        $statement = $wpdb->prepare($sql, $args);
        $wpdb->query($statement);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        $state['cursor'] = $cursor;
        $state['processed'] += count($rows);

        if (count($rows) < $limit) {
            return self::advance($state);
        }

        return $state;
    }

    /* ---------------------------------------------------------------------
     * Render verification
     * ------------------------------------------------------------------ */

    /**
     * Decide which parent pages are worth fetching.
     *
     * Only files that survived the whole database scan with no reference and a
     * live parent are in play, so the queue is the handful of pages that could
     * still explain them -- not every page on the site.
     *
     * A parent that cannot be fetched anonymously is settled here rather than
     * queued: its files are marked "skipped" so the report says the check never
     * ran, instead of leaving a blank that reads like a clean result.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function build_render_queue($state)
    {
        global $wpdb;
        /** @var wpdb $wpdb */
        /** @var literal-string $posts_table */
        $posts_table = $wpdb->posts;
        $results = UAMS_MRA_Store::results_table();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table names come from `$wpdb` or `UAMS_MRA_Store`, and every value is bound through `prepare()` or cast to an integer.
        /** @var list<object{parent_id: string, post_type: string, post_status: string, post_password: string, pending: string}> $rows */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT r.parent_id, p.post_type, p.post_status, p.post_password, COUNT(*) AS pending
			 FROM {$results} r
			 INNER JOIN {$posts_table} p ON p.ID = r.parent_id
			 WHERE r.ref_count = 0 AND r.is_attached = 1 AND r.parent_id > 0
			 GROUP BY r.parent_id, p.post_type, p.post_status, p.post_password
			 ORDER BY pending DESC, r.parent_id ASC"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $fetchable = [];
        $skipped = [];

        foreach ($rows as $row) {
            // A password-protected page returns the password form rather than its
            // content, which would report every file on it as absent.
            // The password column is already a string.
            // @pest-mutate-ignore:RemoveStringCast
            $readable = $row->post_status === 'publish'
                && (string) $row->post_password === ''
                && is_post_type_viewable($row->post_type);

            if ($readable) {
                $fetchable[] = (int) $row->parent_id;
            } else {
                // The parent ID reaches a %d placeholder, which reads a numeric string as the same integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $skipped[] = (int) $row->parent_id;
            }
        }

        if ($skipped !== []) {
            foreach (self::pending_attachment_ids($skipped) as $ids) {
                UAMS_MRA_Store::set_render_state($ids, 'skipped');
            }
        }

        $defaults = uams_mra_default_settings();
        // max() compares a numeric string as the same integer.
        // @pest-mutate-ignore:RemoveIntegerCast
        $max = max(1, (int) ($state['settings']['render_max_pages'] ?? $defaults['render_max_pages']));
        $over_cap = [];

        // A queue exactly as long as the ceiling slices to the same list with nothing over it, so the two comparisons give the same state.
        // @pest-mutate-ignore:GreaterToGreaterOrEqual
        if (count($fetchable) > $max) {
            $over_cap = array_slice($fetchable, $max);
            $fetchable = array_slice($fetchable, 0, $max);

            // Never let a ceiling read as a clean result. Files behind a page we
            // chose not to fetch are marked skipped exactly like the ones we
            // could not fetch, and the count is carried into the progress state.
            foreach (self::pending_attachment_ids($over_cap) as $ids) {
                UAMS_MRA_Store::set_render_state($ids, 'skipped');
            }
        }

        $state['render_queue'] = $fetchable;
        $state['render_total'] = count($fetchable);
        $state['render_skipped'] = count($skipped) + count($over_cap);
        $state['render_capped'] = count($over_cap);
        $state['render_stats'] = [
            'pages' => 0,
            'found' => 0,
            'absent' => 0,
            'error' => 0,
            'offsite' => 0,
        ];

        // One token for the whole scan, appended to every request so a page cache
        // cannot hand back a copy from before the content changed. A stale page
        // that still shows a since-removed file only keeps a file alive, but a
        // stale page missing a just-added file would mark a live file absent.
        $state['render_token'] = wp_generate_password(8, false, false);

        return $state;
    }

    /**
     * Files still unexplained, grouped by the parent they hang off.
     *
     * @param  array  $parent_ids  Parent post IDs.
     * @param  array<int, int|string>  $parent_ids
     * @return array<int, list<int>> parent_id => array of attachment IDs.
     */
    protected static function pending_attachment_ids(array $parent_ids): array
    {
        global $wpdb;
        /** @var wpdb $wpdb */

        // A repeated or numeric-string ID in the IN list matches the same rows, and the list keys are never read.
        // @pest-mutate-ignore:UnwrapArrayUnique
        // @pest-mutate-ignore:UnwrapArrayMap
        // @pest-mutate-ignore:UnwrapArrayValues
        $parent_ids = array_values(array_unique(array_filter(array_map(intval(...), $parent_ids))));
        // An empty list yields no chunks, so the loop below returns the same empty array.
        // @pest-mutate-ignore:RemoveEarlyReturn
        if ($parent_ids === []) {
            return [];
        }

        /** @var literal-string $results */
        $results = UAMS_MRA_Store::results_table();
        $out = [];

        // Any chunk size reads the same rows.
        // @pest-mutate-ignore:IncrementInteger
        // @pest-mutate-ignore:DecrementInteger
        $chunks = array_chunk($parent_ids, 200);

        foreach ($chunks as $chunk) {
            // Only the number of placeholders matters, because the start index of the filled array is dropped when the list is joined.
            // @pest-mutate-ignore:IncrementInteger
            // @pest-mutate-ignore:DecrementInteger
            /** @var literal-string $placeholders */
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $sql = "SELECT attachment_id, parent_id FROM {$results}
				WHERE ref_count = 0 AND is_attached = 1 AND parent_id IN ({$placeholders})";
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the query holds `UAMS_MRA_Store` table names and one `%d` per bound integer.
            /** @var list<object{attachment_id: string, parent_id: string}> $pending_rows */
            $pending_rows = $wpdb->get_results($wpdb->prepare($sql, $chunk));
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
            foreach ($pending_rows as $row) {
                $out[$row->parent_id][] = (int) $row->attachment_id;
            }
        }

        // PHP stores a numeric string key as the same integer.
        /** @var array<int, list<int>> $keyed */
        $keyed = $out;

        return $keyed;
    }

    /**
     * Fetch a batch of parent pages and read what they actually render.
     *
     * This is the only step that leaves the database. A page template that lists
     * its own attachments, a shortcode, a block that builds its markup at render
     * time, a URL hardcoded in a theme file -- none of them put anything in a
     * table for the earlier steps to find, and all of them show up here.
     *
     * @param  array  $state  State.
     * @param  ScanState  $state
     * @return ScanState
     */
    protected static function step_render($state)
    {
        if (empty($state['render_queue'])) {
            return self::advance($state);
        }

        $defaults = uams_mra_default_settings();
        // max() and min() compare a numeric string as the same integer.
        // @pest-mutate-ignore:RemoveIntegerCast
        $limit = max(1, min(25, (int) ($state['settings']['batch_render'] ?? $defaults['batch_render'])));
        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $token = (string) ($state['render_token'] ?? '');
        $batch = array_splice($state['render_queue'], 0, $limit);
        $stats = isset($state['render_stats']) && is_array($state['render_stats'])
            ? $state['render_stats']
            : [
                'pages' => 0,
                'found' => 0,
                'absent' => 0,
                'error' => 0,
                'offsite' => 0,
            ];

        foreach ($batch as $parent_id) {
            // Re-read per page rather than once per batch. An earlier page in
            // this same batch may have cleared a file that hangs off a later
            // parent, and a stale list would then mark that live file absent.
            $pending = self::pending_attachment_ids([$parent_id]);
            $pending = $pending[$parent_id] ?? [];

            if (empty($pending)) {
                continue;
            }

            $url = get_permalink($parent_id);

            if (! $url) {
                UAMS_MRA_Store::set_render_state($pending, 'skipped');

                continue;
            }

            $body = self::fetch_rendered($url, $token);
            $stats['pages']++;

            if ($body === null) {
                UAMS_MRA_Store::set_render_state($pending, 'error', $url);
                $stats['error'] += count($pending);

                continue;
            }

            $found = UAMS_MRA_Index::resolve_paths(self::extract_paths($body));

            if ($found !== []) {
                $refs = [];
                $label = __('Rendered page: ', 'uams-mra').get_the_title($parent_id);

                foreach ($found as $attachment_id) {
                    // The value is stored in an integer column, so a numeric string is stored as the same integer.
                    // @pest-mutate-ignore:RemoveIntegerCast
                    $refs[] = [
                        'attachment_id' => $attachment_id,
                        'source_type' => 'render',
                        'source_id' => (int) $parent_id,
                        'source_label' => $label,
                        'confidence' => 2,
                    ];
                }

                UAMS_MRA_Store::add_refs($refs);

                // The page was parsed whole, so a file that belongs to some other
                // parent but is displayed here gets cleared too, for free.
                UAMS_MRA_Store::recount_refs($found);
            }

            // The list is only counted and bound as placeholders, so its keys are never read.
            // @pest-mutate-ignore:UnwrapArrayValues
            $here = array_values(array_intersect($pending, $found));
            // The list is only counted and bound as placeholders, so its keys are never read.
            // @pest-mutate-ignore:UnwrapArrayValues
            $unmatched = array_values(array_diff($pending, $found));

            // Whatever this page did not serve from our own uploads, check
            // against every other host it pulls uploads from. Precedence runs
            // found, then offsite, then absent: the strongest evidence wins.
            $offsite = [];

            if ($unmatched !== []) {
                $offsite = array_intersect_key(self::extract_offsite_uploads($body), array_flip($unmatched));
            }

            // The list is only counted and bound as placeholders, so its keys are never read.
            // @pest-mutate-ignore:UnwrapArrayValues
            $absent = array_values(array_diff($unmatched, array_keys($offsite)));

            UAMS_MRA_Store::set_render_state($here, 'found', $url);
            UAMS_MRA_Store::set_render_state($absent, 'absent', $url);

            foreach (self::group_by_host($offsite) as $host => $ids) {
                UAMS_MRA_Store::set_render_state(
                    $ids,
                    'offsite',
                    $url,
                    /* translators: %s: hostname serving a file of the same name. */
                    sprintf(__('Served from %s', 'uams-mra'), $host)
                );
            }

            $stats['found'] += count($here);
            $stats['absent'] += count($absent);
            $stats['offsite'] += count($offsite);
        }

        $state['render_stats'] = $stats;
        $state['processed'] += count($batch);

        if (empty($state['render_queue'])) {
            return self::advance($state);
        }

        return $state;
    }

    /**
     * Flip an id => host map into host => ids.
     *
     * @param  array  $offsite  attachment_id => host.
     * @param  array<int, string>  $offsite
     * @return array<string, list<int>> host => array of attachment IDs.
     */
    protected static function group_by_host(array $offsite): array
    {
        $out = [];

        foreach ($offsite as $id => $host) {
            // Array keys are always integers or strings of digits, and a key read back from a map is an integer already.
            // @pest-mutate-ignore:RemoveIntegerCast
            $out[$host][] = (int) $id;
        }

        return $out;
    }

    /**
     * Uploads URLs on a page that belong to some *other* host or site.
     *
     * The database passes look for this site's own upload prefix, which is the
     * right rule for stored content: a URL pointing at another site's uploads is
     * a reference to that site's file, not ours. A rendered page is a different
     * question. When a page serves Foo.pdf from an old host and our library also
     * holds Foo.pdf, our copy is not what the visitor loads -- but it is very
     * obviously not abandoned either, and calling it unused would be how a
     * half-finished migration turns into deleted originals.
     *
     * So these are reported separately rather than counted as references. The
     * file keeps its place in the Verify tier and the report names the host, for
     * a person to decide whether the two files are the same document.
     *
     * @param  string  $body  Rendered HTML.
     * @return array<int, string> attachment_id => host serving it.
     */
    protected static function extract_offsite_uploads($body): array
    {
        $map = [];

        foreach (self::extract_foreign_upload_urls($body) as $found) {
            $map[$found['base_norm']] ??= $found['host'];
        }

        if ($map === []) {
            return [];
        }

        $out = [];

        foreach (UAMS_MRA_Index::resolve_map(array_keys($map)) as $base_norm => $ids) {
            foreach ($ids as $id) {
                $out[$id] = $map[$base_norm];
            }
        }

        return $out;
    }

    /**
     * Every uploads URL in a string that points somewhere other than this site.
     *
     * Shared by the render check, which asks whether a page serves a file of the
     * same name from elsewhere, and the offsite report, which asks where in the
     * database those URLs are stored so they can be migrated.
     *
     * @param  string  $content  Content to read.
     * @return list<array{url: string, host: string, path: string, base_norm: string}> List of array{url, host, path, base_norm}, deduplicated by URL.
     */
    public static function extract_foreign_upload_urls($content): array
    {
        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $content = str_replace(['\\/', '&#047;', '&#x2F;'], '/', (string) $content);

        // Skipping this test only lets a string with no uploads path reach the match below, which finds nothing and returns the same empty list.
        // The early return is the same case: the match below returns that list anyway.
        // @pest-mutate-ignore:FalseToTrue
        // @pest-mutate-ignore:RemoveEarlyReturn
        if (stripos($content, 'wp-content/uploads/') === false) {
            return [];
        }

        $pattern = '#(?:https?:)?//([^/"\'<>\s]+)/[^"\'<>\s)]*?wp-content/uploads/([^\s"\'<>\\\\()\[\]{},;]+\.[a-zA-Z0-9]{2,5})#i';

        if (! preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $ours = self::upload_path_prefixes();
        $hosts = self::own_hosts();
        $base = self::own_uploads_path();
        $out = [];

        foreach ($matches as $match) {
            $full = $match[0];
            $host = strtolower($match[1]);
            $path = $match[2];

            // Anything under this site's own uploads, or under a prefix the
            // settings already claim as ours, is handled by extract_paths().
            //
            // The site's own uploads path is the same on most WordPress installs
            // (wp-content/uploads), so on its own it says nothing about whose file
            // a URL names: another host serving the same layout is the offsite
            // case this report exists for. It counts as ours only on this site's
            // host. A prefix named in the settings is claimed on any host, since
            // that is how a CDN or an offload bucket is declared.
            $is_ours = false;
            // Once a prefix claims the URL, the rest of the loop can only set the same flag again.
            // @pest-mutate-ignore:BreakToContinue
            foreach ($ours as $prefix) {
                // upload_path_prefixes() drops empty prefixes, so the empty-string arm is never taken.
                // @pest-mutate-ignore:EmptyStringToNotEmpty
                if ($prefix === '' || stripos($full, $prefix.'/') === false) {
                    continue;
                }

                if ($prefix === $base && ! in_array($host, $hosts, true)) {
                    continue;
                }

                $is_ours = true;
                break;
            }

            if ($is_ours || isset($out[$full])) {
                continue;
            }

            [, $base_norm] = UAMS_MRA_Index::normalize($path);

            // normalize() returns a non-empty name for any path this pattern can match, so the empty test is a guard that never fires.
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            if ($base_norm === '') {
                continue;
            }

            $out[$full] = [
                'url' => str_starts_with($full, '//') ? 'https:'.$full : $full,
                'host' => $host,
                'path' => $path,
                'base_norm' => $base_norm,
            ];
        }

        return array_values($out);
    }

    /**
     * Offsite rows for one piece of stored content.
     *
     * @param  string  $content  Content to read.
     * @param  string  $source_type  Where it came from.
     * @param  int  $source_id  Row ID in that source.
     * @param  string  $label  Readable source name.
     * @param  int  $source_pk
     * @param  string  $source_key
     * @return list<array<string, int|string>> Rows for UAMS_MRA_Store::add_offsite().
     */
    protected static function collect_offsite($content, $source_type, $source_id, $label, $source_pk = 0, $source_key = ''): array
    {
        $rows = [];

        foreach (self::extract_foreign_upload_urls($content) as $found) {
            $rows[] = [
                'url' => $found['url'],
                'host' => $found['host'],
                'base_norm' => $found['base_norm'],
                'source_type' => $source_type,
                'source_id' => $source_id,
                // The row to write back to, and the key inside it. source_id
                // names the object a person would recognise; these two name the
                // exact row, which is what a rewrite has to target.
                'source_pk' => $source_pk ?: $source_id,
                'source_key' => $source_key,
                'source_label' => $label,
            ];
        }

        return $rows;
    }

    /**
     * Fetch one page and hand back its HTML, or null if it could not be read.
     *
     * Null means unknown, never empty. A timeout, a redirect to a login screen, a
     * 500 -- any of those must leave the file in the tier that asks a person to
     * look, because treating a failed request as "not on the page" would delete
     * live files whenever the site was briefly unreachable.
     *
     * @param  string  $url  Permalink.
     * @param  string  $token  Per scan cache-busting token.
     */
    protected static function fetch_rendered($url, $token): ?string
    {
        /** @var string $version */
        $version = UAMS_MRA_VERSION;
        $request_url = $url;

        if ($token && apply_filters('uams_mra_render_cache_bust', true, $url)) {
            $request_url = add_query_arg('uams-mra-check', $token, $url);
        }

        /** @var array{timeout: int, redirection: int, sslverify: bool, user-agent: string, headers: array<string, string>} $args */
        $args = apply_filters(
            'uams_mra_render_request_args',
            [
                'timeout' => 15,
                'redirection' => 3,
                'sslverify' => apply_filters('uams_mra_render_sslverify', true),
                'user-agent' => 'UAMS-Media-Reference-Audit/'.$version.' (+'.home_url().')',
                'headers' => [
                    'Cache-Control' => 'no-cache',
                    'Pragma' => 'no-cache',
                ],
            ],
            $url
        );

        $response = wp_remote_get($request_url, $args);

        // A failed request has no response code, so the check below returns the same null.
        // @pest-mutate-ignore:RemoveEarlyReturn
        if (is_wp_error($response)) {
            return null;
        }

        // The response code is already an integer.
        // @pest-mutate-ignore:RemoveIntegerCast
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $body = (string) wp_remote_retrieve_body($response);

        return trim($body) === '' ? null : $body;
    }

    /* ---------------------------------------------------------------------
     * Extraction helpers
     * ------------------------------------------------------------------ */

    /**
     * Pattern for keys that hold an ID belonging to something other than an
     * attachment -- a user, a term, a comment, a form entry.
     *
     * Only the weak numeric match is withheld from these, never a strong one, so
     * a key on this list that really does contain an upload URL still counts.
     *
     * Keep it to names that state the object type outright. A key that could
     * plausibly hold a media ID -- image_id, media_id, attachment_id,
     * thumbnail_id -- must stay off it, or a live file starts reporting as
     * unreferenced.
     *
     * @return string Regex tested against the key, or '' to disable the check.
     */
    protected static function foreign_id_key_pattern(): string
    {
        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        /** @var scalar|null $pattern */
        $pattern = apply_filters(
            'uams_mra_foreign_id_key_pattern',
            '#(^|_)(user|author|customer|post|page|parent|term|category|tag|comment|order|site|blog|form|entry)_id$#i'
        );

        return (string) $pattern;
    }

    /**
     * Does this key name an ID belonging to something other than an attachment.
     *
     * @param  string  $key  Meta or option name.
     */
    protected static function is_foreign_id_key($key): bool
    {
        $pattern = self::foreign_id_key_pattern();

        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        return $pattern !== '' && preg_match($pattern, (string) $key) === 1;
    }

    /**
     * Which source types may earn a weak numeric match.
     *
     * usermeta is off. WordPress and its plugins park screen preferences,
     * counters, and progress blobs there -- gform_recent_forms, _sfwd-quizzes,
     * users_network_per_page -- and a bare number in one of those means nothing
     * to the media library. Measured against a real network: of 69 weak usermeta
     * matches, none were a genuine reference, while every genuine one came from
     * postmeta or an option.
     *
     * This withholds only the weak match. An upload URL or a JSON "id" key in
     * usermeta is strong evidence and is still recorded.
     *
     * Sites that store a user's photo as a bare attachment ID -- an ACF user
     * field -- should switch usermeta back on through the filter.
     *
     * @param  string  $source_type  Source type being scanned.
     */
    protected static function source_allows_numeric_match($source_type): bool
    {
        /** @var array<string, bool> $allowed */
        $allowed = apply_filters(
            'uams_mra_numeric_match_sources',
            [
                'postmeta' => true,
                'revision_meta' => true,
                'option' => true,
                'termmeta' => true,
                'commentmeta' => true,
                'usermeta' => false,
            ]
        );

        return ! empty($allowed[$source_type]);
    }

    /**
     * Meta keys that describe the attachment itself and must never count as a reference.
     *
     * @return array<string, int> Each skipped key mapped to its position, for `isset()` lookups.
     */
    protected static function skipped_meta_keys(): array
    {
        /** @var list<string> $keys */
        $keys = apply_filters(
            'uams_mra_skipped_meta_keys',
            [
                '_wp_attached_file',
                '_wp_attachment_metadata',
                '_wp_attachment_backup_sizes',
                '_wp_attachment_image_alt',
                '_wp_attachment_is_custom_header',
                '_edit_lock',
                '_edit_last',
                '_wp_old_slug',
                '_wp_old_date',
                '_wp_trash_meta_status',
                '_wp_trash_meta_time',
                '_wp_desired_post_slug',
                // This plugin's own triage. The note is free text, so a note
                // that quotes an upload URL would otherwise be recorded as a
                // strong reference to whatever file it names.
                UAMS_MRA_Triage::META_DISPOSITION,
                UAMS_MRA_Triage::META_NOTE,
            ]
        );

        return array_flip($keys);
    }

    /**
     * Path of this site's uploads folder, without slashes at either end.
     */
    protected static function own_uploads_path(): string
    {
        /** @var array{basedir: string, baseurl: string} $uploads */
        $uploads = wp_get_upload_dir();

        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $baseurl = (string) $uploads['baseurl'];

        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        return trim((string) wp_parse_url($baseurl, PHP_URL_PATH), '/');
    }

    /**
     * Hosts that count as this site: where uploads are served, and the site itself.
     *
     * @return array<int, string>
     */
    protected static function own_hosts(): array
    {
        /** @var array{basedir: string, baseurl: string} $uploads */
        $uploads = wp_get_upload_dir();
        $hosts = [];

        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $urls = [(string) $uploads['baseurl'], home_url(), site_url()];

        foreach ($urls as $url) {
            // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Upload path prefixes used to spot media URLs.
     *
     * @return list<string>
     */
    public static function upload_path_prefixes(): array
    {
        /** @var array{basedir: string, baseurl: string} $uploads */
        $uploads = wp_get_upload_dir();
        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $base = (string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH);
        $base = trim($base, '/');

        $prefixes = [$base];

        $settings = uams_mra_settings();
        // preg_split() returns false only on a regex error, which this fixed pattern cannot raise.
        /** @var list<string> $extras */
        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $extras = preg_split('#[\r\n]+#', (string) $settings['extra_paths']);
        foreach ($extras as $extra) {
            $extra = trim(trim($extra), '/');
            // array_filter() below drops an empty prefix whether or not this test does.
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            if ($extra !== '') {
                $prefixes[] = $extra;
            }
        }

        return array_values(array_unique(array_filter($prefixes)));
    }

    /**
     * Pull upload-relative file paths out of arbitrary text.
     *
     * @param  string  $content  Text to scan.
     * @param  array  $prefixes  Upload path prefixes. Defaults to the current site.
     * @param  list<string>|null  $prefixes
     * @return list<string>
     */
    public static function extract_paths($content, $prefixes = null): array
    {
        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $content = (string) $content;

        if (! str_contains($content, '/')) {
            return [];
        }

        // JSON and slash-escaped content stores paths as \/ sequences.
        $content = str_replace(['\\/', '&#047;', '&#x2F;'], '/', $content);

        $prefixes ??= self::upload_path_prefixes();

        $found = [];

        foreach ($prefixes as $prefix) {
            // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
            // Skipping this test only runs the match below on a prefix the text lacks, which finds nothing.
            // @pest-mutate-ignore:FalseToTrue
            if (stripos($content, (string) $prefix) === false) {
                continue;
            }

            // Greedy on purpose. Non-greedy stops at the first dot-extension, so
            // Plan-2.11.25-Final.pdf would resolve to "Plan-2.11" and the linked
            // file would report as unreferenced. normalize() drops any query
            // string the greedy match runs into.
            $pattern = '#'.preg_quote($prefix, '#').'/([^\s"\'<>\\\\()\[\]{},;]+\.[a-zA-Z0-9]{2,5})#i';

            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $path) {
                    // On a subdirectory main site the uploads prefix also matches
                    // child site paths, so drop anything under sites/.
                    if (stripos($path, 'sites/') === 0) {
                        continue;
                    }

                    $found[] = $path;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Pull attachment IDs out of post content.
     *
     * @param  string  $content  Post content.
     * @return list<int>
     */
    public static function extract_ids_from_content($content): array
    {
        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $content = (string) $content;
        $ids = [];

        // Classic editor image classes and caption shortcodes.
        if (preg_match_all('#wp-image-(\d+)#i', $content, $m)) {
            $ids = array_merge($ids, $m[1]);
        }

        if (preg_match_all('#wp-att-(\d+)#i', $content, $m)) {
            $ids = array_merge($ids, $m[1]);
        }

        if (preg_match_all('#attachment_(\d+)#i', $content, $m)) {
            $ids = array_merge($ids, $m[1]);
        }

        if (preg_match_all('#attachment[_-]?id["\']?\s*[=:]\s*["\']?(\d+)#i', $content, $m)) {
            $ids = array_merge($ids, $m[1]);
        }

        // Shortcode ID lists: [gallery ids="1,2,3"].
        if (preg_match_all('#\bids\s*=\s*["\']([\d,\s]+)["\']#i', $content, $m)) {
            foreach ($m[1] as $list) {
                /** @var list<string> $parts */
                // A blank entry becomes 0, which array_filter() removes at the end.
                // @pest-mutate-ignore:UnwrapTrim
                $parts = preg_split('#[\s,]+#', trim($list));
                $ids = array_merge($ids, $parts);
            }
        }

        // Block comments carry their attachment IDs as JSON.
        if (str_contains($content, '<!-- wp:') && preg_match_all('#<!--\s+/?wp:.*?-->#s', $content, $blocks)) {
            foreach ($blocks[0] as $block) {
                if (preg_match_all('#"id"\s*:\s*(\d+)#', $block, $bm)) {
                    $ids = array_merge($ids, $bm[1]);
                }

                if (preg_match_all('#"ids"\s*:\s*\[([\d,\s]*)\]#', $block, $bm)) {
                    foreach ($bm[1] as $list) {
                        /** @var list<string> $parts */
                        // A blank entry becomes 0, which array_filter() removes at the end.
                        // @pest-mutate-ignore:UnwrapTrim
                        $parts = preg_split('#[\s,]+#', trim($list));
                        $ids = array_merge($ids, $parts);
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(array_map(intval(...), $ids))));
    }

    /**
     * Inspect a single meta or option value.
     *
     * @param  string  $key  Meta or option name.
     * @param  string  $value  Raw stored value.
     * @param  bool  $allow_numeric  Treat bare numeric values matching an attachment as a reference.
     * @return array<int, int> attachment_id => confidence
     */
    public static function scan_meta_value($key, $value, $allow_numeric = true): array
    {
        // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $value = (string) $value;
        $hits = [];

        // Strong: featured image.
        if ($key === '_thumbnail_id' && ctype_digit(trim($value))) {
            foreach (UAMS_MRA_Index::filter_attachment_ids([(int) $value]) as $id) {
                $hits[$id] = 2;
            }

            return $hits;
        }

        // Strong: any upload URL in the value.
        $paths = self::extract_paths($value);
        if ($paths !== []) {
            foreach (UAMS_MRA_Index::resolve_paths($paths) as $id) {
                $hits[$id] = 2;
            }
        }

        // Strong: JSON style id and ids keys, used by most page builders.
        $json_ids = [];
        if (str_contains($value, '"')) {
            if (preg_match_all('#"(?:id|ids|image_id|attachment_id|thumbnail_id|media_id)"\s*:\s*(\d+)#i', $value, $m)) {
                $json_ids = array_merge($json_ids, $m[1]);
            }

            if (preg_match_all('#"ids"\s*:\s*\[([\d,\s]*)\]#', $value, $m)) {
                foreach ($m[1] as $list) {
                    /** @var list<string> $parts */
                    $parts = preg_split('#[\s,]+#', trim($list));
                    $json_ids = array_merge($json_ids, $parts);
                }
            }
        }

        if ($json_ids !== []) {
            foreach (UAMS_MRA_Index::filter_attachment_ids($json_ids) as $id) {
                $hits[$id] = 2;
            }
        }

        // A key whose name says it holds some other object's ID -- source_user_id,
        // parent_id -- can still hold a number that collides with a real
        // attachment ID. Refuse it the weak numeric match. Strong evidence in the
        // same value, an upload URL or a JSON "id" key, was already collected
        // above and stands.
        if (! $allow_numeric || self::is_foreign_id_key($key)) {
            return $hits;
        }

        // Weak: bare IDs and serialized ID lists, the ACF and page builder pattern.
        $candidates = [];
        $trimmed = trim($value);

        if (ctype_digit($trimmed) && strlen($trimmed) < 12) {
            $candidates[] = (int) $trimmed;
        } elseif (preg_match('#^[\d,\s]+$#', $trimmed) && strlen($trimmed) < 500) {
            /** @var list<string> $parts */
            $parts = preg_split('#[\s,]+#', $trimmed);
            $candidates = array_merge($candidates, $parts);
        } elseif (is_serialized($trimmed)) {
            $data = @unserialize($trimmed); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if (is_array($data)) {
                $candidates = array_merge($candidates, self::collect_ids_from_array($data));
            }
        }

        foreach (UAMS_MRA_Index::filter_attachment_ids($candidates) as $id) {
            $hits[$id] ??= 1;
        }

        return $hits;
    }

    /**
     * Walk an unserialized array collecting plausible attachment IDs.
     *
     * @param  array  $data  Data.
     * @param  int  $depth  Recursion depth.
     * @param  array<mixed>  $data
     * @return array<int, int>
     */
    protected static function collect_ids_from_array($data, $depth = 0): array
    {
        if ($depth > 6) {
            return [];
        }

        $ids = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $ids = array_merge($ids, self::collect_ids_from_array($value, $depth + 1));

                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
            $value = trim((string) $value);

            // An empty value fails ctype_digit() on the next test, so the first test is redundant.
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            if ($value === '' || ! ctype_digit($value) || strlen($value) > 11) {
                continue;
            }

            // Numeric keys inside a list of IDs, or a key that reads like a media field.
            // An integer key is handled by the test before it, so only string keys reach this match.
            // @pest-mutate-ignore:RemoveStringCast
            if (is_int($key) || preg_match('#(id|logo|image|img|icon|media|attachment|thumb|photo|gallery|file|banner|header|background)#i', (string) $key)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /**
     * Readable source label for a post row.
     *
     * @param  object{post_title?: string|null, post_type?: string|null, post_status?: string|null}  $row  Row with post_type, post_title, post_status.
     */
    protected static function post_label($row): string
    {
        $title = isset($row->post_title) && $row->post_title !== '' ? $row->post_title : __('(no title)', 'uams-mra');
        $type = $row->post_type ?? 'post';
        $status = $row->post_status ?? '';

        $label = $type.': '.$title;

        if ($status && ! in_array($status, ['publish', 'inherit'], true)) {
            $label .= ' ('.$status.')';
        }

        return $label;
    }

    /**
     * Total bytes on disk for an attachment, including every generated size.
     *
     * @param  int  $attachment_id  Attachment ID.
     * @param  string  $file  Relative file from _wp_attached_file.
     */
    public static function attachment_bytes($attachment_id, $file = ''): int
    {
        /** @var array{basedir: string, baseurl: string} $uploads */
        $uploads = wp_get_upload_dir();
        $basedir = trailingslashit($uploads['basedir']);

        if ($file === '') {
            /** @var scalar|null $stored_file */
            $stored_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            // The value is already a string here, or PHP coerces it the same way, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
            $file = (string) $stored_file;
        }

        if ($file === '') {
            return 0;
        }

        $bytes = 0;
        $subdir = trailingslashit(dirname($file));
        // A leading "./" in the path resolves to the same file on disk.
        // @pest-mutate-ignore:UnwrapTrim
        $subdir = (trim($subdir, '/') === '.') ? '' : $subdir;

        $targets = [$file];

        $meta = wp_get_attachment_metadata($attachment_id);

        if (is_array($meta)) {
            /** @var array{original_image?: string, sizes?: mixed} $meta */
            if (! empty($meta['original_image'])) {
                $targets[] = $subdir.$meta['original_image'];
            }

            if (! empty($meta['sizes']) && is_array($meta['sizes'])) {
                /** @var array<array{file?: string}> $sizes */
                $sizes = $meta['sizes'];

                foreach ($sizes as $size) {
                    if (! empty($size['file'])) {
                        $targets[] = $subdir.$size['file'];
                    }
                }
            }
        }

        foreach (array_unique($targets) as $target) {
            // A doubled slash in the path resolves to the same file on disk.
            // @pest-mutate-ignore:UnwrapLtrim
            $path = $basedir.ltrim($target, '/');
            if (is_readable($path) && is_file($path)) {
                // filesize() already returns an integer.
                // @pest-mutate-ignore:RemoveIntegerCast
                $bytes += (int) filesize($path);
            }
        }

        return $bytes;
    }
}
