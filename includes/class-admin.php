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
				'nonce'   => wp_create_nonce( 'uams_mra_scan' ),
				'i18n'    => array(
					'confirmDelete' => __( 'This permanently deletes the selected files and every generated size. It cannot be undone. Continue?', 'uams-mra' ),
					'confirmScan'   => __( 'Start a new scan? This replaces the previous results.', 'uams-mra' ),
					'failed'        => __( 'The scan stopped with an error. Reload the page and try again.', 'uams-mra' ),
					'noSelection'   => __( 'Select at least one file first.', 'uams-mra' ),
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

		$force   = ! empty( $_POST['uams_mra_force'] );
		$deleted = 0;
		$skipped = 0;
		$bytes   = 0;

		global $wpdb;
		$table = UAMS_MRA_Store::results_table();

		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				continue;
			}

			$refs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ref_count FROM {$table} WHERE attachment_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( $refs > 0 && ! $force ) {
				++$skipped;
				continue;
			}

			$size = (int) $wpdb->get_var( $wpdb->prepare( "SELECT bytes FROM {$table} WHERE attachment_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( wp_delete_attachment( $id, true ) ) {
				++$deleted;
				$bytes += $size;
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->delete( $table, array( 'attachment_id' => $id ), array( '%d' ) );
			}
		}

		$message = sprintf(
			/* translators: 1: number of files, 2: disk space freed. */
			__( 'Deleted %1$s files and freed %2$s.', 'uams-mra' ),
			number_format_i18n( $deleted ),
			size_format( $bytes, 1 )
		);

		if ( $skipped ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of files. */
				__( 'Kept %s files that still have references. Tick the override box to delete those.', 'uams-mra' ),
				number_format_i18n( $skipped )
			);
		}

		add_settings_error( 'uams-mra', 'uams_mra_deleted', $message, $skipped ? 'warning' : 'success' );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

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
			)
		);

		$table  = UAMS_MRA_Store::results_table();
		$offset = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY bytes DESC LIMIT 500 OFFSET %d", $offset ),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
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
					)
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
		$state    = UAMS_MRA_Scanner::get_state();
		$summary  = UAMS_MRA_Store::summary();
		$settings = uams_mra_settings();
		$scanned  = ! empty( $state['finished'] );
		$view     = isset( $_REQUEST['view'] ) ? sanitize_key( wp_unslash( $_REQUEST['view'] ) ) : 'orphaned'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
			</form>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=uams_mra_export&view=' . $view ), 'uams_mra_export' ) ); ?>">
					<?php esc_html_e( 'Download this list as CSV', 'uams-mra' ); ?>
				</a>
			</p>

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
