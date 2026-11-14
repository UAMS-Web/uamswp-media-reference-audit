<?php

/**
 * Results table.
 */
defined('ABSPATH') || exit;

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders scan results with filters and bulk delete.
 */
class UAMS_MRA_List_Table extends WP_List_Table
{
    /**
     * Counts used for the view links.
     */
    protected array $summary;

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct(
            [
                'singular' => 'media_item',
                'plural' => 'media_items',
                'ajax' => false,
            ]
        );

        $this->summary = UAMS_MRA_Store::summary();
    }

    /**
     * Current filter.
     *
     * @return string
     */
    public static function current_view()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = isset($_REQUEST['view']) ? sanitize_key(wp_unslash($_REQUEST['view'])) : 'orphaned';
        $allowed = ['all', 'orphaned', 'unreferenced', 'unattached', 'weak', 'referenced'];

        return in_array($view, $allowed, true) ? $view : 'orphaned';
    }

    /**
     * WHERE fragment for the current filter.
     *
     * @return string
     */
    protected function view_where()
    {
        return self::where_for_view(self::current_view());
    }

    /**
     * WHERE fragment for one view.
     *
     * Public and static so the triage cards can count inside the view being
     * looked at, rather than reporting on the whole library.
     *
     * @param  string  $view  View key.
     * @return string
     */
    public static function where_for_view($view)
    {
        return match ($view) {
            'orphaned' => 'ref_count = 0 AND is_attached = 0',
            'unreferenced' => 'ref_count = 0',
            'unattached' => 'is_attached = 0',
            'weak' => 'ref_count > 0 AND ref_count_strict = 0',
            'referenced' => 'ref_count > 0',
            default => '1=1',
        };
    }

    /**
     * View links.
     *
     * @return array
     */
    protected function get_views()
    {
        $s = $this->summary;
        $current = static::current_view();

        $views = [
            'orphaned' => [__('Unattached and unreferenced', 'uams-mra'), $s['orphaned'] ?? 0],
            'unreferenced' => [__('Not referenced anywhere', 'uams-mra'), $s['unreferenced'] ?? 0],
            'unattached' => [__('Not attached to a post', 'uams-mra'), $s['unattached'] ?? 0],
            'weak' => [__('Referenced by ID only', 'uams-mra'), $s['weak_only'] ?? 0],
            'referenced' => [__('In use', 'uams-mra'), ($s['total'] ?? 0) - ($s['unreferenced'] ?? 0)],
            'all' => [__('All media', 'uams-mra'), $s['total'] ?? 0],
        ];

        // Built from the registered menu rather than a second copy of the slug, so
        // this cannot drift from UAMS_MRA_Admin::SLUG again. menu_page_url() also
        // resolves against the current site, which matters on multisite.
        $base = menu_page_url(UAMS_MRA_Admin::SLUG, false);

        if (! $base) {
            $base = add_query_arg('page', UAMS_MRA_Admin::SLUG, admin_url('upload.php'));
        }

        $out = [];

        foreach ($views as $key => $view) {
            $url = add_query_arg('view', $key, $base);

            $out[$key] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url($url),
                $current === $key ? ' class="current" aria-current="page"' : '',
                esc_html($view[0]),
                esc_html(number_format_i18n($view[1]))
            );
        }

        return $out;
    }

    /**
     * Columns.
     *
     * @return array
     */
    public function get_columns()
    {
        return [
            'cb' => '<input type="checkbox" />',
            'thumb' => __('Preview', 'uams-mra'),
            'title' => __('File', 'uams-mra'),
            'status' => __('Status', 'uams-mra'),
            'triage' => __('Decision', 'uams-mra'),
            'attached' => __('Attached to', 'uams-mra'),
            'refs' => __('Referenced in', 'uams-mra'),
            'bytes' => __('Disk space', 'uams-mra'),
            'date' => __('Uploaded', 'uams-mra'),
        ];
    }

    /**
     * Sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns()
    {
        return [
            'title' => ['title', false],
            'bytes' => ['bytes', true],
            'date' => ['post_date', true],
        ];
    }

    /**
     * Bulk actions.
     *
     * @return array
     */
    public function get_bulk_actions()
    {
        return [
            'uams_mra_delete' => __('Delete permanently', 'uams-mra'),
        ];
    }

    /**
     * Load rows.
     */
    public static function build_query()
    {
        global $wpdb;

        $where = self::where_for_view(self::current_view());
        $params = [];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset($_REQUEST['s']) ? trim(sanitize_text_field(wp_unslash($_REQUEST['s']))) : '';
        if ($search !== '') {
            $where .= ' AND (title LIKE %s OR file LIKE %s)';
            $like = '%'.$wpdb->esc_like($search).'%';
            $params[] = $like;
            $params[] = $like;
        }

        $tier = self::current_tier();
        if ($tier !== '') {
            $where .= ' AND ( '.UAMS_MRA_Triage::tier_where($tier).' )';
        }

        $disposition = self::current_disposition();
        if ($disposition !== '') {
            $where .= ' AND ( '.UAMS_MRA_Triage::disposition_where($disposition).' )';
        }

        return [
            'where' => $where,
            'params' => $params,
        ];
    }

    /**
     * Load rows.
     */
    public function prepare_items(): void
    {
        global $wpdb;

        $per_page = 50;
        $paged = max(1, (int) $this->get_pagenum());
        $offset = ($paged - 1) * $per_page;
        $table = UAMS_MRA_Store::results_table();

        $query = self::build_query();
        $where = $query['where'];
        $params = $query['params'];

        $orderby_allowed = ['title', 'bytes', 'post_date', 'attachment_id'];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'bytes';
        $orderby = in_array($orderby, $orderby_allowed, true) ? $orderby : 'bytes';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = isset($_REQUEST['order']) && strtolower(sanitize_key(wp_unslash($_REQUEST['order']))) === 'asc' ? 'ASC' : 'DESC';

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, attachment_id DESC LIMIT %d OFFSET %d";
        $sql_args = array_merge($params, [$per_page, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->items = $wpdb->get_results($wpdb->prepare($sql, $sql_args), ARRAY_A);

        $this->set_pagination_args(
            [
                'total_items' => $total,
                'per_page' => $per_page,
                'total_pages' => (int) ceil($total / $per_page),
            ]
        );

        // These IDs never pass through WP_Query, so nothing has primed their meta.
        // Without this the Decision column costs two queries a row.
        if ($this->items) {
            update_meta_cache('post', wp_list_pluck($this->items, 'attachment_id'));
        }

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'title'];
    }

    /**
     * Tier currently filtered to, or '' for every tier.
     *
     * @return string
     */
    public static function current_tier()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tier = isset($_REQUEST['tier']) ? sanitize_key(wp_unslash($_REQUEST['tier'])) : '';

        return array_key_exists($tier, UAMS_MRA_Triage::tiers()) ? $tier : '';
    }

    /**
     * Disposition currently filtered to, or '' for any.
     *
     * @return string
     */
    public static function current_disposition()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $value = isset($_REQUEST['disp']) ? sanitize_key(wp_unslash($_REQUEST['disp'])) : '';

        if ($value === 'none') {
            return 'none';
        }

        return array_key_exists($value, UAMS_MRA_Triage::dispositions()) ? $value : '';
    }

    /**
     * Checkbox column.
     *
     * @param  array  $item  Row.
     * @return string
     */
    public function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="ids[]" value="%d" />', (int) $item['attachment_id']);
    }

    /**
     * Thumbnail column.
     *
     * @param  array  $item  Row.
     * @return string
     */
    public function column_thumb($item)
    {
        $id = (int) $item['attachment_id'];

        if (wp_attachment_is_image($id)) {
            $image = wp_get_attachment_image($id, [60, 60], true, ['alt' => '']);
            if ($image) {
                return $image;
            }
        }

        return '<span class="dashicons dashicons-media-default" aria-hidden="true"></span>';
    }

    /**
     * File column.
     *
     * @param  array  $item  Row.
     * @return string
     */
    public function column_title($item)
    {
        $id = (int) $item['attachment_id'];
        $title = $item['title'] !== '' ? $item['title'] : __('(no title)', 'uams-mra');

        $out = '<strong><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html($title).'</a></strong>';
        $out .= '<div class="uams-mra-file">'.esc_html($item['file']).'</div>';
        $out .= '<div class="uams-mra-meta">ID '.$id.' &middot; '.esc_html($item['mime']).'</div>';

        $actions = [
            'edit' => '<a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html__('Edit', 'uams-mra').'</a>',
            'view' => '<a href="'.esc_url(wp_get_attachment_url($id)).'" target="_blank" rel="noopener">'.esc_html__('Open file', 'uams-mra').'</a>',
        ];

        return $out.$this->row_actions($actions);
    }

    /**
     * Status column.
     *
     * @param  array  $item  Row.
     * @return string
     */
    public function column_status($item)
    {
        $badges = [];

        if (! (int) $item['is_attached']) {
            $badges[] = '<span class="uams-mra-badge uams-mra-badge-warn">'.esc_html__('Unattached', 'uams-mra').'</span>';
        }

        if ((int) $item['ref_count'] === 0) {
            $badges[] = '<span class="uams-mra-badge uams-mra-badge-danger">'.esc_html__('No references', 'uams-mra').'</span>';
        } elseif ((int) $item['ref_count_strict'] === 0) {
            $badges[] = '<span class="uams-mra-badge uams-mra-badge-info">'.esc_html__('ID reference only', 'uams-mra').'</span>';
        } else {
            $badges[] = '<span class="uams-mra-badge uams-mra-badge-ok">'.esc_html__('In use', 'uams-mra').'</span>';
        }

        if ($item['parent_status'] === 'missing') {
            $badges[] = '<span class="uams-mra-badge uams-mra-badge-warn">'.esc_html__('Parent deleted', 'uams-mra').'</span>';
        } elseif ($item['parent_status'] === 'trash') {
            $badges[] = '<span class="uams-mra-badge uams-mra-badge-warn">'.esc_html__('Parent in trash', 'uams-mra').'</span>';
        }

        return implode(' ', $badges);
    }

    /**
     * Attached to column.
     *
     * @param  array  $item  Row.
     * @return string
     */
    public function column_attached($item)
    {
        $parent = (int) $item['parent_id'];

        if (! $parent) {
            return '<span class="uams-mra-muted">'.esc_html__('Nothing', 'uams-mra').'</span>';
        }

        $title = get_the_title($parent);
        $link = get_edit_post_link($parent);

        if (! $link) {
            /* translators: %d: post ID. */
            return esc_html(sprintf(__('Post %d (deleted)', 'uams-mra'), $parent));
        }

        return '<a href="'.esc_url($link).'">'.esc_html($title ?: '#'.$parent).'</a>';
    }

    /**
     * References column.
     *
     * @param  array  $item  Row.
     * @return string
     */
    public function column_refs($item)
    {
        $count = (int) $item['ref_count'];

        if (! $count) {
            return '<span class="uams-mra-muted">'.esc_html__('None found', 'uams-mra').'</span>';
        }

        $refs = UAMS_MRA_Store::get_refs((int) $item['attachment_id'], 5);
        $list = [];

        foreach ($refs as $ref) {
            $label = $ref->source_label;
            $link = in_array($ref->source_type, ['content', 'postmeta', 'revision', 'revision_meta'], true)
                ? get_edit_post_link((int) $ref->source_id)
                : '';

            $weak = ((int) $ref->confidence < 2) ? ' <span class="uams-mra-muted">(by ID)</span>' : '';

            $list[] = $link
                ? '<li><a href="'.esc_url($link).'">'.esc_html($label).'</a>'.$weak.'</li>'
                : '<li>'.esc_html($label).$weak.'</li>';
        }

        $out = '<ul class="uams-mra-refs">'.implode('', $list).'</ul>';

        if ($count > count($refs)) {
            /* translators: %s: number of additional references. */
            $out .= '<span class="uams-mra-muted">'.esc_html(sprintf(__('and %s more', 'uams-mra'), number_format_i18n($count - count($refs)))).'</span>';
        }

        return $out;
    }

    /**
     * Disk space column.
     *
     * @param  array  $item  Row.
     * @return string
     */
    public function column_bytes($item)
    {
        $bytes = (int) $item['bytes'];

        return $bytes ? esc_html(size_format($bytes, 1)) : '<span class="uams-mra-muted">'.esc_html__('File missing', 'uams-mra').'</span>';
    }

    /**
     * Date column.
     *
     * @param  array  $item  Row.
     * @return string
     */
    public function column_date($item)
    {
        return esc_html(mysql2date(get_option('date_format'), $item['post_date']));
    }

    /**
     * Empty state.
     */
    public function no_items(): void
    {
        esc_html_e('No media matches this filter. Run a scan if you have not yet, or pick another filter above.', 'uams-mra');
    }

    /**
     * Decision column: the tier the scan derived, and the mark a person set.
     *
     * @param  array  $item  Row.
     * @return string
     */
    public function column_triage($item)
    {
        $id = (int) $item['attachment_id'];
        $tier = UAMS_MRA_Triage::tier($item);
        $tiers = UAMS_MRA_Triage::tiers();
        $mark = UAMS_MRA_Triage::get($id);

        $out = '<div class="uams-mra-tri" data-id="'.$id.'" data-tier="'.esc_attr($tier).'"'
            .' data-d="'.esc_attr($mark['d']).'">';

        $out .= '<span class="uams-mra-tier uams-mra-tier-'.esc_attr($tier).'" title="'
            .esc_attr($tiers[$tier]['desc']).'">'
            .esc_html($tiers[$tier]['label']).'</span>';

        $out .= '<span class="uams-mra-tri-buttons">';
        foreach (UAMS_MRA_Triage::dispositions() as $key => $label) {
            $on = ($mark['d'] === $key);
            $out .= '<button type="button" class="uams-mra-tri-btn" data-d="'.esc_attr($key).'"'
                .' aria-pressed="'.($on ? 'true' : 'false').'">'
                .esc_html($label).'</button>';
        }

        $out .= '</span>';

        $out .= '<input type="text" class="uams-mra-tri-note" value="'.esc_attr($mark['n']).'"'
            .' maxlength="'.UAMS_MRA_Triage::NOTE_MAX.'"'
            .' placeholder="'.esc_attr__('Note, or who to ask…', 'uams-mra').'"'
            .' aria-label="'.esc_attr__('Note about this file', 'uams-mra').'"'
            .(($mark['d'] || $mark['n']) ? '' : ' hidden').' />';

        $out .= '<span class="uams-mra-tri-state" aria-live="polite"></span>';
        $out .= '</div>';

        return $out;
    }
}
