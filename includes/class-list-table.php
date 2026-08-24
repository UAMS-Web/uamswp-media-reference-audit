<?php
/**
 * Results table.
 *
 * @package UAMS_Media_Reference_Audit
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders scan results with filters and bulk delete.
 */
class UAMS_MRA_List_Table extends WP_List_Table {

	/**
	 * Counts used for the view links.
	 *
	 * @var array
	 */
	protected $summary = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'media_item',
				'plural'   => 'media_items',
				'ajax'     => false,
			)
		);

		$this->summary = UAMS_MRA_Store::summary();
	}

	/**
	 * Current filter.
	 *
	 * @return string
	 */
	protected function current_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_REQUEST['view'] ) ? sanitize_key( wp_unslash( $_REQUEST['view'] ) ) : 'orphaned';
		$allowed = array( 'all', 'orphaned', 'unreferenced', 'unattached', 'weak', 'referenced' );
		return in_array( $view, $allowed, true ) ? $view : 'orphaned';
	}

	/**
	 * WHERE fragment for the current filter.
	 *
	 * @return string
	 */
	protected function view_where() {
		switch ( $this->current_view() ) {
			case 'orphaned':
				return 'ref_count = 0 AND is_attached = 0';
			case 'unreferenced':
				return 'ref_count = 0';
			case 'unattached':
				return 'is_attached = 0';
			case 'weak':
				return 'ref_count > 0 AND ref_count_strict = 0';
			case 'referenced':
				return 'ref_count > 0';
			default:
				return '1=1';
		}
	}

	/**
	 * View links.
	 *
	 * @return array
	 */
	protected function get_views() {
		$s       = $this->summary;
		$current = $this->current_view();

		$views = array(
			'orphaned'     => array( __( 'Unattached and unreferenced', 'uams-mra' ), $s['orphaned'] ?? 0 ),
			'unreferenced' => array( __( 'Not referenced anywhere', 'uams-mra' ), $s['unreferenced'] ?? 0 ),
			'unattached'   => array( __( 'Not attached to a post', 'uams-mra' ), $s['unattached'] ?? 0 ),
			'weak'         => array( __( 'Referenced by ID only', 'uams-mra' ), $s['weak_only'] ?? 0 ),
			'referenced'   => array( __( 'In use', 'uams-mra' ), ( $s['total'] ?? 0 ) - ( $s['unreferenced'] ?? 0 ) ),
			'all'          => array( __( 'All media', 'uams-mra' ), $s['total'] ?? 0 ),
		);

		$out = array();

		foreach ( $views as $key => $view ) {
			$url = add_query_arg(
				array(
					'page' => 'uams-mra',
					'view' => $key,
				),
				admin_url( 'upload.php' )
			);

			$out[ $key ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $view[0] ),
				esc_html( number_format_i18n( $view[1] ) )
			);
		}

		return $out;
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'thumb'    => __( 'Preview', 'uams-mra' ),
			'title'    => __( 'File', 'uams-mra' ),
			'status'   => __( 'Status', 'uams-mra' ),
			'attached' => __( 'Attached to', 'uams-mra' ),
			'refs'     => __( 'Referenced in', 'uams-mra' ),
			'bytes'    => __( 'Disk space', 'uams-mra' ),
			'date'     => __( 'Uploaded', 'uams-mra' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'title' => array( 'title', false ),
			'bytes' => array( 'bytes', true ),
			'date'  => array( 'post_date', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array(
			'uams_mra_delete' => __( 'Delete permanently', 'uams-mra' ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		global $wpdb;

		$per_page = 50;
		$paged    = max( 1, (int) $this->get_pagenum() );
		$offset   = ( $paged - 1 ) * $per_page;
		$table    = UAMS_MRA_Store::results_table();

		$where  = $this->view_where();
		$params = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_REQUEST['s'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : '';
		if ( '' !== $search ) {
			$where   .= ' AND (title LIKE %s OR file LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$orderby_allowed = array( 'title', 'bytes', 'post_date', 'attachment_id' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'bytes';
		$orderby = in_array( $orderby, $orderby_allowed, true ) ? $orderby : 'bytes';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC';

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$sql       = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, attachment_id DESC LIMIT %d OFFSET %d";
		$sql_args  = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $sql_args ), ARRAY_A );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['attachment_id'] );
	}

	/**
	 * Thumbnail column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_thumb( $item ) {
		$id = (int) $item['attachment_id'];

		if ( wp_attachment_is_image( $id ) ) {
			$image = wp_get_attachment_image( $id, array( 60, 60 ), true, array( 'alt' => '' ) );
			if ( $image ) {
				return $image;
			}
		}

		return '<span class="dashicons dashicons-media-default" aria-hidden="true"></span>';
	}

	/**
	 * File column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_title( $item ) {
		$id    = (int) $item['attachment_id'];
		$title = '' !== $item['title'] ? $item['title'] : __( '(no title)', 'uams-mra' );

		$out  = '<strong><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( $title ) . '</a></strong>';
		$out .= '<div class="uams-mra-file">' . esc_html( $item['file'] ) . '</div>';
		$out .= '<div class="uams-mra-meta">ID ' . $id . ' &middot; ' . esc_html( $item['mime'] ) . '</div>';

		$actions = array(
			'edit' => '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html__( 'Edit', 'uams-mra' ) . '</a>',
			'view' => '<a href="' . esc_url( wp_get_attachment_url( $id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open file', 'uams-mra' ) . '</a>',
		);

		return $out . $this->row_actions( $actions );
	}

	/**
	 * Status column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_status( $item ) {
		$badges = array();

		if ( ! (int) $item['is_attached'] ) {
			$badges[] = '<span class="uams-mra-badge uams-mra-badge-warn">' . esc_html__( 'Unattached', 'uams-mra' ) . '</span>';
		}

		if ( 0 === (int) $item['ref_count'] ) {
			$badges[] = '<span class="uams-mra-badge uams-mra-badge-danger">' . esc_html__( 'No references', 'uams-mra' ) . '</span>';
		} elseif ( 0 === (int) $item['ref_count_strict'] ) {
			$badges[] = '<span class="uams-mra-badge uams-mra-badge-info">' . esc_html__( 'ID reference only', 'uams-mra' ) . '</span>';
		} else {
			$badges[] = '<span class="uams-mra-badge uams-mra-badge-ok">' . esc_html__( 'In use', 'uams-mra' ) . '</span>';
		}

		if ( 'missing' === $item['parent_status'] ) {
			$badges[] = '<span class="uams-mra-badge uams-mra-badge-warn">' . esc_html__( 'Parent deleted', 'uams-mra' ) . '</span>';
		} elseif ( 'trash' === $item['parent_status'] ) {
			$badges[] = '<span class="uams-mra-badge uams-mra-badge-warn">' . esc_html__( 'Parent in trash', 'uams-mra' ) . '</span>';
		}

		return implode( ' ', $badges );
	}

	/**
	 * Attached to column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_attached( $item ) {
		$parent = (int) $item['parent_id'];

		if ( ! $parent ) {
			return '<span class="uams-mra-muted">' . esc_html__( 'Nothing', 'uams-mra' ) . '</span>';
		}

		$title = get_the_title( $parent );
		$link  = get_edit_post_link( $parent );

		if ( ! $link ) {
			/* translators: %d: post ID. */
			return esc_html( sprintf( __( 'Post %d (deleted)', 'uams-mra' ), $parent ) );
		}

		return '<a href="' . esc_url( $link ) . '">' . esc_html( $title ? $title : '#' . $parent ) . '</a>';
	}

	/**
	 * References column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_refs( $item ) {
		$count = (int) $item['ref_count'];

		if ( ! $count ) {
			return '<span class="uams-mra-muted">' . esc_html__( 'None found', 'uams-mra' ) . '</span>';
		}

		$refs = UAMS_MRA_Store::get_refs( (int) $item['attachment_id'], 5 );
		$list = array();

		foreach ( $refs as $ref ) {
			$label = $ref->source_label;
			$link  = in_array( $ref->source_type, array( 'content', 'postmeta', 'revision', 'revision_meta' ), true )
				? get_edit_post_link( (int) $ref->source_id )
				: '';

			$weak = ( (int) $ref->confidence < 2 ) ? ' <span class="uams-mra-muted">(by ID)</span>' : '';

			$list[] = $link
				? '<li><a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>' . $weak . '</li>'
				: '<li>' . esc_html( $label ) . $weak . '</li>';
		}

		$out = '<ul class="uams-mra-refs">' . implode( '', $list ) . '</ul>';

		if ( $count > count( $refs ) ) {
			/* translators: %s: number of additional references. */
			$out .= '<span class="uams-mra-muted">' . esc_html( sprintf( __( 'and %s more', 'uams-mra' ), number_format_i18n( $count - count( $refs ) ) ) ) . '</span>';
		}

		return $out;
	}

	/**
	 * Disk space column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_bytes( $item ) {
		$bytes = (int) $item['bytes'];
		return $bytes ? esc_html( size_format( $bytes, 1 ) ) : '<span class="uams-mra-muted">' . esc_html__( 'File missing', 'uams-mra' ) . '</span>';
	}

	/**
	 * Date column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_date( $item ) {
		return esc_html( mysql2date( get_option( 'date_format' ), $item['post_date'] ) );
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		esc_html_e( 'No media matches this filter. Run a scan if you have not yet, or pick another filter above.', 'uams-mra' );
	}
}
