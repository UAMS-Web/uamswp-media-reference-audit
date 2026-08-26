<?php
/**
 * Admin screen and request handling.
 *
 * @package UAMS_Media_Reference_Audit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires up the Media Audit screen.
 */
class UAMS_MRA_Admin {

	const SLUG = 'uams-media-audit';

	/**
	 * Hook everything up.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this, 'handle_requests' ) );
		add_action( 'admin_post_uams_mra_export', array( $this, 'export_csv' ) );
		add_action( 'wp_ajax_uams_mra_scan_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_uams_mra_scan_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_uams_mra_triage', array( $this, 'ajax_triage' ) );
		add_action( 'wp_ajax_uams_mra_archive_start', array( $this, 'ajax_archive_start' ) );
		add_action( 'wp_ajax_uams_mra_archive_step', array( $this, 'ajax_archive_step' ) );
		add_action( 'admin_post_uams_mra_archive_download', array( $this, 'download_archive' ) );
	}

	/**
	 * Register the submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			'upload.php',
			__( 'Media Audit', 'uams-mra' ),
			__( 'Media Audit', 'uams-mra' ),
			UAMS_MRA_CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Is the current screen ours.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	protected function is_screen( $hook ) {
		return 'media_page_' . self::SLUG === $hook;
	}

	/**
	 * Load assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( ! $this->is_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style( 'uams-mra-admin', UAMS_MRA_URL . 'assets/css/admin.css', array(), UAMS_MRA_VERSION );
		wp_enqueue_script( 'uams-mra-admin', UAMS_MRA_URL . 'assets/js/admin.js', array(), UAMS_MRA_VERSION, true );

		wp_localize_script(
			'uams-mra-admin',
			'UAMSMRA',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'uams_mra_scan' ),
				'triageNonce'  => wp_create_nonce( 'uams_mra_triage' ),
				'archiveNonce' => wp_create_nonce( 'uams_mra_archive' ),
				'i18n'    => array(
					'confirmDelete' => __( 'This permanently deletes the selected files and every generated size. It cannot be undone. Continue?', 'uams-mra' ),
					'confirmScan'   => __( 'Start a new scan? This replaces the previous results.', 'uams-mra' ),
					'failed'        => __( 'The scan stopped with an error. Reload the page and try again.', 'uams-mra' ),
					'noSelection'   => __( 'Select at least one file first.', 'uams-mra' ),
					'saved'         => __( 'Saved', 'uams-mra' ),
					'saveFailed'    => __( 'Not saved', 'uams-mra' ),
					'confirmMarked' => __( 'Permanently delete every file marked Delete? This cannot be undone.', 'uams-mra' ),
					'archiving'     => __( 'Building the archive…', 'uams-mra' ),
					'archiveFailed' => __( 'The archive stopped with an error. Reload and try again.', 'uams-mra' ),
					'archiveDone'   => __( 'Archive ready.', 'uams-mra' ),
				),
			)
		);
	}

	/**
	 * Handle settings saves and bulk deletes.
	 */
	public function handle_requests() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( UAMS_MRA_CAP ) ) {
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
	protected function maybe_save_settings() {
		if ( ! isset( $_POST['uams_mra_save_settings'] ) ) {
			return;
		}

		check_admin_referer( 'uams_mra_settings' );

		$defaults = uams_mra_default_settings();
		$settings = array();

		foreach ( array( 'include_revisions', 'include_numeric_meta', 'include_usermeta', 'include_termmeta', 'include_comments', 'include_network' ) as $key ) {
			$settings[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
		}

		$settings['extra_paths'] = isset( $_POST['extra_paths'] )
			? sanitize_textarea_field( wp_unslash( $_POST['extra_paths'] ) )
			: '';

		foreach ( array( 'batch_posts', 'batch_meta', 'batch_index', 'batch_finalize' ) as $key ) {
			$value            = isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : $defaults[ $key ];
			$settings[ $key ] = max( 10, min( 10000, $value ) );
		}

		update_option( 'uams_mra_settings', $settings );

		add_settings_error( 'uams-mra', 'uams_mra_saved', __( 'Settings saved.', 'uams-mra' ), 'success' );
	}

	/**
	 * Run a bulk delete.
	 */
	protected function maybe_delete() {
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( 'uams_mra_delete' !== $action ) {
			$action = isset( $_POST['action2'] ) ? sanitize_key( wp_unslash( $_POST['action2'] ) ) : '';
		}

		if ( 'uams_mra_delete' !== $action ) {
			return;
		}

		check_admin_referer( 'bulk-media_items' );

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			add_settings_error( 'uams-mra', 'uams_mra_none', __( 'No files were selected, so nothing was deleted.', 'uams-mra' ), 'warning' );
			return;
		}

		$this->report_delete( $this->delete_ids( $ids, ! empty( $_POST['uams_mra_force'] ) ) );
	}

	/**
	 * Delete every file currently marked Delete.
	 *
	 * Deliberately a button of its own rather than an entry in the bulk-actions
	 * menu: it acts on the marks, not on the ticked checkboxes, and a menu entry
	 * that quietly ignores the selection would be a trap.
	 */
	protected function maybe_delete_marked() {
		if ( ! isset( $_POST['uams_mra_delete_marked'] ) ) {
			return;
		}

		check_admin_referer( 'bulk-media_items' );

		$ids = UAMS_MRA_Triage::ids_marked( 'delete' );

		if ( empty( $ids ) ) {
			add_settings_error( 'uams-mra', 'uams_mra_none', __( 'Nothing is marked Delete, so nothing was deleted.', 'uams-mra' ), 'warning' );
			return;
		}

		$this->report_delete( $this->delete_ids( $ids, ! empty( $_POST['uams_mra_force'] ) ) );
	}

	/**
	 * Permanently delete attachments, honouring the reference guard.
	 *
	 * @param array $ids   Attachment IDs.
	 * @param bool  $force Delete even when references were recorded.
	 * @return array {deleted, skipped, bytes}
	 */
	protected function delete_ids( array $ids, $force ) {
		global $wpdb;

		$table   = UAMS_MRA_Store::results_table();
		$deleted = 0;
		$skipped = 0;
		$bytes   = 0;

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( 'attachment' !== get_post_type( $id ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT ref_count, bytes FROM {$table} WHERE attachment_id = %d", $id ), ARRAY_A );

			if ( $row && (int) $row['ref_count'] > 0 && ! $force ) {
				++$skipped;
				continue;
			}

			if ( wp_delete_attachment( $id, true ) ) {
				++$deleted;
				$bytes += $row ? (int) $row['bytes'] : 0;
				$wpdb->delete( $table, array( 'attachment_id' => $id ), array( '%d' ) );
			}
		}

		return array(
			'deleted' => $deleted,
			'skipped' => $skipped,
			'bytes'   => $bytes,
		);
	}

	/**
	 * Turn a delete run into an admin notice.
	 *
	 * @param array $result From delete_ids().
	 */
	protected function report_delete( array $result ) {
		$message = sprintf(
			/* translators: 1: number of files, 2: disk space freed. */
			__( 'Deleted %1$s files and freed %2$s.', 'uams-mra' ),
			number_format_i18n( $result['deleted'] ),
			size_format( $result['bytes'], 1 )
		);

		if ( $result['skipped'] ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of files. */
				__( 'Kept %s files that still have references. Tick the override box to delete those.', 'uams-mra' ),
				number_format_i18n( $result['skipped'] )
			);
		}

		add_settings_error( 'uams-mra', 'uams_mra_deleted', $message, $result['skipped'] ? 'warning' : 'success' );
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
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, menu_page_url( self::SLUG, false ) );
	}

	/**
	 * Park a message for the next page load.
	 *
	 * add_settings_error() does not survive the redirect that follows a delete,
	 * and rendering the result inline would leave a resubmit on refresh.
	 *
	 * @param string $message Message.
	 * @param string $type    success, warning, or error.
	 */
	protected function notice( $message, $type = 'success' ) {
		update_user_meta(
			get_current_user_id(),
			'_uams_mra_notice',
			array(
				'message' => $message,
				'type'    => $type,
			)
		);
	}

	/**
	 * Show and forget any parked message.
	 */
	protected function flash_notice() {
		$notice = get_user_meta( get_current_user_id(), '_uams_mra_notice', true );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_user_meta( get_current_user_id(), '_uams_mra_notice' );

		add_settings_error( 'uams-mra', 'uams_mra_flash', $notice['message'], $notice['type'] );
	}

	/**
	 * Build a working set from the filter on screen or from pasted text.
	 */
	protected function maybe_build_selection() {
		$from_filter = isset( $_POST['uams_mra_select_filter'] );
		$from_text   = isset( $_POST['uams_mra_select_text'] );

		if ( ! $from_filter && ! $from_text ) {
			return;
		}

		check_admin_referer( 'uams_mra_select' );

		if ( $from_filter ) {
			$ids = UAMS_MRA_Selection::ids_matching_filter();

			if ( empty( $ids ) ) {
				$this->notice( __( 'That filter matches no files.', 'uams-mra' ), 'warning' );
				wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url() );
				exit;
			}

			UAMS_MRA_Selection::store( $ids );
			wp_safe_redirect( self::url( array( 'action' => 'review' ) ) );
			exit;
		}

		$text     = isset( $_POST['pasted'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pasted'] ) ) : '';
		$resolved = UAMS_MRA_Selection::resolve_text( $text );

		if ( empty( $resolved['ids'] ) ) {
			$this->notice( __( 'None of that matched a file in the last scan. Paste attachment IDs, filenames, or URLs.', 'uams-mra' ), 'warning' );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url() );
			exit;
		}

		UAMS_MRA_Selection::store( $resolved['ids'] );

		if ( ! empty( $resolved['unmatched'] ) ) {
			$this->notice(
				sprintf(
					/* translators: 1: number matched, 2: number not matched, 3: the unmatched entries. */
					__( 'Matched %1$s files. %2$s entries matched nothing and were left out: %3$s', 'uams-mra' ),
					number_format_i18n( count( $resolved['ids'] ) ),
					number_format_i18n( count( $resolved['unmatched'] ) ),
					implode( ', ', array_slice( $resolved['unmatched'], 0, 20 ) )
				),
				'warning'
			);
		}

		wp_safe_redirect( self::url( array( 'action' => 'review' ) ) );
		exit;
	}

	/**
	 * Delete the working set.
	 */
	protected function maybe_delete_selection() {
		if ( ! isset( $_POST['uams_mra_delete_selection'] ) ) {
			return;
		}

		check_admin_referer( 'uams_mra_review' );

		$ids = UAMS_MRA_Selection::get();

		if ( empty( $ids ) ) {
			$this->notice( __( 'Nothing is selected, so nothing was deleted.', 'uams-mra' ), 'warning' );
			wp_safe_redirect( self::url() );
			exit;
		}

		$result = $this->delete_ids( $ids, ! empty( $_POST['uams_mra_force'] ) );

		$message = sprintf(
			/* translators: 1: number of files, 2: disk space freed. */
			__( 'Deleted %1$s files and freed %2$s.', 'uams-mra' ),
			number_format_i18n( $result['deleted'] ),
			size_format( $result['bytes'], 1 )
		);

		if ( $result['skipped'] ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of files. */
				__( 'Kept %s that still have references. Tick the override box to delete those too.', 'uams-mra' ),
				number_format_i18n( $result['skipped'] )
			);
		}

		$this->notice( $message, $result['skipped'] ? 'warning' : 'success' );

		UAMS_MRA_Selection::clear();

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Throw away the built archive.
	 */
	protected function maybe_discard_archive() {
		if ( ! isset( $_POST['uams_mra_discard_archive'] ) ) {
			return;
		}

		check_admin_referer( 'uams_mra_review' );

		UAMS_MRA_Archive::discard();

		$this->notice( __( 'Archive discarded.', 'uams-mra' ) );

		wp_safe_redirect( self::url( array( 'action' => 'review' ) ) );
		exit;
	}

	/**
	 * Begin building the archive.
	 */
	public function ajax_archive_start() {
		check_ajax_referer( 'uams_mra_archive', 'nonce' );

		if ( ! current_user_can( UAMS_MRA_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'uams-mra' ) ), 403 );
		}

		$job = UAMS_MRA_Archive::start( UAMS_MRA_Selection::get() );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}

		wp_send_json_success( $this->archive_payload( $job ) );
	}

	/**
	 * Add the next batch to the archive.
	 */
	public function ajax_archive_step() {
		check_ajax_referer( 'uams_mra_archive', 'nonce' );

		if ( ! current_user_can( UAMS_MRA_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'uams-mra' ) ), 403 );
		}

		$job = UAMS_MRA_Archive::step();

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}

		wp_send_json_success( $this->archive_payload( $job ) );
	}

	/**
	 * Shape a job for the browser.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	protected function archive_payload( array $job ) {
		$total = max( 1, count( $job['ids'] ) );
		$parts = array();

		foreach ( (array) $job['parts'] as $part ) {
			if ( empty( $part['files'] ) ) {
				continue;
			}

			$parts[] = array(
				'part'  => (int) $part['part'],
				'name'  => $part['name'],
				'size'  => size_format( (int) $part['bytes'], 1 ),
				'files' => (int) $part['files'],
				'url'   => wp_nonce_url(
					admin_url( 'admin-post.php?action=uams_mra_archive_download&part=' . (int) $part['part'] ),
					'uams_mra_archive_download'
				),
			);
		}

		return array(
			'done'     => ! empty( $job['done'] ),
			'percent'  => min( 100, (int) round( ( $job['cursor'] / $total ) * 100 ) ),
			'files'    => (int) $job['files'],
			'bytes'    => size_format( (int) $job['bytes'], 1 ),
			'skipped'  => count( (array) $job['skipped'] ),
			'parts'    => $parts,
			'total'    => count( $job['ids'] ),
			'progress' => sprintf(
				/* translators: 1: files archived, 2: total files, 3: disk space. */
				__( '%1$s of %2$s files · %3$s', 'uams-mra' ),
				number_format_i18n( $job['files'] ),
				number_format_i18n( count( $job['ids'] ) ),
				size_format( (int) $job['bytes'], 1 )
			),
		);
	}

	/**
	 * Stream one archive part to the browser.
	 */
	public function download_archive() {
		if ( ! current_user_can( UAMS_MRA_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to download this.', 'uams-mra' ) );
		}

		check_admin_referer( 'uams_mra_archive_download' );

		$job  = UAMS_MRA_Archive::get_job();
		$part = isset( $_GET['part'] ) ? absint( wp_unslash( $_GET['part'] ) ) : 0;

		if ( ! $job || ! $part || empty( $job['parts'][ $part ] ) ) {
			wp_die( esc_html__( 'That archive is no longer available. Build it again.', 'uams-mra' ) );
		}

		$path = $job['dir'] . '/' . $job['parts'][ $part ]['name'];

		// The job folder is the only place this may ever read from.
		if ( 0 !== strpos( $path, UAMS_MRA_Archive::base_dir() ) || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'That archive file is missing. Build it again.', 'uams-mra' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename=' . basename( $path ) );
		header( 'Content-Length: ' . filesize( $path ) );

		// Chunked so a large part does not have to fit in memory.
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( $handle ) {
			while ( ! feof( $handle ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo fread( $handle, 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				flush();
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		exit;
	}

	/**
	 * The review screen: one list, then archive and delete.
	 */
	protected function render_review() {
		$ids    = UAMS_MRA_Selection::get();
		$rows   = UAMS_MRA_Selection::rows( $ids );
		$totals = UAMS_MRA_Selection::totals( $rows );
		$job    = UAMS_MRA_Archive::get_job();
		$tiers  = UAMS_MRA_Triage::tiers();

		if ( $rows ) {
			update_meta_cache( 'post', wp_list_pluck( $rows, 'attachment_id' ) );
		}
		?>
		<div class="wrap uams-mra-wrap">
			<h1><?php esc_html_e( 'Review selection', 'uams-mra' ); ?></h1>

			<?php settings_errors( 'uams-mra' ); ?>

			<p class="uams-mra-intro">
				<a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Back to results', 'uams-mra' ); ?></a>
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'Nothing is selected. Go back and choose a filter or paste a list.', 'uams-mra' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<div class="uams-mra-stats">
				<div class="uams-mra-stat">
					<span class="uams-mra-stat-label"><?php esc_html_e( 'Files selected', 'uams-mra' ); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html( number_format_i18n( $totals['count'] ) ); ?></strong>
				</div>
				<div class="uams-mra-stat">
					<span class="uams-mra-stat-label"><?php esc_html_e( 'Disk space', 'uams-mra' ); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html( size_format( $totals['bytes'], 1 ) ); ?></strong>
				</div>
				<div class="uams-mra-stat">
					<span class="uams-mra-stat-label"><?php esc_html_e( 'Still referenced', 'uams-mra' ); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html( number_format_i18n( $totals['referenced'] ) ); ?></strong>
					<span class="uams-mra-muted"><?php esc_html_e( 'skipped unless overridden', 'uams-mra' ); ?></span>
				</div>
				<div class="uams-mra-stat">
					<span class="uams-mra-stat-label"><?php esc_html_e( 'Attached to a post', 'uams-mra' ); ?></span>
					<strong class="uams-mra-stat-value"><?php echo esc_html( number_format_i18n( $totals['attached'] ) ); ?></strong>
				</div>
			</div>

			<?php if ( $totals['missing'] ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						/* translators: %s: number of files. */
						echo esc_html( sprintf( __( '%s of these have no file on disk. They will be left out of the archive but can still be deleted, which clears the orphaned database row.', 'uams-mra' ), number_format_i18n( $totals['missing'] ) ) );
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! UAMS_MRA_Archive::available() ) : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'This server has no ZipArchive support, so the plugin cannot build an archive. Take your own backup before deleting anything here.', 'uams-mra' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="uams-mra-panel">
				<div class="uams-mra-panel-head">
					<div>
						<h2><?php esc_html_e( 'Archive', 'uams-mra' ); ?></h2>
						<p class="uams-mra-muted">
							<?php
							printf(
								/* translators: %s: size of one part. */
								esc_html__( 'Original files plus a manifest listing every ID, title, and note. Split into parts of about %s so a failed download costs one part, not the whole set. Generated image sizes are left out; WordPress rebuilds those.', 'uams-mra' ),
								esc_html( size_format( UAMS_MRA_Archive::part_max_bytes() ) )
							);
							?>
						</p>
					</div>
					<?php if ( UAMS_MRA_Archive::available() ) : ?>
						<button type="button" class="button button-primary" id="uams-mra-archive">
							<?php echo $job ? esc_html__( 'Rebuild archive', 'uams-mra' ) : esc_html__( 'Build archive', 'uams-mra' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<div id="uams-mra-archive-progress" class="uams-mra-progress" hidden>
					<div class="uams-mra-bar"><span></span></div>
					<p id="uams-mra-archive-text" role="status" aria-live="polite"></p>
				</div>

				<div id="uams-mra-archive-parts" class="uams-mra-parts">
					<?php
					if ( $job && ! empty( $job['parts'] ) ) {
						foreach ( $job['parts'] as $part ) {
							if ( empty( $part['files'] ) ) {
								continue;
							}
							$href = wp_nonce_url(
								admin_url( 'admin-post.php?action=uams_mra_archive_download&part=' . (int) $part['part'] ),
								'uams_mra_archive_download'
							);
							?>
							<a class="button" href="<?php echo esc_url( $href ); ?>">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: part number, 2: file count, 3: size. */
										__( 'Download part %1$d (%2$s files, %3$s)', 'uams-mra' ),
										(int) $part['part'],
										number_format_i18n( (int) $part['files'] ),
										size_format( (int) $part['bytes'], 1 )
									)
								);
								?>
							</a>
							<?php
						}
					}
					?>
				</div>

				<?php if ( $job ) : ?>
					<form method="post" class="uams-mra-discard">
						<?php wp_nonce_field( 'uams_mra_review' ); ?>
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
						<input type="hidden" name="action" value="review" />
						<button type="submit" name="uams_mra_discard_archive" value="1" class="button-link">
							<?php esc_html_e( 'Discard this archive', 'uams-mra' ); ?>
						</button>
						<span class="uams-mra-muted">
							<?php esc_html_e( 'Archives are deleted automatically after a day.', 'uams-mra' ); ?>
						</span>
					</form>
				<?php endif; ?>
			</div>

			<table class="wp-list-table widefat fixed striped uams-mra-review-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'File', 'uams-mra' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tier', 'uams-mra' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Decision', 'uams-mra' ); ?></th>
						<th scope="col"><?php esc_html_e( 'References', 'uams-mra' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Disk space', 'uams-mra' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $rows as $row ) :
						$id   = (int) $row['attachment_id'];
						$tier = UAMS_MRA_Triage::tier( $row );
						$mark = UAMS_MRA_Triage::get( $id );
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( '' !== $row['title'] ? $row['title'] : __( '(no title)', 'uams-mra' ) ); ?></a></strong>
								<div class="uams-mra-file"><?php echo esc_html( $row['file'] ); ?></div>
								<div class="uams-mra-meta">ID <?php echo (int) $id; ?> &middot; <?php echo esc_html( $row['mime'] ); ?></div>
							</td>
							<td>
								<span class="uams-mra-tier uams-mra-tier-<?php echo esc_attr( $tier ); ?>"><?php echo esc_html( $tiers[ $tier ]['label'] ); ?></span>
							</td>
							<td>
								<?php if ( $mark['d'] ) : ?>
									<?php $labels = UAMS_MRA_Triage::dispositions(); ?>
									<strong><?php echo esc_html( $labels[ $mark['d'] ] ); ?></strong>
									<?php if ( $mark['n'] ) : ?>
										<div class="uams-mra-meta"><?php echo esc_html( $mark['n'] ); ?></div>
									<?php endif; ?>
								<?php else : ?>
									<span class="uams-mra-muted"><?php esc_html_e( 'Undecided', 'uams-mra' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( (int) $row['ref_count'] > 0 ) : ?>
									<span class="uams-mra-badge uams-mra-badge-ok"><?php echo esc_html( number_format_i18n( $row['ref_count'] ) ); ?></span>
								<?php else : ?>
									<span class="uams-mra-muted"><?php esc_html_e( 'None', 'uams-mra' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['bytes'] ? size_format( $row['bytes'], 1 ) : __( 'File missing', 'uams-mra' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" class="uams-mra-danger">
				<?php wp_nonce_field( 'uams_mra_review' ); ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="action" value="review" />

				<p class="uams-mra-force">
					<label>
						<input type="checkbox" name="uams_mra_force" value="1" />
						<?php esc_html_e( 'Also delete the files that still have references.', 'uams-mra' ); ?>
					</label>
				</p>

				<button type="submit" name="uams_mra_delete_selection" value="1" class="button button-secondary" id="uams-mra-delete-selection">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of files, 2: disk space. */
							__( 'Delete these %1$s files permanently (%2$s)', 'uams-mra' ),
							number_format_i18n( $totals['count'] ),
							size_format( $totals['bytes'], 1 )
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
	public function ajax_triage() {
		check_ajax_referer( 'uams_mra_triage', 'nonce' );

		if ( ! current_user_can( UAMS_MRA_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this.', 'uams-mra' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'That file no longer exists.', 'uams-mra' ) ), 404 );
		}

		$disposition = isset( $_POST['d'] ) ? sanitize_key( wp_unslash( $_POST['d'] ) ) : '';
		$note        = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

		$mark   = UAMS_MRA_Triage::set( $id, $disposition, $note );
		$counts = UAMS_MRA_Triage::counts();

		wp_send_json_success(
			array(
				'mark'        => $mark,
				'counts'      => $counts,
				'deleteLabel' => sprintf(
					/* translators: 1: number of files, 2: disk space. */
					__( 'Delete the %1$s files marked Delete (%2$s)', 'uams-mra' ),
					number_format_i18n( $counts['disp']['delete'] ),
					size_format( $counts['delete_bytes'], 1 )
				),
			)
		);
	}

	/**
	 * Begin a scan.
	 */
	public function ajax_start() {
		check_ajax_referer( 'uams_mra_scan', 'nonce' );

		if ( ! current_user_can( UAMS_MRA_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to run a scan.', 'uams-mra' ) ), 403 );
		}

		wp_send_json_success( $this->progress( UAMS_MRA_Scanner::start() ) );
	}

	/**
	 * Advance the scan by one batch.
	 */
	public function ajax_step() {
		check_ajax_referer( 'uams_mra_scan', 'nonce' );

		if ( ! current_user_can( UAMS_MRA_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to run a scan.', 'uams-mra' ) ), 403 );
		}

		wp_send_json_success( $this->progress( UAMS_MRA_Scanner::step() ) );
	}

	/**
	 * Shape state for the progress UI.
	 *
	 * @param array $state Scan state.
	 * @return array
	 */
	protected function progress( $state ) {
		$done = ( 'done' === ( $state['step'] ?? 'done' ) );

		return array(
			'step'      => $state['step'] ?? 'done',
			'message'   => $state['message'] ?? '',
			'processed' => (int) ( $state['processed'] ?? 0 ),
			'done'      => $done,
			'summary'   => $done ? UAMS_MRA_Store::summary() : null,
		);
	}

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------ */

	/**
	 * Stream results as CSV.
	 */
	public function export_csv() {
		if ( ! current_user_can( UAMS_MRA_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'uams-mra' ) );
		}

		check_admin_referer( 'uams_mra_export' );

		global $wpdb;

		$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'orphaned';
		$where = array(
			'orphaned'     => 'ref_count = 0 AND is_attached = 0',
			'unreferenced' => 'ref_count = 0',
			'unattached'   => 'is_attached = 0',
			'weak'         => 'ref_count > 0 AND ref_count_strict = 0',
			'referenced'   => 'ref_count > 0',
			'all'          => '1=1',
		);
		$where = $where[ $view ] ?? $where['orphaned'];

		$filename = 'uams-media-audit-' . sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . $view . '-' . gmdate( 'Ymd' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
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
				'tier',
				'decision',
				'note',
			),
			',',
			'"',
			''
		);

		$table  = UAMS_MRA_Store::results_table();
		$offset = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY bytes DESC LIMIT 500 OFFSET %d", $offset ),
				ARRAY_A
			);

			update_meta_cache( 'post', wp_list_pluck( $rows, 'attachment_id' ) );

			foreach ( $rows as $row ) {
				$mark   = UAMS_MRA_Triage::get( (int) $row['attachment_id'] );
				$refs   = UAMS_MRA_Store::get_refs( (int) $row['attachment_id'], 10 );
				$labels = array();
				foreach ( $refs as $ref ) {
					$labels[] = $ref->source_type . ' #' . $ref->source_id . ' ' . $ref->source_label;
				}

				fputcsv(
					$out,
					array(
						$row['attachment_id'],
						$row['title'],
						$row['file'],
						wp_get_attachment_url( (int) $row['attachment_id'] ),
						$row['mime'],
						$row['post_date'],
						$row['bytes'],
						size_format( (int) $row['bytes'], 1 ),
						$row['parent_id'] ? $row['parent_id'] . ' ' . get_the_title( (int) $row['parent_id'] ) : '',
						$row['parent_status'],
						$row['ref_count'],
						$row['ref_count_strict'],
						implode( ' | ', $labels ),
						UAMS_MRA_Triage::tier( $row ),
						$mark['d'],
						$mark['n'],
					),
					',',
					'"',
					''
				);
			}

			$offset += 500;
		} while ( count( $rows ) === 500 );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------ */

	/**
	 * Output the admin page.
	 */
	public function render() {
		$this->flash_notice();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'review' === $action ) {
			$this->render_review();
			return;
		}

		$state    = UAMS_MRA_Scanner::get_state();
		$summary  = UAMS_MRA_Store::summary();
		$settings = uams_mra_settings();
		$scanned  = ! empty( $state['finished'] );
		$view     = isset( $_REQUEST['view'] ) ? sanitize_key( wp_unslash( $_REQUEST['view'] ) ) : 'orphaned'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$triage = $scanned ? UAMS_MRA_Triage::counts( UAMS_MRA_List_Table::where_for_view( $view ) ) : array();

		$table = new UAMS_MRA_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap uams-mra-wrap">
			<h1><?php esc_html_e( 'Media Audit', 'uams-mra' ); ?></h1>

			<?php settings_errors( 'uams-mra' ); ?>

			<p class="uams-mra-intro">
				<?php esc_html_e( 'Scans every post, custom field, theme setting, widget, and menu on this site, then reports which media files nothing points to. Nothing is deleted until you choose to delete it.', 'uams-mra' ); ?>
			</p>

			<div class="uams-mra-panel">
				<div class="uams-mra-panel-head">
					<div>
						<h2><?php esc_html_e( 'Scan', 'uams-mra' ); ?></h2>
						<p class="uams-mra-muted">
							<?php
							if ( $scanned ) {
								printf(
									/* translators: %s: human readable time difference. */
									esc_html__( 'Last scanned %s ago.', 'uams-mra' ),
									esc_html( human_time_diff( (int) $state['finished'] ) )
								);
							} else {
								esc_html_e( 'No scan has finished yet.', 'uams-mra' );
							}
							?>
						</p>
					</div>
					<button type="button" class="button button-primary" id="uams-mra-scan">
						<?php echo $scanned ? esc_html__( 'Scan again', 'uams-mra' ) : esc_html__( 'Start scan', 'uams-mra' ); ?>
					</button>
				</div>

				<div id="uams-mra-progress" class="uams-mra-progress" hidden>
					<div class="uams-mra-bar"><span></span></div>
					<p id="uams-mra-progress-text" role="status" aria-live="polite"></p>
				</div>
			</div>

			<?php if ( $scanned && ! empty( $summary['total'] ) ) : ?>
				<div class="uams-mra-stats">
					<?php
					$cards = array(
						array( __( 'Media files', 'uams-mra' ), number_format_i18n( $summary['total'] ), size_format( $summary['total_bytes'], 1 ) ),
						array( __( 'Not attached', 'uams-mra' ), number_format_i18n( $summary['unattached'] ), '' ),
						array( __( 'Not referenced', 'uams-mra' ), number_format_i18n( $summary['unreferenced'] ), size_format( $summary['unreferenced_bytes'], 1 ) ),
						array( __( 'Safe to review first', 'uams-mra' ), number_format_i18n( $summary['orphaned'] ), size_format( $summary['orphaned_bytes'], 1 ) ),
					);
					foreach ( $cards as $card ) :
						?>
						<div class="uams-mra-stat">
							<span class="uams-mra-stat-label"><?php echo esc_html( $card[0] ); ?></span>
							<strong class="uams-mra-stat-value"><?php echo esc_html( $card[1] ); ?></strong>
							<?php if ( $card[2] ) : ?>
								<span class="uams-mra-muted"><?php echo esc_html( $card[2] ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $scanned && ! empty( $summary['total'] ) && ! empty( $triage ) ) : ?>
				<h2 class="uams-mra-settings-heading"><?php esc_html_e( 'Triage', 'uams-mra' ); ?></h2>
				<p class="uams-mra-muted uams-mra-tier-intro">
					<?php esc_html_e( 'Every file gets a tier from the scan and a decision from you. Decisions are stored on the file itself, so they survive the next scan and everyone with access sees the same marks.', 'uams-mra' ); ?>
					<br />
					<?php esc_html_e( 'These counts cover the filter selected below, not the whole library.', 'uams-mra' ); ?>
				</p>

				<div class="uams-mra-tiers">
					<?php
					$current_tier = UAMS_MRA_List_Table::current_tier();
					foreach ( UAMS_MRA_Triage::tiers() as $key => $tier ) :
						$url = add_query_arg(
							array(
								'view' => $view,
								'tier' => ( $current_tier === $key ) ? false : $key,
							),
							menu_page_url( self::SLUG, false )
						);
						?>
						<a class="uams-mra-tier-card uams-mra-tier-<?php echo esc_attr( $key ); ?><?php echo $current_tier === $key ? ' is-current' : ''; ?>"
							href="<?php echo esc_url( $url ); ?>">
							<span class="uams-mra-tier-name"><?php echo esc_html( $tier['label'] ); ?></span>
							<strong class="uams-mra-stat-value"><?php echo esc_html( number_format_i18n( $triage['tier'][ $key ] ) ); ?></strong>
							<span class="uams-mra-muted"><?php echo esc_html( $tier['desc'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>

				<form method="get" class="uams-mra-filters">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
					<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />

					<label for="uams-mra-filter-tier" class="screen-reader-text"><?php esc_html_e( 'Filter by tier', 'uams-mra' ); ?></label>
					<select name="tier" id="uams-mra-filter-tier">
						<option value=""><?php esc_html_e( 'Every tier', 'uams-mra' ); ?></option>
						<?php foreach ( UAMS_MRA_Triage::tiers() as $key => $tier ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( UAMS_MRA_List_Table::current_tier(), $key ); ?>>
								<?php echo esc_html( $tier['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<label for="uams-mra-filter-disp" class="screen-reader-text"><?php esc_html_e( 'Filter by decision', 'uams-mra' ); ?></label>
					<select name="disp" id="uams-mra-filter-disp">
						<option value=""><?php esc_html_e( 'Any decision', 'uams-mra' ); ?></option>
						<option value="none" <?php selected( UAMS_MRA_List_Table::current_disposition(), 'none' ); ?>>
							<?php
							/* translators: %s: number of files. */
							echo esc_html( sprintf( __( 'Undecided (%s)', 'uams-mra' ), number_format_i18n( $triage['none'] ) ) );
							?>
						</option>
						<?php foreach ( UAMS_MRA_Triage::dispositions() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( UAMS_MRA_List_Table::current_disposition(), $key ); ?>>
								<?php echo esc_html( $label . ' (' . number_format_i18n( $triage['disp'][ $key ] ) . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<?php submit_button( __( 'Filter', 'uams-mra' ), 'secondary', '', false ); ?>

					<?php if ( UAMS_MRA_List_Table::current_tier() || UAMS_MRA_List_Table::current_disposition() ) : ?>
						<a class="button-link" href="<?php echo esc_url( add_query_arg( 'view', $view, menu_page_url( self::SLUG, false ) ) ); ?>">
							<?php esc_html_e( 'Clear filters', 'uams-mra' ); ?>
						</a>
					<?php endif; ?>
				</form>
			<?php endif; ?>

			<form method="post" id="uams-mra-results-form">
				<?php
				wp_nonce_field( 'bulk-media_items' );
				echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
				echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '" />';
				$table->views();
				$table->search_box( __( 'Search media', 'uams-mra' ), 'uams-mra-search' );
				$table->display();
				?>

				<p class="uams-mra-force">
					<label>
						<input type="checkbox" name="uams_mra_force" value="1" />
						<?php esc_html_e( 'Also delete files that still have references. Leave this off unless you have checked each one.', 'uams-mra' ); ?>
					</label>
				</p>

				<?php if ( ! empty( $triage ) ) : ?>
					<p class="uams-mra-marked"<?php echo $triage['disp']['delete'] > 0 ? '' : ' hidden'; ?>>
						<button type="submit" name="uams_mra_delete_marked" value="1" id="uams-mra-delete-marked" class="button button-secondary">
							<?php
							/* translators: 1: number of files, 2: disk space. */
							echo esc_html( sprintf( __( 'Delete the %1$s files marked Delete (%2$s)', 'uams-mra' ), number_format_i18n( $triage['disp']['delete'] ), size_format( $triage['delete_bytes'], 1 ) ) );
							?>
						</button>
						<span class="uams-mra-muted">
							<?php esc_html_e( 'Acts on every file marked Delete, not on the checkboxes above.', 'uams-mra' ); ?>
						</span>
					</p>
				<?php endif; ?>
			</form>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=uams_mra_export&view=' . $view ), 'uams_mra_export' ) ); ?>">
					<?php esc_html_e( 'Download this list as CSV', 'uams-mra' ); ?>
				</a>
			</p>

			<?php if ( $scanned && ! empty( $summary['total'] ) ) : ?>
				<h2 class="uams-mra-settings-heading"><?php esc_html_e( 'Archive and delete', 'uams-mra' ); ?></h2>
				<p class="uams-mra-muted uams-mra-tier-intro">
					<?php esc_html_e( 'Gather a set of files, look at it as one list, then download it as a zip and delete it. The zip is what makes the deletion reversible, so build it first.', 'uams-mra' ); ?>
				</p>

				<div class="uams-mra-select">
					<form method="post" class="uams-mra-select-card">
						<?php wp_nonce_field( 'uams_mra_select' ); ?>
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
						<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
						<input type="hidden" name="tier" value="<?php echo esc_attr( UAMS_MRA_List_Table::current_tier() ); ?>" />
						<input type="hidden" name="disp" value="<?php echo esc_attr( UAMS_MRA_List_Table::current_disposition() ); ?>" />
						<?php
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$search_term = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
						?>
						<input type="hidden" name="s" value="<?php echo esc_attr( $search_term ); ?>" />

						<h3><?php esc_html_e( 'Everything matching this filter', 'uams-mra' ); ?></h3>
						<p class="uams-mra-muted">
							<?php
							/* translators: %s: number of files. */
							echo esc_html( sprintf( __( 'All %s files currently filtered, not just the ones on this page.', 'uams-mra' ), number_format_i18n( $table->get_pagination_arg( 'total_items' ) ) ) );
							?>
						</p>
						<button type="submit" name="uams_mra_select_filter" value="1" class="button button-primary">
							<?php esc_html_e( 'Review these files', 'uams-mra' ); ?>
						</button>
					</form>

					<form method="post" class="uams-mra-select-card">
						<?php wp_nonce_field( 'uams_mra_select' ); ?>
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />

						<h3><?php esc_html_e( 'A list from somewhere else', 'uams-mra' ); ?></h3>
						<p class="uams-mra-muted">
							<?php esc_html_e( 'Paste attachment IDs, filenames, or URLs from a content audit — one per line or comma separated. Anything that matches nothing is reported rather than skipped quietly.', 'uams-mra' ); ?>
						</p>
						<textarea name="pasted" rows="4" class="large-text code" placeholder="1215&#10;stroke-program-issue-4.pdf&#10;https://example.org/wp-content/uploads/2024/01/photo.jpg"></textarea>
						<button type="submit" name="uams_mra_select_text" value="1" class="button">
							<?php esc_html_e( 'Review this list', 'uams-mra' ); ?>
						</button>
					</form>
				</div>
			<?php endif; ?>

			<h2 class="uams-mra-settings-heading"><?php esc_html_e( 'Scan settings', 'uams-mra' ); ?></h2>

			<form method="post">
				<?php wp_nonce_field( 'uams_mra_settings' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Where to look', 'uams-mra' ); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="include_numeric_meta" value="1" <?php checked( $settings['include_numeric_meta'] ); ?> /> <?php esc_html_e( 'Count a custom field that stores only an ID as a reference. Keep this on for ACF and page builder sites.', 'uams-mra' ); ?></label><br />
									<label><input type="checkbox" name="include_revisions" value="1" <?php checked( $settings['include_revisions'] ); ?> /> <?php esc_html_e( 'Count references found in post revisions.', 'uams-mra' ); ?></label><br />
									<label><input type="checkbox" name="include_termmeta" value="1" <?php checked( $settings['include_termmeta'] ); ?> /> <?php esc_html_e( 'Category, tag, and taxonomy fields.', 'uams-mra' ); ?></label><br />
									<label><input type="checkbox" name="include_usermeta" value="1" <?php checked( $settings['include_usermeta'] ); ?> /> <?php esc_html_e( 'User profile fields.', 'uams-mra' ); ?></label><br />
									<label><input type="checkbox" name="include_comments" value="1" <?php checked( $settings['include_comments'] ); ?> /> <?php esc_html_e( 'Comment fields.', 'uams-mra' ); ?></label>
									<?php if ( is_multisite() ) : ?>
										<br /><label><input type="checkbox" name="include_network" value="1" <?php checked( $settings['include_network'] ); ?> /> <?php esc_html_e( 'Other sites in this network. Slower, but it catches images this site hosts and another site displays.', 'uams-mra' ); ?></label>
									<?php endif; ?>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="extra_paths"><?php esc_html_e( 'Extra upload paths', 'uams-mra' ); ?></label></th>
							<td>
								<textarea id="extra_paths" name="extra_paths" rows="3" class="large-text code"><?php echo esc_textarea( $settings['extra_paths'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One path per line. Add the path a CDN or offload plugin rewrites media to, for example media/library. The default uploads path is always checked.', 'uams-mra' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Batch sizes', 'uams-mra' ); ?></th>
							<td>
								<label><?php esc_html_e( 'Posts', 'uams-mra' ); ?> <input type="number" name="batch_posts" value="<?php echo esc_attr( $settings['batch_posts'] ); ?>" min="10" max="10000" class="small-text" /></label>
								<label><?php esc_html_e( 'Meta rows', 'uams-mra' ); ?> <input type="number" name="batch_meta" value="<?php echo esc_attr( $settings['batch_meta'] ); ?>" min="10" max="10000" class="small-text" /></label>
								<label><?php esc_html_e( 'Index', 'uams-mra' ); ?> <input type="number" name="batch_index" value="<?php echo esc_attr( $settings['batch_index'] ); ?>" min="10" max="10000" class="small-text" /></label>
								<label><?php esc_html_e( 'Results', 'uams-mra' ); ?> <input type="number" name="batch_finalize" value="<?php echo esc_attr( $settings['batch_finalize'] ); ?>" min="10" max="10000" class="small-text" /></label>
								<p class="description"><?php esc_html_e( 'Lower these if the scan times out on shared hosting.', 'uams-mra' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Save settings', 'uams-mra' ), 'secondary', 'uams_mra_save_settings' ); ?>
			</form>
		</div>
		<?php
	}
}
