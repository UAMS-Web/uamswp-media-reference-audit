<?php

/**
 * Batched reference scanner.
 */
defined('ABSPATH') || exit;

/**
 * Walks every content source and records which attachments are referenced.
 */
class UAMS_MRA_Scanner
{
    const STATE_OPTION = 'uams_mra_scan_state';

    /**
     * Ordered pipeline.
     *
     * @var array
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
    ];

    /**
     * Current scan state.
     */
    public static function get_state(): array
    {
        $state = get_option(self::STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    /**
     * Persist scan state.
     *
     * @param  array  $state  State.
     */
    public static function set_state($state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * Start a fresh scan.
     *
     * @return array State.
     */
    public static function start(): array
    {
        global $wpdb;

        UAMS_MRA_Store::reset();

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");

        $state = [
            'step' => 'index',
            'cursor' => 0,
            'processed' => 0,
            'attachments' => $total,
            'started' => time(),
            'finished' => 0,
            'site_queue' => [],
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
     * @return array State.
     */
    public static function step()
    {
        $state = self::get_state();

        if ($state === [] || 'done' === ($state['step'] ?? '')) {
            return $state;
        }

        $method = 'step_'.$state['step'];

        if (! method_exists(self::class, $method)) {
            return self::advance($state);
        }

        $state = self::$method($state);

        self::set_state($state);

        return $state;
    }

    /**
     * Move to the next step in the pipeline.
     *
     * @param  array  $state  State.
     * @return array
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
        ];

        if (! empty($skip[$state['step']])) {
            return self::advance($state);
        }

        if ($state['step'] === 'network') {
            $sites = get_sites(
                [
                    'number' => 500,
                    'fields' => 'ids',
                    'site__not_in' => [get_current_blog_id()],
                ]
            );
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
     * @return array
     */
    protected static function step_index($state)
    {
        $limit = (int) $state['settings']['batch_index'];

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
     * @return array
     */
    protected static function step_posts($state)
    {
        global $wpdb;

        $limit = (int) $state['settings']['batch_posts'];
        $cursor = (int) $state['cursor'];

        $exclude_revisions = empty($state['settings']['include_revisions'])
            ? "AND post_type != 'revision'"
            : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_type, post_title, post_status, post_content, post_excerpt
				 FROM {$wpdb->posts}
				 WHERE ID > %d AND post_type != 'attachment' {$exclude_revisions}
				 ORDER BY ID ASC LIMIT %d",
                $cursor,
                $limit
            )
        );

        if (empty($rows)) {
            return self::advance($state);
        }

        $refs = [];

        foreach ($rows as $row) {
            $cursor = (int) $row->ID;
            $content = $row->post_content."\n".$row->post_excerpt;

            if (trim($content) === '') {
                continue;
            }

            $label = self::post_label($row);

            $ids = self::extract_ids_from_content($content);
            $paths = self::extract_paths($content);

            $strong = UAMS_MRA_Index::filter_attachment_ids($ids);
            $strong = array_merge($strong, UAMS_MRA_Index::resolve_paths($paths));

            foreach (array_unique($strong) as $attachment_id) {
                if ((int) $attachment_id === (int) $row->ID) {
                    continue;
                }

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
     * @return array
     */
    protected static function step_postmeta($state)
    {
        global $wpdb;

        $limit = (int) $state['settings']['batch_meta'];
        $cursor = (int) $state['cursor'];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_title, p.post_status
				 FROM {$wpdb->postmeta} pm
				 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_id > %d
				 ORDER BY pm.meta_id ASC LIMIT %d",
                $cursor,
                $limit
            )
        );

        if (empty($rows)) {
            return self::advance($state);
        }

        $skip_keys = self::skipped_meta_keys();
        $refs = [];

        foreach ($rows as $row) {
            $cursor = (int) $row->meta_id;

            if (isset($skip_keys[$row->meta_key])) {
                continue;
            }

            if ($row->post_type === 'revision' && empty($state['settings']['include_revisions'])) {
                continue;
            }

            if ((string) $row->meta_value === '') {
                continue;
            }

            $label = self::post_label($row);
            $label = $row->meta_key.' on '.$label;
            $source = $row->post_type === 'revision' ? 'revision_meta' : 'postmeta';

            $hits = self::scan_meta_value(
                $row->meta_key,
                $row->meta_value,
                ! empty($state['settings']['include_numeric_meta'])
                    && self::source_allows_numeric_match($source)
            );

            foreach ($hits as $attachment_id => $confidence) {
                if ((int) $attachment_id === (int) $row->post_id) {
                    continue;
                }

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
     * @return array
     */
    protected static function step_options($state)
    {
        global $wpdb;

        $limit = (int) $state['settings']['batch_meta'];
        $cursor = (int) $state['cursor'];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_name, option_value
				 FROM {$wpdb->options}
				 WHERE option_id > %d
				   AND option_name NOT LIKE '\_transient\_%%'
				   AND option_name NOT LIKE '\_site\_transient\_%%'
				   AND option_name NOT LIKE 'uams_mra\_%%'
				 ORDER BY option_id ASC LIMIT %d",
                $cursor,
                $limit
            )
        );

        if (empty($rows)) {
            return self::advance($state);
        }

        $refs = [];

        foreach ($rows as $row) {
            $cursor = (int) $row->option_id;

            if ((string) $row->option_value === '') {
                continue;
            }

            $hits = self::scan_meta_value(
                $row->option_name,
                $row->option_value,
                ! empty($state['settings']['include_numeric_meta'])
                    && self::source_allows_numeric_match('option')
            );

            foreach ($hits as $attachment_id => $confidence) {
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
     * @return array
     */
    protected static function step_termmeta($state)
    {
        return self::generic_meta_step($state, $GLOBALS['wpdb']->termmeta, 'meta_id', 'term_id', 'termmeta', 'Term');
    }

    /**
     * Scan user meta.
     *
     * @param  array  $state  State.
     * @return array
     */
    protected static function step_usermeta($state)
    {
        return self::generic_meta_step($state, $GLOBALS['wpdb']->usermeta, 'umeta_id', 'user_id', 'usermeta', 'User');
    }

    /**
     * Scan comment meta.
     *
     * @param  array  $state  State.
     * @return array
     */
    protected static function step_commentmeta($state)
    {
        return self::generic_meta_step($state, $GLOBALS['wpdb']->commentmeta, 'meta_id', 'comment_id', 'commentmeta', 'Comment');
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
     * @return array
     */
    protected static function generic_meta_step($state, $table, $pk, $object_col, $source_type, $prefix)
    {
        global $wpdb;

        $limit = (int) $state['settings']['batch_meta'];
        $cursor = (int) $state['cursor'];

        $sql = "SELECT {$pk} AS pk, {$object_col} AS object_id, meta_key, meta_value
				FROM {$table} WHERE {$pk} > %d ORDER BY {$pk} ASC LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $cursor, $limit));

        if (empty($rows)) {
            return self::advance($state);
        }

        $skip_keys = self::skipped_meta_keys();
        $refs = [];

        foreach ($rows as $row) {
            $cursor = (int) $row->pk;

            if (isset($skip_keys[$row->meta_key])) {
                continue;
            }

            if ((string) $row->meta_value === '') {
                continue;
            }

            $hits = self::scan_meta_value(
                $row->meta_key,
                $row->meta_value,
                ! empty($state['settings']['include_numeric_meta'])
                    && self::source_allows_numeric_match($source_type)
            );

            foreach ($hits as $attachment_id => $confidence) {
                $refs[] = [
                    'attachment_id' => $attachment_id,
                    'source_type' => $source_type,
                    'source_id' => (int) $row->object_id,
                    'source_label' => $prefix.' #'.(int) $row->object_id.': '.$row->meta_key,
                    'confidence' => $confidence,
                ];
            }
        }

        UAMS_MRA_Store::add_refs($refs);

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
     * @return array
     */
    protected static function step_network($state)
    {
        global $wpdb;

        if (empty($state['site_queue'])) {
            return self::advance($state);
        }

        $target_prefix = $wpdb->prefix;
        $target_base = self::upload_path_prefixes();
        $site_id = (int) $state['site_queue'][0];
        $limit = (int) $state['settings']['batch_posts'];
        $cursor = (int) $state['cursor'];

        switch_to_blog($site_id);

        UAMS_MRA_Store::set_prefix_override($target_prefix);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_type, post_title, post_status, post_content
				 FROM {$wpdb->posts}
				 WHERE ID > %d AND post_type NOT IN ('attachment','revision')
				 ORDER BY ID ASC LIMIT %d",
                $cursor,
                $limit
            )
        );

        $refs = [];

        foreach ($rows as $row) {
            $cursor = (int) $row->ID;

            $paths = self::extract_paths($row->post_content, $target_base);
            if ($paths === []) {
                continue;
            }

            foreach (UAMS_MRA_Index::resolve_paths($paths) as $attachment_id) {
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
     * @return array
     */
    protected static function step_finalize($state)
    {
        global $wpdb;

        $limit = (int) $state['settings']['batch_finalize'];
        $cursor = (int) $state['cursor'];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_parent, post_mime_type, post_date, post_title
				 FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND ID > %d
				 ORDER BY ID ASC LIMIT %d",
                $cursor,
                $limit
            )
        );

        if (empty($rows)) {
            return self::advance($state);
        }

        $ids = wp_list_pluck($rows, 'ID');
        $ids = array_map(intval(...), $ids);

        // One meta query for the whole batch instead of two per attachment.
        update_meta_cache('post', $ids);

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $counts = [];
        $sql = 'SELECT attachment_id, COUNT(*) AS total, SUM(CASE WHEN confidence >= 2 THEN 1 ELSE 0 END) AS strong
				   FROM '.UAMS_MRA_Store::refs_table()." WHERE attachment_id IN ({$placeholders}) GROUP BY attachment_id";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ($wpdb->get_results($wpdb->prepare($sql, $ids)) as $count_row) {
            $counts[(int) $count_row->attachment_id] = [(int) $count_row->total, (int) $count_row->strong];
        }

        $parent_statuses = [];
        $parents = array_filter(array_map(intval(...), wp_list_pluck($rows, 'post_parent')));
        if ($parents !== []) {
            $parents = array_values(array_unique($parents));
            $placeholders = implode(',', array_fill(0, count($parents), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            foreach ($wpdb->get_results($wpdb->prepare("SELECT ID, post_status FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", $parents)) as $parent_row) {
                $parent_statuses[(int) $parent_row->ID] = $parent_row->post_status;
            }
        }

        $values = [];
        $args = [];
        $now = current_time('mysql');

        foreach ($rows as $row) {
            $cursor = (int) $row->ID;
            $parent = (int) $row->post_parent;
            $count = $counts[(int) $row->ID] ?? [0, 0];
            $file = (string) get_post_meta($row->ID, '_wp_attached_file', true);

            $values[] = '(%d,%d,%s,%d,%d,%d,%d,%s,%s,%s,%s,%s)';
            $args[] = (int) $row->ID;
            $args[] = $parent;
            $args[] = $parent ? ($parent_statuses[$parent] ?? 'missing') : '';
            $args[] = $parent && isset($parent_statuses[$parent]) ? 1 : 0;
            $args[] = $count[0];
            $args[] = $count[1];
            $args[] = self::attachment_bytes((int) $row->ID, $file);
            $args[] = (string) $row->post_mime_type;
            $args[] = (string) $row->post_date;
            $args[] = mb_substr((string) $row->post_title, 0, 250);
            $args[] = mb_substr($file, 0, 250);
            $args[] = $now;
        }

        $sql = 'REPLACE INTO '.UAMS_MRA_Store::results_table()
            .' (attachment_id, parent_id, parent_status, is_attached, ref_count, ref_count_strict, bytes, mime, post_date, title, file, scanned_at) VALUES '
            .implode(',', $values);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare($sql, $args));

        $state['cursor'] = $cursor;
        $state['processed'] += count($rows);

        if (count($rows) < $limit) {
            return self::advance($state);
        }

        return $state;
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
        return (string) apply_filters(
            'uams_mra_foreign_id_key_pattern',
            '#(^|_)(user|author|customer|post|page|parent|term|category|tag|comment|order|site|blog|form|entry)_id$#i'
        );
    }

    /**
     * Does this key name an ID belonging to something other than an attachment.
     *
     * @param  string  $key  Meta or option name.
     */
    protected static function is_foreign_id_key($key): bool
    {
        $pattern = self::foreign_id_key_pattern();

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
     */
    protected static function skipped_meta_keys(): array
    {
        return array_flip(
            apply_filters(
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
            )
        );
    }

    /**
     * Upload path prefixes used to spot media URLs.
     */
    public static function upload_path_prefixes(): array
    {
        $uploads = wp_get_upload_dir();
        $base = (string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH);
        $base = trim($base, '/');

        $prefixes = [$base];

        $settings = uams_mra_settings();
        foreach (preg_split('#[\r\n]+#', (string) $settings['extra_paths']) as $extra) {
            $extra = trim(trim($extra), '/');
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
     */
    public static function extract_paths($content, $prefixes = null): array
    {
        $content = (string) $content;

        if (! str_contains($content, '/')) {
            return [];
        }

        // JSON and slash-escaped content stores paths as \/ sequences.
        $content = str_replace(['\\/', '&#047;', '&#x2F;'], '/', $content);

        $prefixes ??= self::upload_path_prefixes();

        $found = [];

        foreach ($prefixes as $prefix) {
            if (stripos($content, (string) $prefix) === false) {
                continue;
            }

            $pattern = '#'.preg_quote($prefix, '#').'/([^\s"\'<>\\\\()\[\]{},;]+?\.[a-zA-Z0-9]{2,5})#i';

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
     */
    public static function extract_ids_from_content($content): array
    {
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
                $ids = array_merge($ids, preg_split('#[\s,]+#', trim($list)));
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
                        $ids = array_merge($ids, preg_split('#[\s,]+#', trim($list)));
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
     * @return array attachment_id => confidence
     */
    public static function scan_meta_value($key, $value, $allow_numeric = true): array
    {
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
                    $json_ids = array_merge($json_ids, preg_split('#[\s,]+#', trim($list)));
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
            $candidates = array_merge($candidates, preg_split('#[\s,]+#', $trimmed));
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

            $value = trim((string) $value);

            if ($value === '' || ! ctype_digit($value) || strlen($value) > 11) {
                continue;
            }

            // Numeric keys inside a list of IDs, or a key that reads like a media field.
            if (is_int($key) || preg_match('#(id|logo|image|img|icon|media|attachment|thumb|photo|gallery|file|banner|header|background)#i', (string) $key)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /**
     * Readable source label for a post row.
     *
     * @param  object  $row  Row with post_type, post_title, post_status.
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
        $uploads = wp_get_upload_dir();
        $basedir = trailingslashit($uploads['basedir']);

        if ($file === '') {
            $file = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
        }

        if ($file === '') {
            return 0;
        }

        $bytes = 0;
        $subdir = trailingslashit(dirname($file));
        $subdir = (trim($subdir, '/') === '.') ? '' : $subdir;

        $targets = [$file];

        $meta = wp_get_attachment_metadata($attachment_id);

        if (is_array($meta)) {
            if (! empty($meta['original_image'])) {
                $targets[] = $subdir.$meta['original_image'];
            }

            if (! empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if (! empty($size['file'])) {
                        $targets[] = $subdir.$size['file'];
                    }
                }
            }
        }

        foreach (array_unique($targets) as $target) {
            $path = $basedir.ltrim($target, '/');
            if (is_readable($path) && is_file($path)) {
                $bytes += (int) filesize($path);
            }
        }

        return $bytes;
    }
}
