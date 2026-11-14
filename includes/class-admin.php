<?php
/**
 * Admin screen and request handling.
 */
defined('ABSPATH') || exit;

/**
 * Wires up the Media Audit screen.
 */
class UAMS_MRA_Admin
{
    const SLUG = 'uams-media-audit';

    /**
     * Offsite media report slug.
     */
    const SLUG_OFFSITE = 'uams-media-audit-offsite';

    /**
     * Hook everything up.
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_init', [$this, 'handle_requests']);
        add_action('admin_post_uams_mra_export', [$this, 'export_csv']);
        add_action('admin_post_uams_mra_offsite_export', [$this, 'export_offsite_csv']);
        add_action('wp_ajax_uams_mra_scan_start', [$this, 'ajax_start']);
        add_action('wp_ajax_uams_mra_scan_step', [$this, 'ajax_step']);
        add_action('wp_ajax_uams_mra_triage', [$this, 'ajax_triage']);
        add_action('wp_ajax_uams_mra_archive_start', [$this, 'ajax_archive_start']);
        add_action('wp_ajax_uams_mra_archive_step', [$this, 'ajax_archive_step']);
        add_action('admin_post_uams_mra_archive_download', [$this, 'download_archive']);
        add_action('wp_ajax_uams_mra_repoint_plan', [$this, 'ajax_repoint_plan']);
        add_action('wp_ajax_uams_mra_repoint_apply', [$this, 'ajax_repoint_apply']);
        add_action('wp_ajax_uams_mra_repoint_step', [$this, 'ajax_repoint_step']);
        add_action('wp_ajax_uams_mra_repoint_sideload', [$this, 'ajax_repoint_sideload']);
    }

    /**
     * Register the submenu.
     */
    public function add_menu(): void
    {
        add_submenu_page(
            'upload.php',
            __('Media Audit', 'uams-mra'),
            __('Media Audit', 'uams-mra'),
            UAMS_MRA_CAP,
            self::SLUG,
            [$this, 'render']
        );

        add_submenu_page(
            'upload.php',
            __('Offsite Media', 'uams-mra'),
            __('Offsite Media', 'uams-mra'),
            UAMS_MRA_CAP,
            self::SLUG_OFFSITE,
            [$this, 'render_offsite']
        );
    }

    /**
     * Is the current screen ours.
     *
     * @param  string  $hook  Hook suffix.
     */
    protected function is_screen($hook): bool
    {
        return in_array(
            $hook,
            ['media_page_'.self::SLUG, 'media_page_'.self::SLUG_OFFSITE],
            true
        );
    }

    /**
     * Load assets.
     *
     * @param  string  $hook  Hook suffix.
     */
    public function enqueue($hook): void
    {
        if (! $this->is_screen($hook)) {
            return;
        }

        wp_enqueue_style('uams-mra-admin', UAMS_MRA_URL.'assets/css/admin.css', [], UAMS_MRA_VERSION);
        wp_enqueue_script('uams-mra-admin', UAMS_MRA_URL.'assets/js/admin.js', [], UAMS_MRA_VERSION, true);

        wp_localize_script(
            'uams-mra-admin',
            'UAMSMRA',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('uams_mra_scan'),
                'triageNonce' => wp_create_nonce('uams_mra_triage'),
                'archiveNonce' => wp_create_nonce('uams_mra_archive'),
                'repointNonce' => wp_create_nonce('uams_mra_repoint'),
                'i18n' => [
                    'confirmDelete' => __('This permanently deletes the selected files and every generated size. It cannot be undone. Continue?', 'uams-mra'),
                    'confirmScan' => __('Start a new scan? This replaces the previous results.', 'uams-mra'),
                    'failed' => __('The scan stopped with an error. Reload the page and try again.', 'uams-mra'),
                    'noSelection' => __('Select at least one file first.', 'uams-mra'),
                    'saved' => __('Saved', 'uams-mra'),
                    'saveFailed' => __('Not saved', 'uams-mra'),
                    'confirmMarked' => __('Permanently delete every file marked Delete? This cannot be undone.', 'uams-mra'),
                    'archiving' => __('Building the archive…', 'uams-mra'),
                    'archiveFailed' => __('The archive stopped with an error. Reload and try again.', 'uams-mra'),
                    'archiveDone' => __('Archive ready.', 'uams-mra'),
                    'confirmRepoint' => __('Rewrite these stored URLs to point at this library? Pages are updated through the editor, so each change is kept as a revision. Take a database backup first.', 'uams-mra'),
                    'repointFailed' => __('The job stopped with an error. Reload and try again.', 'uams-mra'),
                    'repointResuming' => __('A file took the request down. Skipping it and carrying on (resume %d)…', 'uams-mra'),
                    'repointFatal' => __('The server did not answer. This is usually PHP running out of time or memory on a download -- lower "Files downloaded per batch" in Scan settings, then click again to carry on. The PHP error log will have the detail.', 'uams-mra'),
                    'confirmSideload' => __('Download these files from the other host into this media library? Nothing is rewritten yet -- you repoint them afterwards.', 'uams-mra'),
                ],
            ]
        );
    }

    /**
     * Handle settings saves and bulk deletes.
     */
    public function handle_requests(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! isset($_GET['page']) || $_GET['page'] !== self::SLUG) {
            return;
        }

        if (! current_user_can(UAMS_MRA_CAP)) {
            return;
        }

        $this->maybe_save_settings();
        $this->maybe_delete();
        $this->maybe_delete_marked();
        $this->maybe_build_selection();
        $this->maybe_delete_selection();
        $this->maybe_discard_archive();
    }

    /**
     * Save the settings form.
     */
    protected function maybe_save_settings()
    {
        if (! isset($_POST['uams_mra_save_settings'])) {
            return;
        }

        check_admin_referer('uams_mra_settings');

        $defaults = uams_mra_default_settings();
        $settings = [];

        foreach (['include_revisions', 'include_numeric_meta', 'include_usermeta', 'include_termmeta', 'include_comments', 'include_network', 'include_render', 'include_offsite'] as $key) {
            $settings[$key] = isset($_POST[$key]) ? 1 : 0;
        }

        $settings['extra_paths'] = isset($_POST['extra_paths'])
            ? sanitize_textarea_field(wp_unslash($_POST['extra_paths']))
            : '';

        foreach (['batch_posts', 'batch_meta', 'batch_index', 'batch_finalize'] as $key) {
            $value = isset($_POST[$key]) ? absint($_POST[$key]) : $defaults[$key];
            $settings[$key] = max(10, min(10000, $value));
        }

        // Render batches are HTTP requests rather than rows, so they get their
        // own much smaller range instead of the shared 10-10000 clamp.
        $value = isset($_POST['batch_render']) ? absint($_POST['batch_render']) : $defaults['batch_render'];
        $settings['batch_render'] = max(1, min(25, $value));

        $value = isset($_POST['render_max_pages']) ? absint($_POST['render_max_pages']) : $defaults['render_max_pages'];
        $settings['render_max_pages'] = max(1, min(5000, $value));

        $value = isset($_POST['batch_repoint']) ? absint($_POST['batch_repoint']) : $defaults['batch_repoint'];
        $settings['batch_repoint'] = max(1, min(25, $value));

        $value = isset($_POST['batch_sideload']) ? absint($_POST['batch_sideload']) : $defaults['batch_sideload'];
        $settings['batch_sideload'] = max(1, min(10, $value));

        $value = isset($_POST['sideload_max_mb']) ? absint($_POST['sideload_max_mb']) : $defaults['sideload_max_mb'];
        $settings['sideload_max_mb'] = max(1, min(2048, $value));

        update_option('uams_mra_settings', $settings);

        add_settings_error('uams-mra', 'uams_mra_saved', __('Settings saved.', 'uams-mra'), 'success');
    }

    /**
     * Run a bulk delete.
     */
    protected function maybe_delete()
    {
        $action = isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '';
        if ($action !== 'uams_mra_delete') {
            $action = isset($_POST['action2']) ? sanitize_key(wp_unslash($_POST['action2'])) : '';
        }

        if ($action !== 'uams_mra_delete') {
            return;
        }

        check_admin_referer('bulk-media_items');

        $ids = isset($_POST['ids']) ? array_map(absint(...), (array) wp_unslash($_POST['ids'])) : [];
        $ids = array_filter($ids);

        if ($ids === []) {
            add_settings_error('uams-mra', 'uams_mra_none', __('No files were selected, so nothing was deleted.', 'uams-mra'), 'warning');

            return;
        }

        $this->report_delete($this->delete_ids($ids, ! empty($_POST['uams_mra_force'])));
    }

    /**
     * Delete every file currently marked Delete.
     *
     * Deliberately a button of its own rather than an entry in the bulk-actions
     * menu: it acts on the marks, not on the ticked checkboxes, and a menu entry
     * that quietly ignores the selection would be a trap.
     */
    protected function maybe_delete_marked()
    {
        if (! isset($_POST['uams_mra_delete_marked'])) {
            return;
        }

        check_admin_referer('bulk-media_items');

        $ids = UAMS_MRA_Triage::ids_marked('delete');

        if ($ids === []) {
            add_settings_error('uams-mra', 'uams_mra_none', __('Nothing is marked Delete, so nothing was deleted.', 'uams-mra'), 'warning');

            return;
        }

        $this->report_delete($this->delete_ids($ids, ! empty($_POST['uams_mra_force'])));
    }

    /**
     * Permanently delete attachments, honouring the reference guard.
     *
     * @param  array  $ids  Attachment IDs.
     * @param  bool  $force  Delete even when references were recorded.
     * @return array {deleted, skipped, bytes}
     */
    protected function delete_ids(array $ids, $force): array
    {
        global $wpdb;

        $table = UAMS_MRA_Store::results_table();
        $deleted = 0;
        $skipped = 0;
        $bytes = 0;

        foreach ($ids as $id) {
            $id = (int) $id;

            if (get_post_type($id) !== 'attachment') {
                continue;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $row = $wpdb->get_row($wpdb->prepare("SELECT ref_count, bytes FROM {$table} WHERE attachment_id = %d", $id), ARRAY_A);

            if ($row && (int) $row['ref_count'] > 0 && ! $force) {
                $skipped++;

                continue;
            }

            if (wp_delete_attachment($id, true)) {
                $deleted++;
                $bytes += $row ? (int) $row['bytes'] : 0;
                $wpdb->delete($table, ['attachment_id' => $id], ['%d']);
            }
        }

        return [
            'deleted' => $deleted,
            'skipped' => $skipped,
            'bytes' => $bytes,
        ];
    }

    /**
     * Turn a delete run into an admin notice.
     *
     * @param  array  $result  From delete_ids().
     */
    protected function report_delete(array $result)
    {
        $message = sprintf(
            /* translators: 1: number of files, 2: disk space freed. */
            __('Deleted %1$s files and freed %2$s.', 'uams-mra'),
            number_format_i18n($result['deleted']),
            size_format($result['bytes'], 1)
        );

        if ($result['skipped']) {
            $message .= ' '.sprintf(
                /* translators: %s: number of files. */
                __('Kept %s files that still have references. Tick the override box to delete those.', 'uams-mra'),
                number_format_i18n($result['skipped'])
            );
        }

        add_settings_error('uams-mra', 'uams_mra_deleted', $message, $result['skipped'] ? 'warning' : 'success');
    }

    /* ---------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    /* ---------------------------------------------------------------------
     * Selection and archive
     * ------------------------------------------------------------------ */

    /**
     * URL of one of this plugin's screens.
     *
     * @param  array  $args  Query args.
     * @return string
     */
    public static function url(array $args = [])
    {
        return add_query_arg($args, menu_page_url(self::SLUG, false));
    }

    /**
     * Park a message for the next page load.
     *
     * add_settings_error() does not survive the redirect that follows a delete,
     * and rendering the result inline would leave a resubmit on refresh.
     *
     * @param  string  $message  Message.
     * @param  string  $type  success, warning, or error.
     */
    protected function notice($message, $type = 'success')
    {
        update_user_meta(
            get_current_user_id(),
            '_uams_mra_notice',
            [
                'message' => $message,
                'type' => $type,
            ]
        );
    }

    /**
     * Show and forget any parked message.
     */
    protected function flash_notice()
    {
        $notice = get_user_meta(get_current_user_id(), '_uams_mra_notice', true);

        if (! is_array($notice) || empty($notice['message'])) {
            return;
        }

        delete_user_meta(get_current_user_id(), '_uams_mra_notice');

        add_settings_error('uams-mra', 'uams_mra_flash', $notice['message'], $notice['type']);
    }

    /**
     * Build a working set from the filter on screen or from pasted text.
     */
    protected function maybe_build_selection()
    {
        $from_filter = isset($_POST['uams_mra_select_filter']);
        $from_text = isset($_POST['uams_mra_select_text']);

        if (! $from_filter && ! $from_text) {
            return;
        }

        check_admin_referer('uams_mra_select');

        if ($from_filter) {
            $ids = UAMS_MRA_Selection::ids_matching_filter();

            if ($ids === []) {
                $this->notice(__('That filter matches no files.', 'uams-mra'), 'warning');
                wp_safe_redirect(wp_get_referer() ?: self::url());
                exit;
            }

            UAMS_MRA_Selection::store($ids);
            wp_safe_redirect(self::url(['action' => 'review']));
            exit;
        }

        $text = isset($_POST['pasted']) ? sanitize_textarea_field(wp_unslash($_POST['pasted'])) : '';
        $resolved = UAMS_MRA_Selection::resolve_text($text);

        if (empty($resolved['ids'])) {
            $this->notice(__('None of that matched a file in the last scan. Paste attachment IDs, filenames, or URLs.', 'uams-mra'), 'warning');
            wp_safe_redirect(wp_get_referer() ?: self::url());
            exit;
        }

        UAMS_MRA_Selection::store($resolved['ids']);

        if (! empty($resolved['unmatched'])) {
            $this->notice(
                sprintf(
                    /* translators: 1: number matched, 2: number not matched, 3: the unmatched entries. */
                    __('Matched %1$s files. %2$s entries matched nothing and were left out: %3$s', 'uams-mra'),
                    number_format_i18n(count($resolved['ids'])),
                    number_format_i18n(count($resolved['unmatched'])),
                    implode(', ', array_slice($resolved['unmatched'], 0, 20))
                ),
                'warning'
            );
        }

        wp_safe_redirect(self::url(['action' => 'review']));
        exit;
    }

    /**
     * Delete the working set.
     */
    protected function maybe_delete_selection()
    {
        if (! isset($_POST['uams_mra_delete_selection'])) {
            return;
        }

        check_admin_referer('uams_mra_review');

        $ids = UAMS_MRA_Selection::get();

        if ($ids === []) {
            $this->notice(__('Nothing is selected, so nothing was deleted.', 'uams-mra'), 'warning');
            wp_safe_redirect(self::url());
            exit;
        }

        $result = $this->delete_ids($ids, ! empty($_POST['uams_mra_force']));

        $message = sprintf(
            /* translators: 1: number of files, 2: disk space freed. */
            __('Deleted %1$s files and freed %2$s.', 'uams-mra'),
            number_format_i18n($result['deleted']),
            size_format($result['bytes'], 1)
        );

        if ($result['skipped']) {
            $message .= ' '.sprintf(
                /* translators: %s: number of files. */
                __('Kept %s that still have references. Tick the override box to delete those too.', 'uams-mra'),
                number_format_i18n($result['skipped'])
            );
        }

        $this->notice($message, $result['skipped'] ? 'warning' : 'success');

        UAMS_MRA_Selection::clear();

        wp_safe_redirect(self::url());
        exit;
    }

    /**
     * Throw away the built archive.
     */
    protected function maybe_discard_archive()
    {
        if (! isset($_POST['uams_mra_discard_archive'])) {
            return;
        }

        check_admin_referer('uams_mra_review');

        UAMS_MRA_Archive::discard();

        $this->notice(__('Archive discarded.', 'uams-mra'));

        wp_safe_redirect(self::url(['action' => 'review']));
        exit;
    }

    /**
     * Begin building the archive.
     */
    public function ajax_archive_start(): void
    {
        check_ajax_referer('uams_mra_archive', 'nonce');

        if (! current_user_can(UAMS_MRA_CAP)) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'uams-mra')], 403);
        }

        $job = UAMS_MRA_Archive::start(UAMS_MRA_Selection::get());

        if (is_wp_error($job)) {
            wp_send_json_error(['message' => $job->get_error_message()], 400);
        }

        wp_send_json_success($this->archive_payload($job));
    }

    /**
     * Add the next batch to the archive.
     */
    public function ajax_archive_step(): void
    {
        check_ajax_referer('uams_mra_archive', 'nonce');

        if (! current_user_can(UAMS_MRA_CAP)) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'uams-mra')], 403);
        }

        $job = UAMS_MRA_Archive::step();

        if (is_wp_error($job)) {
            wp_send_json_error(['message' => $job->get_error_message()], 400);
        }

        wp_send_json_success($this->archive_payload($job));
    }

    /**
     * Shape a job for the browser.
     *
     * @param  array  $job  Job.
     */
    protected function archive_payload(array $job): array
    {
        $total = max(1, count($job['ids']));
        $parts = [];

        foreach ((array) $job['parts'] as $part) {
            if (empty($part['files'])) {
                continue;
            }

            $parts[] = [
                'part' => (int) $part['part'],
                'name' => $part['name'],
                'size' => size_format((int) $part['bytes'], 1),
                'files' => (int) $part['files'],
                'url' => wp_nonce_url(
                    admin_url('admin-post.php?action=uams_mra_archive_download&part='.(int) $part['part']),
                    'uams_mra_archive_download'
                ),
            ];
        }

        return [
            'done' => ! empty($job['done']),
            'percent' => min(100, (int) round(($job['cursor'] / $total) * 100)),
            'files' => (int) $job['files'],
            'bytes' => size_format((int) $job['bytes'], 1),
            'skipped' => count((array) $job['skipped']),
            'parts' => $parts,
            'total' => count($job['ids']),
            'progress' => sprintf(
                /* translators: 1: files archived, 2: total files, 3: disk space. */
                __('%1$s of %2$s files · %3$s', 'uams-mra'),
                number_format_i18n($job['files']),
                number_format_i18n(count($job['ids'])),
                size_format((int) $job['bytes'], 1)
            ),
        ];
    }

    /**
     * Stream one archive part to the browser.
     */
    public function download_archive(): void
    {
        if (! current_user_can(UAMS_MRA_CAP)) {
            wp_die(esc_html__('You do not have permission to download this.', 'uams-mra'));
        }

        check_admin_referer('uams_mra_archive_download');

        $job = UAMS_MRA_Archive::get_job();
        $part = isset($_GET['part']) ? absint(wp_unslash($_GET['part'])) : 0;

        if (! $job || ! $part || empty($job['parts'][$part])) {
            wp_die(esc_html__('That archive is no longer available. Build it again.', 'uams-mra'));
        }

        $path = $job['dir'].'/'.$job['parts'][$part]['name'];

        // The job folder is the only place this may ever read from.
        if (! str_starts_with($path, UAMS_MRA_Archive::base_dir()) || ! file_exists($path)) {
            wp_die(esc_html__('That archive file is missing. Build it again.', 'uams-mra'));
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename='.basename($path));
        header('Content-Length: '.filesize($path));

        // Chunked so a large part does not have to fit in memory.
        $handle = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions

        if ($handle) {
            while (! feof($handle)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo fread($handle, 1024 * 1024); // phpcs:ignore WordPress.WP.AlternativeFunctions
                flush();
            }
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        exit;
    }

    /**
     * The review screen: one list, then archive and delete.
     */
    protected function render_review()
    {
        $ids = UAMS_MRA_Selection::get();
        $rows = UAMS_MRA_Selection::rows($ids);
        $totals = UAMS_MRA_Selection::totals($rows);
        $job = UAMS_MRA_Archive::get_job();
        $tiers = UAMS_MRA_Triage::tiers();

        if ($rows !== []) {
            update_meta_cache('post', wp_list_pluck($rows, 'attachment_id'));
        }
        ?>
		<div class="wrap uams-mra-wrap">
			<h1><?php esc_html_e('Review selection', 'uams-mra'); ?></h1>

			<?php settings_errors('uams-mra'); ?>

			<p class="uams-mra-intro">
				<a href="<?php echo esc_url(self::url()); ?>">&larr; <?php esc_html_e('Back to results', 'uams-mra'); ?></a>
			</p>

			<?php if ($rows === []) { ?>
				<p><?php esc_html_e('Nothing is selected. Go back and choose a filter or paste a list.', 'uams-mra'); ?></p>
				</div>
				<?php
                return;
			}
        ?>

			<div class="uams-mra-stats">
				<div class="uams-mra-stat">
					<span class="uams-mra-stat-label"><?php esc_html_e('Files selected', 'uams-mra'); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html(number_format_i18n($totals['count'])); ?></strong>
				</div>
				<div class="uams-mra-stat">
					<span class="uams-mra-stat-label"><?php esc_html_e('Disk space', 'uams-mra'); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html(size_format($totals['bytes'], 1)); ?></strong>
				</div>
				<div class="uams-mra-stat">
					<span class="uams-mra-stat-label"><?php esc_html_e('Still referenced', 'uams-mra'); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html(number_format_i18n($totals['referenced'])); ?></strong>
					<span class="uams-mra-muted"><?php esc_html_e('skipped unless overridden', 'uams-mra'); ?></span>
				</div>
				<div class="uams-mra-stat">
					<span class="uams-mra-stat-label"><?php esc_html_e('Attached to a post', 'uams-mra'); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html(number_format_i18n($totals['attached'])); ?></strong>
				</div>
			</div>

			<?php if ($totals['missing']) { ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
                    /* translators: %s: number of files. */
                    echo esc_html(sprintf(__('%s of these have no file on disk. They will be left out of the archive but can still be deleted, which clears the orphaned database row.', 'uams-mra'), number_format_i18n($totals['missing'])));
			    ?>
					</p>
				</div>
			<?php } ?>

			<?php if (! UAMS_MRA_Archive::available()) { ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e('This server has no ZipArchive support, so the plugin cannot build an archive. Take your own backup before deleting anything here.', 'uams-mra'); ?></p>
				</div>
			<?php } ?>

			<div class="uams-mra-panel">
				<div class="uams-mra-panel-head">
					<div>
						<h2><?php esc_html_e('Archive', 'uams-mra'); ?></h2>
						<p class="uams-mra-muted">
							<?php
			        printf(
			            /* translators: %s: size of one part. */
			            esc_html__('Original files plus a manifest listing every ID, title, and note. Split into parts of about %s so a failed download costs one part, not the whole set. Generated image sizes are left out; WordPress rebuilds those.', 'uams-mra'),
			            esc_html(size_format(UAMS_MRA_Archive::part_max_bytes()))
			        );
        ?>
						</p>
					</div>
					<?php if (UAMS_MRA_Archive::available()) { ?>
						<button type="button" class="button button-primary" id="uams-mra-archive">
							<?php echo $job ? esc_html__('Rebuild archive', 'uams-mra') : esc_html__('Build archive', 'uams-mra'); ?>
						</button>
					<?php } ?>
				</div>

				<div id="uams-mra-archive-progress" class="uams-mra-progress" hidden>
					<div class="uams-mra-bar"><span></span></div>
					<p id="uams-mra-archive-text" role="status" aria-live="polite"></p>
				</div>

				<div id="uams-mra-archive-parts" class="uams-mra-parts">
					<?php
                    if ($job && ! empty($job['parts'])) {
                        foreach ($job['parts'] as $part) {
                            if (empty($part['files'])) {
                                continue;
                            }
                            $href = wp_nonce_url(
                                admin_url('admin-post.php?action=uams_mra_archive_download&part='.(int) $part['part']),
                                'uams_mra_archive_download'
                            );
                            ?>
							<a class="button" href="<?php echo esc_url($href); ?>">
								<?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: part number, 2: file count, 3: size. */
                                        __('Download part %1$d (%2$s files, %3$s)', 'uams-mra'),
                                        (int) $part['part'],
                                        number_format_i18n((int) $part['files']),
                                        size_format((int) $part['bytes'], 1)
                                    )
                                );
                            ?>
							</a>
							<?php
                        }
                    }
        ?>
				</div>

				<?php if ($job) { ?>
					<form method="post" class="uams-mra-discard">
						<?php wp_nonce_field('uams_mra_review'); ?>
						<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>" />
						<input type="hidden" name="action" value="review" />
						<button type="submit" name="uams_mra_discard_archive" value="1" class="button-link">
							<?php esc_html_e('Discard this archive', 'uams-mra'); ?>
						</button>
						<span class="uams-mra-muted">
							<?php esc_html_e('Archives are deleted automatically after a day.', 'uams-mra'); ?>
						</span>
					</form>
				<?php } ?>
			</div>

			<table class="wp-list-table widefat fixed striped uams-mra-review-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e('File', 'uams-mra'); ?></th>
						<th scope="col"><?php esc_html_e('Tier', 'uams-mra'); ?></th>
						<th scope="col"><?php esc_html_e('Decision', 'uams-mra'); ?></th>
						<th scope="col"><?php esc_html_e('References', 'uams-mra'); ?></th>
						<th scope="col"><?php esc_html_e('Disk space', 'uams-mra'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
        foreach ($rows as $row) {
            $id = (int) $row['attachment_id'];
            $tier = UAMS_MRA_Triage::tier($row);
            $mark = UAMS_MRA_Triage::get($id);
            ?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url((string) get_edit_post_link($id)); ?>"><?php echo esc_html($row['title'] !== '' ? $row['title'] : __('(no title)', 'uams-mra')); ?></a></strong>
								<div class="uams-mra-file"><?php echo esc_html($row['file']); ?></div>
								<div class="uams-mra-meta">ID <?php echo $id; ?> &middot; <?php echo esc_html($row['mime']); ?></div>
							</td>
							<td>
								<span class="uams-mra-tier uams-mra-tier-<?php echo esc_attr($tier); ?>"><?php echo esc_html($tiers[$tier]['label']); ?></span>
							</td>
							<td>
								<?php if ($mark['d']) { ?>
									<?php $labels = UAMS_MRA_Triage::dispositions(); ?>
									<strong><?php echo esc_html($labels[$mark['d']]); ?></strong>
									<?php if ($mark['n']) { ?>
										<div class="uams-mra-meta"><?php echo esc_html($mark['n']); ?></div>
									<?php } ?>
								<?php } else { ?>
									<span class="uams-mra-muted"><?php esc_html_e('Undecided', 'uams-mra'); ?></span>
								<?php } ?>
							</td>
							<td>
								<?php if ((int) $row['ref_count'] > 0) { ?>
									<span class="uams-mra-badge uams-mra-badge-ok"><?php echo esc_html(number_format_i18n($row['ref_count'])); ?></span>
								<?php } else { ?>
									<span class="uams-mra-muted"><?php esc_html_e('None', 'uams-mra'); ?></span>
								<?php } ?>
							</td>
							<td><?php echo esc_html($row['bytes'] ? size_format($row['bytes'], 1) : __('File missing', 'uams-mra')); ?></td>
						</tr>
					<?php } ?>
				</tbody>
			</table>

			<form method="post" class="uams-mra-danger">
				<?php wp_nonce_field('uams_mra_review'); ?>
				<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>" />
				<input type="hidden" name="action" value="review" />

				<p class="uams-mra-force">
					<label>
						<input type="checkbox" name="uams_mra_force" value="1" />
						<?php esc_html_e('Also delete the files that still have references.', 'uams-mra'); ?>
					</label>
				</p>

				<button type="submit" name="uams_mra_delete_selection" value="1" class="button button-secondary" id="uams-mra-delete-selection">
					<?php
                    echo esc_html(
                        sprintf(
                            /* translators: 1: number of files, 2: disk space. */
                            __('Delete these %1$s files permanently (%2$s)', 'uams-mra'),
                            number_format_i18n($totals['count']),
                            size_format($totals['bytes'], 1)
                        )
                    );
        ?>
				</button>
			</form>
		</div>
		<?php
    }

    /**
     * Save one file's disposition and note.
     */
    public function ajax_triage(): void
    {
        check_ajax_referer('uams_mra_triage', 'nonce');

        if (! current_user_can(UAMS_MRA_CAP)) {
            wp_send_json_error(['message' => __('You do not have permission to change this.', 'uams-mra')], 403);
        }

        $id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;

        if (! $id || get_post_type($id) !== 'attachment') {
            wp_send_json_error(['message' => __('That file no longer exists.', 'uams-mra')], 404);
        }

        $disposition = isset($_POST['d']) ? sanitize_key(wp_unslash($_POST['d'])) : '';
        $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';

        $mark = UAMS_MRA_Triage::set($id, $disposition, $note);
        $counts = UAMS_MRA_Triage::counts();

        wp_send_json_success(
            [
                'mark' => $mark,
                'counts' => $counts,
                'deleteLabel' => sprintf(
                    /* translators: 1: number of files, 2: disk space. */
                    __('Delete the %1$s files marked Delete (%2$s)', 'uams-mra'),
                    number_format_i18n($counts['disp']['delete']),
                    size_format($counts['delete_bytes'], 1)
                ),
            ]
        );
    }

    /**
     * Begin a scan.
     */
    public function ajax_start(): void
    {
        check_ajax_referer('uams_mra_scan', 'nonce');

        if (! current_user_can(UAMS_MRA_CAP)) {
            wp_send_json_error(['message' => __('You do not have permission to run a scan.', 'uams-mra')], 403);
        }

        $state = UAMS_MRA_Scanner::start();

        if (is_wp_error($state)) {
            wp_send_json_error(['message' => $state->get_error_message()], 409);
        }

        wp_send_json_success($this->progress($state));
    }

    /**
     * Advance the scan by one batch.
     */
    public function ajax_step(): void
    {
        check_ajax_referer('uams_mra_scan', 'nonce');

        if (! current_user_can(UAMS_MRA_CAP)) {
            wp_send_json_error(['message' => __('You do not have permission to run a scan.', 'uams-mra')], 403);
        }

        wp_send_json_success($this->progress(UAMS_MRA_Scanner::step()));
    }

    /**
     * Shape state for the progress UI.
     *
     * @param  array  $state  Scan state.
     */
    protected function progress($state): array
    {
        $done = ('done' === ($state['step'] ?? 'done'));

        $detail = '';

        if ('render' === ($state['step'] ?? '')) {
            $total = (int) ($state['render_total'] ?? 0);
            $remaining = count((array) ($state['render_queue'] ?? []));
            $detail = sprintf(
                /* translators: 1: pages handled so far, 2: total pages queued. */
                __('%1$s of %2$s pages read', 'uams-mra'),
                number_format_i18n(max(0, $total - $remaining)),
                number_format_i18n($total)
            );
        }

        return [
            'step' => $state['step'] ?? 'done',
            'message' => $state['message'] ?? '',
            'processed' => (int) ($state['processed'] ?? 0),
            'detail' => $detail,
            'done' => $done,
            'summary' => $done ? UAMS_MRA_Store::summary() : null,
        ];
    }

    /* ---------------------------------------------------------------------
     * Export
     * ------------------------------------------------------------------ */

    /**
     * Stream results as CSV.
     */
    public function export_csv(): void
    {
        if (! current_user_can(UAMS_MRA_CAP)) {
            wp_die(esc_html__('You do not have permission to export this report.', 'uams-mra'));
        }

        check_admin_referer('uams_mra_export');

        global $wpdb;

        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : UAMS_MRA_List_Table::default_view();
        $where = [
            'orphaned' => 'ref_count = 0 AND is_attached = 0',
            'unreferenced' => 'ref_count = 0',
            'unattached' => 'is_attached = 0',
            'weak' => 'ref_count > 0 AND ref_count_strict = 0',
            'referenced' => 'ref_count > 0',
            'all' => '1=1',
        ];
        $where = $where[$view] ?? $where['orphaned'];

        $filename = 'uams-media-audit-'.sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST)).'-'.$view.'-'.gmdate('Ymd').'.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename='.$filename);

        $out = fopen('php://output', 'w');

        fputcsv(
            $out,
            [
                'attachment_id',
                'title',
                'file',
                'url',
                'mime',
                'uploaded',
                'bytes',
                'human_size',
                'attached_to',
                'parent_status',
                'reference_count',
                'strong_reference_count',
                'references',
                'page_check',
                'page_check_note',
                'page_checked_url',
                'page_checked_at',
                'tier',
                'decision',
                'note',
            ],
            ',',
            '"',
            ''
        );

        $table = UAMS_MRA_Store::results_table();
        $offset = 0;

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY bytes DESC LIMIT 500 OFFSET %d", $offset),
                ARRAY_A
            );

            update_meta_cache('post', wp_list_pluck($rows, 'attachment_id'));

            foreach ($rows as $row) {
                $mark = UAMS_MRA_Triage::get((int) $row['attachment_id']);
                $refs = UAMS_MRA_Store::get_refs((int) $row['attachment_id'], 10);
                $labels = [];
                foreach ($refs as $ref) {
                    $labels[] = $ref->source_type.' #'.$ref->source_id.' '.$ref->source_label;
                }

                fputcsv(
                    $out,
                    [
                        $row['attachment_id'],
                        $row['title'],
                        $row['file'],
                        wp_get_attachment_url((int) $row['attachment_id']),
                        $row['mime'],
                        $row['post_date'],
                        $row['bytes'],
                        size_format((int) $row['bytes'], 1),
                        $row['parent_id'] ? $row['parent_id'].' '.get_the_title((int) $row['parent_id']) : '',
                        $row['parent_status'],
                        $row['ref_count'],
                        $row['ref_count_strict'],
                        implode(' | ', $labels),
                        $row['render_state'] ?? '',
                        $row['render_note'] ?? '',
                        $row['render_url'] ?? '',
                        (isset($row['render_checked_at']) && $row['render_checked_at'] !== '0000-00-00 00:00:00') ? $row['render_checked_at'] : '',
                        UAMS_MRA_Triage::tier($row),
                        $mark['d'],
                        $mark['n'],
                    ],
                    ',',
                    '"',
                    ''
                );
            }

            $offset += 500;
        } while (count($rows) === 500);

        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    /* ---------------------------------------------------------------------
     * Render
     * ------------------------------------------------------------------ */

    /**
     * Output the admin page.
     */
    public function render(): void
    {
        $this->flash_notice();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if ($action === 'review') {
            $this->render_review();

            return;
        }

        $state = UAMS_MRA_Scanner::get_state();
        $summary = UAMS_MRA_Store::summary();
        $settings = uams_mra_settings();
        $scanned = ! empty($state['finished']);
        $view = UAMS_MRA_List_Table::current_view();

        $triage = $scanned ? UAMS_MRA_Triage::counts(UAMS_MRA_List_Table::where_for_view($view)) : [];

        $table = new UAMS_MRA_List_Table;
        $table->prepare_items();
        ?>
		<div class="wrap uams-mra-wrap">
			<h1><?php esc_html_e('Media Audit', 'uams-mra'); ?></h1>

			<?php settings_errors('uams-mra'); ?>

			<p class="uams-mra-intro">
				<?php esc_html_e('Scans every post, custom field, theme setting, widget, and menu on this site, then reports which media files nothing points to. Nothing is deleted until you choose to delete it.', 'uams-mra'); ?>
			</p>

			<div class="uams-mra-panel">
				<div class="uams-mra-panel-head">
					<div>
						<h2><?php esc_html_e('Scan', 'uams-mra'); ?></h2>
						<p class="uams-mra-muted">
							<?php
                            if ($scanned) {
                                printf(
                                    /* translators: %s: human readable time difference. */
                                    esc_html__('Last scanned %s ago.', 'uams-mra'),
                                    esc_html(human_time_diff((int) $state['finished']))
                                );
                            } else {
                                esc_html_e('No scan has finished yet.', 'uams-mra');
                            }
        ?>
						</p>
					</div>
					<button type="button" class="button button-primary" id="uams-mra-scan">
						<?php echo $scanned ? esc_html__('Scan again', 'uams-mra') : esc_html__('Start scan', 'uams-mra'); ?>
					</button>
				</div>

				<div id="uams-mra-progress" class="uams-mra-progress" hidden>
					<div class="uams-mra-bar"><span></span></div>
					<p id="uams-mra-progress-text" role="status" aria-live="polite"></p>
				</div>
			</div>

			<?php if ($scanned && ! empty($summary['total'])) { ?>
				<div class="uams-mra-stats">
					<?php
                    $cards = [
                        [__('Media files', 'uams-mra'), number_format_i18n($summary['total']), size_format($summary['total_bytes'], 1)],
                        [__('Not attached', 'uams-mra'), number_format_i18n($summary['unattached']), ''],
                        [__('Not referenced', 'uams-mra'), number_format_i18n($summary['unreferenced']), size_format($summary['unreferenced_bytes'], 1)],
                        [__('Safe to review first', 'uams-mra'), number_format_i18n($summary['orphaned']), size_format($summary['orphaned_bytes'], 1)],
                    ];
			    foreach ($cards as $card) {
			        ?>
						<div class="uams-mra-stat">
							<span class="uams-mra-stat-label"><?php echo esc_html($card[0]); ?></span>
							<strong class="uams-mra-stat-value"><?php echo esc_html($card[1]); ?></strong>
							<?php if ($card[2]) { ?>
								<span class="uams-mra-muted"><?php echo esc_html($card[2]); ?></span>
							<?php } ?>
						</div>
					<?php } ?>
				</div>
			<?php } ?>

			<?php if ($scanned && ! empty($summary['total']) && $triage !== []) { ?>
				<h2 class="uams-mra-settings-heading"><?php esc_html_e('Triage', 'uams-mra'); ?></h2>
				<p class="uams-mra-muted uams-mra-tier-intro">
					<?php esc_html_e('Every file gets a tier from the scan and a decision from you. Decisions are stored on the file itself, so they survive the next scan and everyone with access sees the same marks.', 'uams-mra'); ?>
					<br />
					<?php esc_html_e('These counts cover the filter selected below, not the whole library.', 'uams-mra'); ?>
				</p>

				<div class="uams-mra-tiers">
					<?php
			        $current_tier = UAMS_MRA_List_Table::current_tier();
			    foreach (UAMS_MRA_Triage::tiers() as $key => $tier) {
			        $url = add_query_arg(
			            [
			                'view' => $view,
			                'tier' => ($current_tier === $key) ? false : $key,
			            ],
			            menu_page_url(self::SLUG, false)
			        );
			        ?>
						<a class="uams-mra-tier-card uams-mra-tier-<?php echo esc_attr($key); ?><?php echo $current_tier === $key ? ' is-current' : ''; ?>"
							href="<?php echo esc_url($url); ?>">
							<span class="uams-mra-tier-name"><?php echo esc_html($tier['label']); ?></span>
							<strong class="uams-mra-stat-value"><?php echo esc_html(number_format_i18n($triage['tier'][$key])); ?></strong>
							<span class="uams-mra-muted"><?php echo esc_html($tier['desc']); ?></span>
						</a>
					<?php } ?>
				</div>

				<form method="get" class="uams-mra-filters">
					<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>" />
					<input type="hidden" name="view" value="<?php echo esc_attr($view); ?>" />

					<label for="uams-mra-filter-tier" class="screen-reader-text"><?php esc_html_e('Filter by tier', 'uams-mra'); ?></label>
					<select name="tier" id="uams-mra-filter-tier">
						<option value=""><?php esc_html_e('Every tier', 'uams-mra'); ?></option>
						<?php foreach (UAMS_MRA_Triage::tiers() as $key => $tier) { ?>
							<option value="<?php echo esc_attr($key); ?>" <?php selected(UAMS_MRA_List_Table::current_tier(), $key); ?>>
								<?php echo esc_html($tier['label']); ?>
							</option>
						<?php } ?>
					</select>

					<label for="uams-mra-filter-disp" class="screen-reader-text"><?php esc_html_e('Filter by decision', 'uams-mra'); ?></label>
					<select name="disp" id="uams-mra-filter-disp">
						<option value=""><?php esc_html_e('Any decision', 'uams-mra'); ?></option>
						<option value="none" <?php selected(UAMS_MRA_List_Table::current_disposition(), 'none'); ?>>
							<?php
			            /* translators: %s: number of files. */
			            echo esc_html(sprintf(__('Undecided (%s)', 'uams-mra'), number_format_i18n($triage['none'])));
			    ?>
						</option>
						<?php foreach (UAMS_MRA_Triage::dispositions() as $key => $label) { ?>
							<option value="<?php echo esc_attr($key); ?>" <?php selected(UAMS_MRA_List_Table::current_disposition(), $key); ?>>
								<?php echo esc_html($label.' ('.number_format_i18n($triage['disp'][$key]).')'); ?>
							</option>
						<?php } ?>
					</select>

					<?php submit_button(__('Filter', 'uams-mra'), 'secondary', '', false); ?>

					<?php if (UAMS_MRA_List_Table::current_tier() || UAMS_MRA_List_Table::current_disposition()) { ?>
						<a class="button-link" href="<?php echo esc_url(add_query_arg('view', $view, menu_page_url(self::SLUG, false))); ?>">
							<?php esc_html_e('Clear filters', 'uams-mra'); ?>
						</a>
					<?php } ?>
				</form>
			<?php } ?>

			<form method="post" id="uams-mra-results-form">
				<?php
			    wp_nonce_field('bulk-media_items');
        echo '<input type="hidden" name="page" value="'.esc_attr(self::SLUG).'" />';
        echo '<input type="hidden" name="view" value="'.esc_attr($view).'" />';
        $table->views();
        $table->search_box(__('Search media', 'uams-mra'), 'uams-mra-search');
        $table->display();
        ?>

				<p class="uams-mra-force">
					<label>
						<input type="checkbox" name="uams_mra_force" value="1" />
						<?php esc_html_e('Also delete files that still have references. Leave this off unless you have checked each one.', 'uams-mra'); ?>
					</label>
				</p>

				<?php if ($triage !== []) { ?>
					<p class="uams-mra-marked"<?php echo $triage['disp']['delete'] > 0 ? '' : ' hidden'; ?>>
						<button type="submit" name="uams_mra_delete_marked" value="1" id="uams-mra-delete-marked" class="button button-secondary">
							<?php
                    /* translators: 1: number of files, 2: disk space. */
                    echo esc_html(sprintf(__('Delete the %1$s files marked Delete (%2$s)', 'uams-mra'), number_format_i18n($triage['disp']['delete']), size_format($triage['delete_bytes'], 1)));
				    ?>
						</button>
						<span class="uams-mra-muted">
							<?php esc_html_e('Acts on every file marked Delete, not on the checkboxes above.', 'uams-mra'); ?>
						</span>
					</p>
				<?php } ?>
			</form>

			<p>
				<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uams_mra_export&view='.$view), 'uams_mra_export')); ?>">
					<?php esc_html_e('Download this list as CSV', 'uams-mra'); ?>
				</a>
			</p>

			<?php if ($scanned && ! empty($summary['total'])) { ?>
				<h2 class="uams-mra-settings-heading"><?php esc_html_e('Archive and delete', 'uams-mra'); ?></h2>
				<p class="uams-mra-muted uams-mra-tier-intro">
					<?php esc_html_e('Gather a set of files, look at it as one list, then download it as a zip and delete it. The zip is what makes the deletion reversible, so build it first.', 'uams-mra'); ?>
				</p>

				<div class="uams-mra-select">
					<form method="post" class="uams-mra-select-card">
						<?php wp_nonce_field('uams_mra_select'); ?>
						<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>" />
						<input type="hidden" name="view" value="<?php echo esc_attr($view); ?>" />
						<input type="hidden" name="tier" value="<?php echo esc_attr(UAMS_MRA_List_Table::current_tier()); ?>" />
						<input type="hidden" name="disp" value="<?php echo esc_attr(UAMS_MRA_List_Table::current_disposition()); ?>" />
						<?php
				        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				        $search_term = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
			    ?>
						<input type="hidden" name="s" value="<?php echo esc_attr($search_term); ?>" />

						<h3><?php esc_html_e('Everything matching this filter', 'uams-mra'); ?></h3>
						<p class="uams-mra-muted">
							<?php
			        /* translators: %s: number of files. */
			        echo esc_html(sprintf(__('All %s files currently filtered, not just the ones on this page.', 'uams-mra'), number_format_i18n($table->get_pagination_arg('total_items'))));
			    ?>
						</p>
						<button type="submit" name="uams_mra_select_filter" value="1" class="button button-primary">
							<?php esc_html_e('Review these files', 'uams-mra'); ?>
						</button>
					</form>

					<form method="post" class="uams-mra-select-card">
						<?php wp_nonce_field('uams_mra_select'); ?>
						<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>" />

						<h3><?php esc_html_e('A list from somewhere else', 'uams-mra'); ?></h3>
						<p class="uams-mra-muted">
							<?php esc_html_e('Paste attachment IDs, filenames, or URLs from a content audit — one per line or comma separated. Anything that matches nothing is reported rather than skipped quietly.', 'uams-mra'); ?>
						</p>
						<textarea name="pasted" rows="4" class="large-text code" placeholder="1215&#10;stroke-program-issue-4.pdf&#10;https://example.org/wp-content/uploads/2024/01/photo.jpg"></textarea>
						<button type="submit" name="uams_mra_select_text" value="1" class="button">
							<?php esc_html_e('Review this list', 'uams-mra'); ?>
						</button>
					</form>
				</div>
			<?php } ?>

			<h2 class="uams-mra-settings-heading"><?php esc_html_e('Scan settings', 'uams-mra'); ?></h2>

			<form method="post">
				<?php wp_nonce_field('uams_mra_settings'); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e('Where to look', 'uams-mra'); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="include_numeric_meta" value="1" <?php checked($settings['include_numeric_meta']); ?> /> <?php esc_html_e('Count a custom field that stores only an ID as a reference. Keep this on for ACF and page builder sites.', 'uams-mra'); ?></label><br />
									<label><input type="checkbox" name="include_revisions" value="1" <?php checked($settings['include_revisions']); ?> /> <?php esc_html_e('Count references found in post revisions.', 'uams-mra'); ?></label><br />
									<label><input type="checkbox" name="include_termmeta" value="1" <?php checked($settings['include_termmeta']); ?> /> <?php esc_html_e('Category, tag, and taxonomy fields.', 'uams-mra'); ?></label><br />
									<label><input type="checkbox" name="include_usermeta" value="1" <?php checked($settings['include_usermeta']); ?> /> <?php esc_html_e('User profile fields.', 'uams-mra'); ?></label><br />
									<label><input type="checkbox" name="include_comments" value="1" <?php checked($settings['include_comments']); ?> /> <?php esc_html_e('Comment fields.', 'uams-mra'); ?></label>
									<?php if (is_multisite()) { ?>
										<br /><label><input type="checkbox" name="include_network" value="1" <?php checked($settings['include_network']); ?> /> <?php esc_html_e('Other sites in this network. Slower, but it catches images this site hosts and another site displays.', 'uams-mra'); ?></label>
									<?php } ?>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Read the page itself', 'uams-mra'); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="include_render" value="1" <?php checked($settings['include_render']); ?> /> <?php esc_html_e('After the database scan, open the published page each unexplained file is attached to and look for the file in what the page actually renders.', 'uams-mra'); ?></label>
									<p class="description"><?php esc_html_e('This is the only way to see a file a template lists at render time, a shortcode builds, or a theme file hardcodes -- none of which leave anything in the database to find. It only runs for files that finished the scan with no reference and a published parent, so it is usually a handful of pages. A page that cannot be fetched is reported as unchecked, never as unused.', 'uams-mra'); ?></p>
									<label><?php esc_html_e('Pages per batch', 'uams-mra'); ?> <input type="number" name="batch_render" value="<?php echo esc_attr($settings['batch_render']); ?>" min="1" max="25" class="small-text" /></label>
									<label><?php esc_html_e('Most pages per scan', 'uams-mra'); ?> <input type="number" name="render_max_pages" value="<?php echo esc_attr($settings['render_max_pages']); ?>" min="1" max="5000" class="small-text" /></label>
									<br /><label><?php esc_html_e('Offsite files compared or rewritten per batch', 'uams-mra'); ?> <input type="number" name="batch_repoint" value="<?php echo esc_attr($settings['batch_repoint']); ?>" min="1" max="25" class="small-text" /></label>
									<label><?php esc_html_e('Files downloaded per batch', 'uams-mra'); ?> <input type="number" name="batch_sideload" value="<?php echo esc_attr($settings['batch_sideload']); ?>" min="1" max="10" class="small-text" /></label>
									<label><?php esc_html_e('Largest file to pull over (MB)', 'uams-mra'); ?> <input type="number" name="sideload_max_mb" value="<?php echo esc_attr($settings['sideload_max_mb']); ?>" min="1" max="2048" class="small-text" /></label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Offsite media', 'uams-mra'); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="include_offsite" value="1" <?php checked($settings['include_offsite']); ?> /> <?php esc_html_e('While scanning, record uploads URLs that point at another site.', 'uams-mra'); ?></label>
									<p class="description">
										<?php
			                printf(
			                    /* translators: %s: link to the offsite media report. */
			                    esc_html__('Costs nothing extra -- it reads the content the scan is already reading. Results appear under %s. Useful when a site was migrated and its pages still serve media from the host it moved off.', 'uams-mra'),
			                    '<a href="'.esc_url(admin_url('upload.php?page='.self::SLUG_OFFSITE)).'">'.esc_html__('Media > Offsite Media', 'uams-mra').'</a>'
			                );
        ?>
									</p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="extra_paths"><?php esc_html_e('Extra upload paths', 'uams-mra'); ?></label></th>
							<td>
								<textarea id="extra_paths" name="extra_paths" rows="3" class="large-text code"><?php echo esc_textarea($settings['extra_paths']); ?></textarea>
								<p class="description"><?php esc_html_e('One path per line. Add the path a CDN or offload plugin rewrites media to, for example media/library. The default uploads path is always checked.', 'uams-mra'); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Batch sizes', 'uams-mra'); ?></th>
							<td>
								<label><?php esc_html_e('Posts', 'uams-mra'); ?> <input type="number" name="batch_posts" value="<?php echo esc_attr($settings['batch_posts']); ?>" min="10" max="10000" class="small-text" /></label>
								<label><?php esc_html_e('Meta rows', 'uams-mra'); ?> <input type="number" name="batch_meta" value="<?php echo esc_attr($settings['batch_meta']); ?>" min="10" max="10000" class="small-text" /></label>
								<label><?php esc_html_e('Index', 'uams-mra'); ?> <input type="number" name="batch_index" value="<?php echo esc_attr($settings['batch_index']); ?>" min="10" max="10000" class="small-text" /></label>
								<label><?php esc_html_e('Results', 'uams-mra'); ?> <input type="number" name="batch_finalize" value="<?php echo esc_attr($settings['batch_finalize']); ?>" min="10" max="10000" class="small-text" /></label>
								<p class="description"><?php esc_html_e('Lower these if the scan times out on shared hosting.', 'uams-mra'); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(__('Save settings', 'uams-mra'), 'secondary', 'uams_mra_save_settings'); ?>
			</form>
		</div>
		<?php
    }

    /* ---------------------------------------------------------------------
     * Repoint
     * ------------------------------------------------------------------ */

    /**
     * Guard shared by the repoint endpoints.
     */
    protected function repoint_guard()
    {
        check_ajax_referer('uams_mra_repoint', 'nonce');

        if (! current_user_can(UAMS_MRA_CAP)) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'uams-mra')], 403);
        }
    }

    /**
     * Shape a repoint job for the browser.
     *
     * @param  array  $job  Job.
     */
    protected function repoint_payload($job): array
    {
        $done = ('done' === ($job['phase'] ?? 'done'));

        return [
            'phase' => $job['phase'] ?? 'done',
            'message' => $job['message'] ?? '',
            'total' => (int) ($job['total'] ?? 0),
            'progress' => (int) ($job['done'] ?? 0),
            'applied' => (int) ($job['applied'] ?? 0),
            'skipped' => (int) ($job['skipped'] ?? 0),
            'failed' => (int) ($job['failed'] ?? 0),
            'done' => $done,
        ];
    }

    /**
     * Run a repoint entry point, reporting whatever goes wrong.
     *
     * A fatal inside one of these reaches the browser as a dead connection and
     * the screen can only say "something failed", which helps nobody. Catching
     * it here turns it into a message a person can act on or send on.
     *
     * @param  callable  $work  Returns a job array.
     */
    protected function repoint_respond(callable $work)
    {
        $this->repoint_guard();

        // Armed before the work starts so a fatal is recorded even though this
        // method never gets to return.
        UAMS_MRA_Repoint::watch_for_fatal(UAMS_MRA_Repoint::next_target());

        try {
            wp_send_json_success($this->repoint_payload($work()));
        } catch (Throwable $throwable) {
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: 1: error message, 2: file, 3: line. */
                        __('Stopped: %1$s (%2$s line %3$d)', 'uams-mra'),
                        $throwable->getMessage(),
                        basename($throwable->getFile()),
                        $throwable->getLine()
                    ),
                ],
                500
            );
        }
    }

    /**
     * Start the read-only comparison pass.
     */
    public function ajax_repoint_plan(): void
    {
        $this->repoint_respond(['UAMS_MRA_Repoint', 'start_plan']);
    }

    /**
     * Start the rewriting pass.
     */
    public function ajax_repoint_apply(): void
    {
        $resized = ! empty($_POST['resized']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in repoint_guard().

        $this->repoint_respond(
            static function () use ($resized): array {
                // Saving a page as anyone else runs it through the content filters,
                // which can change what is on it. See UAMS_MRA_Repoint::rewrite_row().
                if (! current_user_can('unfiltered_html')) {
                    wp_send_json_error(
                        ['message' => __('Rewriting pages needs the unfiltered_html capability, because saving a page as anyone else runs it through the content filter and can change what it holds. Ask a user who has it (on multisite, a network administrator).', 'uams-mra')],
                        403
                    );
                }

                return UAMS_MRA_Repoint::start_apply($resized);
            }
        );
    }

    /**
     * Start pulling missing files into this library.
     */
    public function ajax_repoint_sideload(): void
    {
        $this->repoint_respond(['UAMS_MRA_Repoint', 'start_sideload']);
    }

    /**
     * Run one batch of whichever pass is going.
     */
    public function ajax_repoint_step(): void
    {
        $this->repoint_respond(['UAMS_MRA_Repoint', 'step']);
    }

    /* ---------------------------------------------------------------------
     * Offsite media report
     * ------------------------------------------------------------------ */

    /**
     * Media this site displays but does not host.
     *
     * Read-only on purpose. Nothing here is fetched, rewritten, or deleted --
     * the report exists so the scope of a half-finished migration is visible
     * before anyone decides what to do about it.
     */
    public function render_offsite(): void
    {
        if (! current_user_can(UAMS_MRA_CAP)) {
            return;
        }

        $summary = UAMS_MRA_Store::offsite_summary();
        $hosts = UAMS_MRA_Store::offsite_hosts();
        $host = isset($_GET['host']) ? sanitize_text_field(wp_unslash($_GET['host'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 100;

        $valid_hosts = wp_list_pluck($hosts, 'host');
        if ($host !== '' && ! in_array($host, $valid_hosts, true)) {
            $host = '';
        }

        $total = UAMS_MRA_Store::offsite_file_count($host);
        $files = UAMS_MRA_Store::offsite_files($host, $per_page, ($paged - 1) * $per_page);
        $pages = max(1, (int) ceil($total / $per_page));
        ?>
		<div class="wrap uams-mra">
			<h1><?php esc_html_e('Offsite Media', 'uams-mra'); ?></h1>

			<p class="uams-mra-lede">
				<?php esc_html_e('Media this site displays but does not host: uploads URLs stored in your content, custom fields, or settings that point at another site. A site part-way through a migration keeps serving files from the host it moved off, which is fine until that host goes away.', 'uams-mra'); ?>
			</p>

			<?php if (empty($summary['hits'])) { ?>
				<div class="notice notice-info inline">
					<p>
						<?php
                        printf(
                            /* translators: %s: link to the media audit screen. */
                            esc_html__('No offsite media found. This report is filled in by the media scan -- run one from %s if you have not yet.', 'uams-mra'),
                            '<a href="'.esc_url(admin_url('upload.php?page='.self::SLUG)).'">'.esc_html__('Media Audit', 'uams-mra').'</a>'
                        );
			    ?>
					</p>
				</div>
			</div>
			<?php return;
			} ?>

			<div class="uams-mra-cards">
				<div class="uams-mra-card">
					<span class="uams-mra-stat-label"><?php esc_html_e('Offsite files', 'uams-mra'); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html(number_format_i18n($summary['files'])); ?></strong>
				</div>
				<div class="uams-mra-card">
					<span class="uams-mra-stat-label"><?php esc_html_e('Places they are stored', 'uams-mra'); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html(number_format_i18n($summary['hits'])); ?></strong>
				</div>
				<div class="uams-mra-card">
					<span class="uams-mra-stat-label"><?php esc_html_e('Hosts', 'uams-mra'); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html(number_format_i18n($summary['hosts'])); ?></strong>
				</div>
				<div class="uams-mra-card">
					<span class="uams-mra-stat-label"><?php esc_html_e('Already in this library by name', 'uams-mra'); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html(number_format_i18n($summary['with_local'])); ?></strong>
					<span class="uams-mra-muted"><?php esc_html_e('A local file shares the filename. It may or may not be the same document.', 'uams-mra'); ?></span>
				</div>
			</div>

			<p>
				<?php foreach ($hosts as $row) { ?>
					<a class="button<?php echo $host === $row['host'] ? ' button-primary' : ''; ?>"
						href="<?php echo esc_url(add_query_arg(['page' => self::SLUG_OFFSITE, 'host' => $host === $row['host'] ? false : $row['host']], admin_url('upload.php'))); ?>">
						<?php echo esc_html($row['host']); ?>
						<span class="uams-mra-muted">(<?php echo esc_html(number_format_i18n($row['files'])); ?>)</span>
					</a>
				<?php } ?>

				<a class="button" style="float:right"
					href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=uams_mra_offsite_export&host='.rawurlencode($host)), 'uams_mra_offsite_export')); ?>">
					<?php esc_html_e('Export CSV', 'uams-mra'); ?>
				</a>
			</p>

			<?php
			$counts = UAMS_MRA_Repoint::counts();
        $applied = UAMS_MRA_Repoint::applied_counts();
        $verdicts = UAMS_MRA_Repoint::verdicts();
        $planned = array_sum(wp_list_pluck($counts, 'files'));
        // Freshly pulled files are eligible exactly like a byte-identical
        // match, so the button has to count them. Reading only "identical"
        // left the screen with no way to repoint what it had just
        // downloaded, short of running the whole comparison again.
        $ready = 0;
        $ready_rows = 0;

        foreach (UAMS_MRA_Repoint::eligible_verdicts() as $verdict) {
            $ready += (int) ($counts[$verdict]['files'] ?? 0);
            $ready_rows += (int) ($counts[$verdict]['rows'] ?? 0);
        }
        $resized = (int) ($counts['resized']['files'] ?? 0);
        ?>
			<?php $stale = UAMS_MRA_Store::offsite_needs_rescan(); ?>

			<div class="uams-mra-repoint" id="uams-mra-repoint">
				<h2>
					<?php esc_html_e('Repoint what this library already has', 'uams-mra'); ?>
					<span class="uams-mra-muted" style="font-weight:normal;font-size:13px">
						<?php
                    /* translators: %s: plugin version. */
                    printf(esc_html__('plugin v%s', 'uams-mra'), esc_html(UAMS_MRA_VERSION));
        ?>
					</span>
				</h2>

				<?php $fatal = UAMS_MRA_Repoint::last_fatal(); ?>

				<?php if (! empty($fatal['message'])) { ?>
					<div class="notice notice-error inline">
						<p><strong><?php esc_html_e('The last attempt died. This is what killed it:', 'uams-mra'); ?></strong></p>
						<p><code><?php echo esc_html($fatal['message']); ?></code></p>
						<p class="uams-mra-muted">
							<?php echo esc_html(basename((string) $fatal['file']).' line '.(int) $fatal['line']); ?>
							<?php if (! empty($fatal['context'])) { ?>
								<br /><?php esc_html_e('While handling:', 'uams-mra'); ?> <code><?php echo esc_html($fatal['context']); ?></code>
							<?php } ?>
						</p>
					</div>
				<?php } ?>

				<?php if ($stale) { ?>

					<?php // No buttons at all here. A stale report can only mislead.?>
					<div class="notice notice-warning inline">
						<p>
							<?php
            printf(
                /* translators: %s: link to the media audit screen. */
                esc_html__('This report was built by an older version of the plugin and does not record which database row each URL sits in, so nothing here can be rewritten yet. Run a scan from %s and come back.', 'uams-mra'),
                '<a href="'.esc_url(admin_url('upload.php?page='.self::SLUG)).'">'.esc_html__('Media Audit', 'uams-mra').'</a>'
            );
				    ?>
						</p>
					</div>

				<?php } else { ?>

				<p class="uams-mra-muted">
					<?php esc_html_e('Where a file of the same name is already in this library, the stored URL can be rewritten to point at it. Nothing is downloaded and no attachment is created. The comparison below only reads -- it fetches each offsite file and checks its size against the local copy, and changes nothing until you say so.', 'uams-mra'); ?>
				</p>

				<p>
					<button type="button" class="button" id="uams-mra-repoint-plan"><?php echo $planned ? esc_html__('Compare again', 'uams-mra') : esc_html__('Compare with this library', 'uams-mra'); ?></button>
					<span class="uams-mra-repoint-status" id="uams-mra-repoint-status"></span>
				</p>

				<?php if ($planned) { ?>
					<table class="widefat striped uams-mra-verdicts">
						<tbody>
						<?php foreach ($verdicts as $key => $desc) { ?>
							<?php if (empty($counts[$key]['files'])) {
							    continue;
							} ?>
							<tr>
								<td style="width:7em"><strong><?php echo esc_html(number_format_i18n($counts[$key]['files'])); ?></strong></td>
								<td style="width:9em"><code><?php echo esc_html($key); ?></code></td>
								<td><?php echo esc_html($desc); ?></td>
							</tr>
						<?php } ?>
						</tbody>
					</table>

					<?php
                    $nolocal = (int) ($counts['nolocal']['files'] ?? 0);
				    if ($nolocal) {
				        ?>
						<p>
							<button type="button" class="button" id="uams-mra-repoint-sideload" data-count="<?php echo esc_attr((string) $nolocal); ?>">
								<?php
				                printf(
				                    /* translators: %s: number of files. */
				                    esc_html__('Pull %s missing files into this library', 'uams-mra'),
				                    esc_html(number_format_i18n($nolocal))
				                );
				        ?>
							</button>
						</p>
						<p class="uams-mra-muted">
							<?php esc_html_e('Downloads the original behind each URL -- not the thumbnail a page happens to ask for -- so one upload covers every size, and WordPress regenerates the rest. Each file is put through the same upload checks as the media screen. This adds files; it rewrites nothing until you repoint afterwards.', 'uams-mra'); ?>
						</p>
					<?php } ?>

					<?php if ($ready || $resized) { ?>
						<p>
							<?php if ($resized) { ?>
								<label>
									<input type="checkbox" id="uams-mra-repoint-resized" />
									<?php
				            printf(
				                /* translators: %s: number of weaker matches. */
				                esc_html__('Also repoint the %s resized matches', 'uams-mra'),
				                esc_html(number_format_i18n($resized))
				            );
							    ?>
								</label><br />
							<?php } ?>

							<button type="button" class="button button-primary" id="uams-mra-repoint-apply"
								data-ready="<?php echo esc_attr((string) $ready); ?>">
								<?php
							    printf(
							        /* translators: 1: files, 2: stored URLs. */
							        esc_html__('Repoint %1$s files (%2$s stored URLs)', 'uams-mra'),
							        esc_html(number_format_i18n($ready)),
							        esc_html(number_format_i18n($ready_rows))
							    );
					    ?>
							</button>
						</p>
						<p class="uams-mra-muted">
							<?php esc_html_e('Pages are saved through the editor, so the previous content is kept as a revision. Custom fields and options are written directly and have no revision -- take a database backup first. Revisions themselves are never rewritten.', 'uams-mra'); ?>
						</p>
					<?php } ?>
				<?php } ?>

				<?php if (! empty($applied['applied']) || ! empty($applied['failed'])) { ?>
					<p class="uams-mra-muted">
						<?php
					    printf(
					        /* translators: 1: rewritten, 2: left alone, 3: failed. */
					        esc_html__('Already done: %1$s rewritten, %2$s left alone, %3$s failed. Rescan to see the report rebuilt.', 'uams-mra'),
					        esc_html(number_format_i18n($applied['applied'] ?? 0)),
					        esc_html(number_format_i18n($applied['skipped'] ?? 0)),
					        esc_html(number_format_i18n($applied['failed'] ?? 0))
					    );
				    ?>
					</p>
				<?php } ?>

				<?php } ?>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e('File', 'uams-mra'); ?></th>
						<th scope="col" style="width:14em"><?php esc_html_e('Host', 'uams-mra'); ?></th>
						<th scope="col" style="width:12em"><?php esc_html_e('Local copy by name', 'uams-mra'); ?></th>
						<th scope="col"><?php esc_html_e('Stored in', 'uams-mra'); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($files as $file) { ?>
					<tr>
						<td>
							<a href="<?php echo esc_url($file['url']); ?>" target="_blank" rel="noopener"><code><?php echo esc_html($file['base_norm']); ?></code></a>
							<div class="uams-mra-muted"><?php echo esc_html($file['url']); ?></div>
						</td>
						<td><?php echo esc_html($file['host']); ?></td>
						<td>
							<?php if (! empty($file['local_ids'])) { ?>
								<span class="uams-mra-badge uams-mra-badge-info"><?php esc_html_e('Yes', 'uams-mra'); ?></span>
								<span class="uams-mra-muted">#<?php echo esc_html($file['local_ids']); ?></span>
							<?php } else { ?>
								<span class="uams-mra-badge uams-mra-badge-warn"><?php esc_html_e('No', 'uams-mra'); ?></span>
							<?php } ?>
						</td>
						<td>
							<?php
				        $sources = UAMS_MRA_Store::offsite_sources($file['url_hash'], 6);

				    foreach ($sources as $index => $source) {
				        if ($index) {
				            echo '<br />';
				        }
				        echo wp_kses_post($this->offsite_source_line($source));
				    }

				    $extra = (int) $file['hits'] - count($sources);
				    if ($extra > 0) {
				        echo ' <span class="uams-mra-muted">';
				        printf(
				            /* translators: %s: number of further places the URL is stored. */
				            esc_html__('and %s more', 'uams-mra'),
				            esc_html(number_format_i18n($extra))
				        );
				        echo '</span>';
				    }
				    ?>
						</td>
					</tr>
				<?php } ?>
				</tbody>
			</table>

			<?php if ($pages > 1) { ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
				        echo wp_kses_post(
				            paginate_links(
				                [
				                    'base' => add_query_arg('paged', '%#%'),
				                    'format' => '',
				                    'current' => $paged,
				                    'total' => $pages,
				                    'prev_text' => '&laquo;',
				                    'next_text' => '&raquo;',
				                ]
				            )
				        );
			    ?>
					</div>
				</div>
			<?php } ?>

			<h2><?php esc_html_e('What to do with this', 'uams-mra'); ?></h2>
			<p class="uams-mra-muted">
				<?php esc_html_e('A URL listed here is stored in the database, so it can be rewritten once the file lives in this library. A file the theme prints at render time will not appear here at all -- the page check on the Media Audit screen is the only thing that can see those. Nothing on this screen changes anything: pulling the files over is a separate step, and it edits post content.', 'uams-mra'); ?>
			</p>
		</div>
		<?php
    }

    /**
     * One "stored in" entry, with a way to go and look at it.
     *
     * Working through this report means opening the page a URL sits on, so the
     * label carries the two links that saves: the page as a visitor sees it, and
     * the editor. Sources that are not a post -- an option, say -- have neither,
     * and get the label alone rather than a link to nowhere.
     *
     * @param  array  $source  Row from offsite_sources().
     * @return string HTML.
     */
    protected function offsite_source_line(array $source)
    {
        $label = esc_html(html_entity_decode((string) $source['source_label'], ENT_QUOTES, 'UTF-8'));
        $id = (int) $source['source_id'];
        $type = (string) $source['source_type'];

        if (! in_array($type, ['content', 'postmeta', 'revision', 'revision_meta'], true) || ! $id) {
            return $label;
        }

        $post = get_post($id);

        if (! $post) {
            return $label;
        }

        $links = [];

        $view = get_permalink($post);

        if ($view) {
            $links[] = '<a href="'.esc_url($view).'" target="_blank" rel="noopener">'.esc_html__('View', 'uams-mra').'</a>';
        }

        $edit = get_edit_post_link($post->ID);

        if ($edit) {
            $links[] = '<a href="'.esc_url($edit).'" target="_blank" rel="noopener">'.esc_html__('Edit', 'uams-mra').'</a>';
        }

        if ($links === []) {
            return $label;
        }

        return $label.' <span class="uams-mra-muted">('.implode(' &middot; ', $links).')</span>';
    }

    /**
     * Make one CSV cell safe to open in a spreadsheet.
     *
     * A cell that starts with = + - @ or a control character is read as a formula,
     * and a post title (which any author sets) ends up in this report.
     *
     * @param  mixed  $value  Cell text.
     */
    protected static function csv_cell($value): string
    {
        $value = (string) $value;

        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'".$value : $value;
    }

    /**
     * Stream the offsite report as CSV.
     */
    public function export_offsite_csv(): void
    {
        if (! current_user_can(UAMS_MRA_CAP)) {
            wp_die(esc_html__('You do not have permission to export this report.', 'uams-mra'));
        }

        check_admin_referer('uams_mra_offsite_export');

        $host = isset($_GET['host']) ? sanitize_text_field(wp_unslash($_GET['host'])) : '';

        $filename = 'uams-media-audit-offsite-'.sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST)).'-'.gmdate('Ymd').'.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename='.$filename);

        $out = fopen('php://output', 'w');

        fputcsv(
            $out,
            ['file', 'url', 'host', 'occurrences', 'source_types', 'local_attachment_ids', 'stored_in'],
            ',',
            '"',
            ''
        );

        $offset = 0;

        do {
            $files = UAMS_MRA_Store::offsite_files($host, 200, $offset);

            foreach ($files as $file) {
                $labels = [];
                foreach (UAMS_MRA_Store::offsite_sources($file['url_hash'], 50) as $source) {
                    $labels[] = $source['source_type'].' #'.$source['source_id'].' '.$source['source_label'];
                }

                fputcsv(
                    $out,
                    [
                        self::csv_cell($file['base_norm']),
                        self::csv_cell($file['url']),
                        self::csv_cell($file['host']),
                        $file['hits'],
                        $file['source_types'],
                        (string) $file['local_ids'],
                        self::csv_cell(implode(' | ', $labels)),
                    ],
                    ',',
                    '"',
                    ''
                );
            }

            $offset += 200;
        } while (count($files) === 200);

        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }
}
