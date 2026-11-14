<?php

/**
 * UAMS_MRA_List_Table: the views, the query, the paging and every column cell, with WordPress mocked.
 *
 * WP_List_Table is core's and is not loaded here, so a minimal stand-in records
 * what the class hands it. The store and the triage class are loaded for real;
 * the calls that would reach a database are redefined per test.
 *
 * @see includes/class-list-table.php
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\stubEscapeFunctions;
use function Brain\Monkey\Functions\stubTranslationFunctions;
use function Brain\Monkey\Functions\when;

mutates(UAMS_MRA_List_Table::class);

if (! class_exists('WP_List_Table')) {
    /**
     * What the class reads from core's table: its arguments, the paging calls and the row actions.
     */
    class ListTableCoreStandIn
    {
        /** @var array<mixed> */
        public $items = [];

        /** @var array<mixed> */
        public $_column_headers = [];

        /** @var array<mixed> */
        public array $pagination = [];

        public int $pagenum = 1;

        /**
         * @param  array<mixed>  $args
         */
        public function __construct(public array $args = []) {}

        public function get_pagenum(): int
        {
            return $this->pagenum;
        }

        /**
         * @param  array<mixed>  $args
         */
        public function set_pagination_args(array $args): void
        {
            $this->pagination = $args;
        }

        /**
         * @param  array<string, string>  $actions
         */
        public function row_actions($actions): string
        {
            return '[actions '.implode(' | ', array_keys($actions)).']'.implode('', $actions);
        }
    }

    // Declared under another name and aliased, so static analysis keeps reading core's own class.
    class_alias(ListTableCoreStandIn::class, 'WP_List_Table');
}

// prepare_items() asks for associative rows with core's constant.
if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__.'/../../includes/class-admin.php';
require_once __DIR__.'/../../includes/class-store.php';
require_once __DIR__.'/../../includes/class-list-table.php';

/**
 * Replace a method of a loaded class for the rest of the test.
 */
function lt_redefine(string $target, callable $replacement): void
{
    $redefine = lt_loose('Patchwork\\redefine');
    $redefine($target, $replacement);
}

/**
 * Hand a value over with its type hidden, as a caller outside this code may.
 */
function lt_loose(mixed $value): mixed
{
    return $value;
}

/**
 * Make the store report a summary without a database.
 *
 * @param  array<string, int>  $summary
 */
function lt_summary(array $summary): void
{
    lt_redefine('UAMS_MRA_Store::summary', static fn (): array => $summary);
}

/**
 * Build the table with the store's summary replaced.
 *
 * @param  array<string, int>  $summary
 */
function lt_table(array $summary = []): UAMS_MRA_List_Table
{
    lt_summary($summary);

    return new UAMS_MRA_List_Table;
}

/**
 * A $wpdb that records every query and answers from a script.
 */
function lt_wpdb(int $count = 0, mixed $rows = []): object
{
    return new class($count, $rows)
    {
        /** @var list<string> */
        public array $queries = [];

        public function __construct(private int $count, private mixed $rows) {}

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function prepare(string $query, mixed ...$args): string
        {
            $args = (count($args) === 1 && is_array($args[0])) ? $args[0] : $args;

            return 'PREPARED '.vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function get_var(string $query): string
        {
            $this->queries[] = $query;

            return (string) $this->count;
        }

        public function get_results(string $query, mixed $output = null): mixed
        {
            $this->queries[] = $query;

            return $this->rows;
        }
    };
}

/**
 * Call a protected method of the table.
 */
function lt_call(object $object, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
}

/**
 * What the protected badge method returns for a row.
 *
 * @param  array<string, mixed>  $item
 */
function lt_badge(array $item): string
{
    return (string) lt_call(lt_table(), 'render_badge', $item);
}

/**
 * A scanned row with every column the cells read.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function lt_row(array $over = []): array
{
    return array_merge([
        'attachment_id' => '7',
        'title' => 'Hero',
        'file' => '2024/05/hero.jpg',
        'mime' => 'image/jpeg',
        'is_attached' => '1',
        'ref_count' => '2',
        'ref_count_strict' => '1',
        'parent_id' => '0',
        'parent_status' => '',
        'bytes' => '2048',
        'post_date' => '2024-05-01 10:00:00',
    ], $over);
}

beforeEach(function (): void {
    stubTranslationFunctions();
    stubEscapeFunctions();
    when('sanitize_key')->alias(static fn ($key): string => strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key)));
    // Core trims too; this one does not, so the class's own trim is what the test sees.
    when('sanitize_text_field')->alias(static fn ($text): string => strip_tags((string) $text));
    when('wp_unslash')->alias(static fn ($value) => is_string($value) ? stripslashes($value) : $value);
    when('number_format_i18n')->alias(static fn ($n): string => number_format((float) $n));

    $_REQUEST = [];
});

afterEach(function (): void {
    $_REQUEST = [];
    unset($GLOBALS['wpdb']);
});

describe('the constructor', function (): void {
    it('names the items, turns ajax off and keeps the store summary', function (): void {
        $table = lt_table(['total' => 4]);

        expect(lt_loose($table)->args)->toBe(['singular' => 'media_item', 'plural' => 'media_items', 'ajax' => false]);

        $views = (fn (): array => $this->summary)->call($table);

        expect($views)->toBe(['total' => 4]);
    });
});

describe('current_view', function (): void {
    it('accepts each named view', function (string $view): void {
        // The default is another view, so a name that were dropped from the list would show.
        lt_summary($view === 'orphaned' ? ['total' => 9, 'weak_only' => 1] : ['total' => 9, 'orphaned' => 1]);
        $_REQUEST['view'] = $view;

        expect(UAMS_MRA_List_Table::current_view())->toBe($view);
    })->with(['all', 'orphaned', 'unreferenced', 'unattached', 'weak', 'referenced']);

    it('falls back to the default view when none is named', function (): void {
        lt_summary(['total' => 9, 'unattached' => 3]);

        expect(UAMS_MRA_List_Table::current_view())->toBe('unattached');
    });

    it('falls back to the default view when the name is not one of the views', function (): void {
        lt_summary(['total' => 9, 'weak_only' => 2]);
        $_REQUEST['view'] = 'bogus';

        expect(UAMS_MRA_List_Table::current_view())->toBe('weak');
    });

    it('cleans the name before comparing it', function (): void {
        lt_summary(['total' => 9, 'orphaned' => 1]);
        $_REQUEST['view'] = 'Weak!';

        expect(UAMS_MRA_List_Table::current_view())->toBe('weak');
    });
});

describe('default_view', function (): void {
    it('opens on the original landing before any scan has run', function (): void {
        lt_summary([]);

        expect(UAMS_MRA_List_Table::default_view())->toBe('orphaned');
    });

    it('opens on the original landing when the total is zero', function (): void {
        lt_summary(['total' => 0, 'orphaned' => 5]);

        expect(UAMS_MRA_List_Table::default_view())->toBe('orphaned');
    });

    it('opens on the first view that has rows, in order of how much it wants attention', function (array $summary, string $expected): void {
        lt_summary($summary);

        expect(UAMS_MRA_List_Table::default_view())->toBe($expected);
    })->with([
        'orphaned first' => [['total' => 9, 'orphaned' => 1, 'unreferenced' => 4, 'unattached' => 4, 'weak_only' => 4], 'orphaned'],
        'unreferenced next' => [['total' => 9, 'orphaned' => 0, 'unreferenced' => 4, 'unattached' => 4, 'weak_only' => 4], 'unreferenced'],
        'unattached next' => [['total' => 9, 'orphaned' => 0, 'unreferenced' => 0, 'unattached' => 4, 'weak_only' => 4], 'unattached'],
        'weak last' => [['total' => 9, 'orphaned' => 0, 'unreferenced' => 0, 'unattached' => 0, 'weak_only' => 4], 'weak'],
        'a missing count reads as none' => [['total' => 9, 'weak_only' => 1], 'weak'],
        'only a total reads as every count missing' => [['total' => 9], 'all'],
    ]);

    it('shows the whole library when nothing needs attention', function (): void {
        lt_summary(['total' => 9, 'orphaned' => 0, 'unreferenced' => 0, 'unattached' => 0, 'weak_only' => 0]);

        expect(UAMS_MRA_List_Table::default_view())->toBe('all');
    });
});

describe('where_for_view', function (): void {
    it('returns the fragment for each view', function (string $view, string $where): void {
        expect(UAMS_MRA_List_Table::where_for_view($view))->toBe($where);
    })->with([
        ['orphaned', 'ref_count = 0 AND is_attached = 0'],
        ['unreferenced', 'ref_count = 0'],
        ['unattached', 'is_attached = 0'],
        ['weak', 'ref_count > 0 AND ref_count_strict = 0'],
        ['referenced', 'ref_count > 0'],
        ['all', '1=1'],
        ['anything else', '1=1'],
    ]);

    it('is what the protected wrapper returns for the current view', function (): void {
        lt_summary(['total' => 9]);
        $_REQUEST['view'] = 'weak';
        $table = new UAMS_MRA_List_Table;

        $where = lt_call($table, 'view_where');

        expect($where)->toBe('ref_count > 0 AND ref_count_strict = 0');
    });
});

describe('get_views', function (): void {
    beforeEach(function (): void {
        // Echoing the URL would print it into the page, so the call must ask for it back.
        when('menu_page_url')->alias(static fn (string $slug, bool $echo): string => $echo ? '' : 'http://x/wp-admin/upload.php?page='.$slug);
        when('add_query_arg')->alias(static fn (string $key, string $value, string $url): string => $url.'&'.$key.'='.$value);
        when('admin_url')->alias(static fn (string $path): string => 'http://x/wp-admin/'.$path);
    });

    it('builds a link for each view, with its count, and marks the current one', function (): void {
        $table = lt_table(['total' => 1200, 'orphaned' => 3, 'unreferenced' => 5, 'unattached' => 7, 'weak_only' => 2]);
        $_REQUEST['view'] = 'unattached';

        $views = lt_call($table, 'get_views');

        expect(array_keys($views))->toBe(['orphaned', 'unreferenced', 'unattached', 'weak', 'referenced', 'all'])
            ->and($views['orphaned'])->toBe('<a href="http://x/wp-admin/upload.php?page=uams-media-audit&view=orphaned">Unattached and unreferenced <span class="count">(3)</span></a>')
            ->and($views['unreferenced'])->toBe('<a href="http://x/wp-admin/upload.php?page=uams-media-audit&view=unreferenced">Not referenced anywhere <span class="count">(5)</span></a>')
            ->and($views['unattached'])->toBe('<a href="http://x/wp-admin/upload.php?page=uams-media-audit&view=unattached" class="current" aria-current="page">Not attached to a post <span class="count">(7)</span></a>')
            ->and($views['weak'])->toBe('<a href="http://x/wp-admin/upload.php?page=uams-media-audit&view=weak">Referenced by ID only <span class="count">(2)</span></a>')
            ->and($views['referenced'])->toBe('<a href="http://x/wp-admin/upload.php?page=uams-media-audit&view=referenced">In use <span class="count">(1,195)</span></a>')
            ->and($views['all'])->toBe('<a href="http://x/wp-admin/upload.php?page=uams-media-audit&view=all">All media <span class="count">(1,200)</span></a>');
    });

    it('reads a missing count as zero', function (): void {
        $table = lt_table([]);

        $views = lt_call($table, 'get_views');

        expect($views['orphaned'])->toContain('(0)')
            ->and($views['unreferenced'])->toContain('(0)')
            ->and($views['unattached'])->toContain('(0)')
            ->and($views['weak'])->toContain('(0)')
            ->and($views['referenced'])->toContain('(0)')
            ->and($views['all'])->toContain('(0)');
    });

    it('builds the base from the upload screen when the menu page has no URL', function (): void {
        when('menu_page_url')->justReturn('');
        $table = lt_table(['total' => 1]);

        $views = lt_call($table, 'get_views');

        expect($views['all'])->toContain('href="http://x/wp-admin/upload.php&page=uams-media-audit&view=all"');
    });
});

describe('the column, sort and bulk declarations', function (): void {
    it('lists the columns in order', function (): void {
        expect(lt_table()->get_columns())->toBe([
            'cb' => '<input type="checkbox" />',
            'thumb' => 'Preview',
            'title' => 'File',
            'status' => 'Status',
            'triage' => 'Decision',
            'attached' => 'Attached to',
            'refs' => 'Referenced in',
            'bytes' => 'Disk space',
            'date' => 'Uploaded',
        ]);
    });

    it('makes the file, size and date sortable, the last two newest and largest first', function (): void {
        expect(lt_table()->get_sortable_columns())->toBe([
            'title' => ['title', false],
            'bytes' => ['bytes', true],
            'date' => ['post_date', true],
        ]);
    });

    it('offers one bulk action', function (): void {
        expect(lt_table()->get_bulk_actions())->toBe(['uams_mra_delete' => 'Delete permanently']);
    });
});

describe('current_tier and current_disposition', function (): void {
    it('accepts a known tier and cleans it', function (): void {
        $_REQUEST['tier'] = 'Ask';

        expect(UAMS_MRA_List_Table::current_tier())->toBe('ask');
    });

    it('reads an unknown or missing tier as every tier', function (): void {
        expect(UAMS_MRA_List_Table::current_tier())->toBe('');

        $_REQUEST['tier'] = 'bogus';

        expect(UAMS_MRA_List_Table::current_tier())->toBe('');
    });

    it('accepts a known disposition and the none filter', function (string $value): void {
        $_REQUEST['disp'] = $value;

        expect(UAMS_MRA_List_Table::current_disposition())->toBe($value);
    })->with(['keep', 'ask', 'delete', 'none']);

    it('reads an unknown or missing disposition as any', function (): void {
        expect(UAMS_MRA_List_Table::current_disposition())->toBe('');

        $_REQUEST['disp'] = 'bogus';

        expect(UAMS_MRA_List_Table::current_disposition())->toBe('');
    });
});

describe('build_query', function (): void {
    beforeEach(function (): void {
        lt_summary(['total' => 9, 'orphaned' => 1]);
        $GLOBALS['wpdb'] = lt_wpdb();
        lt_redefine('UAMS_MRA_Triage::tier_where', static fn ($tier): string => 'TIER('.$tier.')');
        lt_redefine('UAMS_MRA_Triage::disposition_where', static fn ($disposition): string => 'DISP('.$disposition.')');
    });

    it('is the view alone when nothing narrows it', function (): void {
        expect(UAMS_MRA_List_Table::build_query())->toBe([
            'where' => 'ref_count = 0 AND is_attached = 0',
            'params' => [],
        ]);
    });

    it('adds a search over the title and the file, with the term escaped for LIKE', function (): void {
        $_REQUEST['view'] = 'all';
        $_REQUEST['s'] = '  100%_sure ';

        expect(UAMS_MRA_List_Table::build_query())->toBe([
            'where' => '1=1 AND (title LIKE %s OR file LIKE %s)',
            'params' => ['%100\\%\\_sure%', '%100\\%\\_sure%'],
        ]);
    });

    it('ignores a search that is only whitespace', function (): void {
        $_REQUEST['view'] = 'all';
        $_REQUEST['s'] = '   ';

        expect(UAMS_MRA_List_Table::build_query())->toBe(['where' => '1=1', 'params' => []]);
    });

    it('adds the tier and the disposition fragments in parentheses', function (): void {
        $_REQUEST['view'] = 'referenced';
        $_REQUEST['tier'] = 'verify';
        $_REQUEST['disp'] = 'keep';

        expect(UAMS_MRA_List_Table::build_query())->toBe([
            'where' => 'ref_count > 0 AND ( TIER(verify) ) AND ( DISP(keep) )',
            'params' => [],
        ]);
    });

    it('adds the tier without a disposition and the disposition without a tier', function (): void {
        $_REQUEST['view'] = 'all';
        $_REQUEST['tier'] = 'ask';

        expect(UAMS_MRA_List_Table::build_query()['where'])->toBe('1=1 AND ( TIER(ask) )');

        unset($_REQUEST['tier']);
        $_REQUEST['disp'] = 'none';

        expect(UAMS_MRA_List_Table::build_query()['where'])->toBe('1=1 AND ( DISP(none) )');
    });
});

describe('prepare_items', function (): void {
    beforeEach(function (): void {
        lt_summary(['total' => 9, 'orphaned' => 1]);
        $_REQUEST['view'] = 'all';
        when('wp_list_pluck')->alias(static fn (array $list, string $field): array => array_column($list, $field));
    });

    it('counts and loads the first page, newest bytes first, and primes the meta of the rows', function (): void {
        $rows = [lt_row(['attachment_id' => 7]), lt_row(['attachment_id' => 9])];
        $wpdb = lt_wpdb(120, $rows);
        $GLOBALS['wpdb'] = $wpdb;
        lt_redefine('UAMS_MRA_Store::results_table', static fn (): string => 'wp_uams_mra_results');
        $primed = [];
        when('update_meta_cache')->alias(static function (string $type, array $ids) use (&$primed): void {
            $primed = [$type, $ids];
        });
        $table = new UAMS_MRA_List_Table;

        $table->prepare_items();

        expect($wpdb->queries)->toBe([
            'SELECT COUNT(*) FROM wp_uams_mra_results WHERE 1=1',
            'PREPARED SELECT * FROM wp_uams_mra_results WHERE 1=1 ORDER BY bytes DESC, attachment_id DESC LIMIT 50 OFFSET 0',
        ])
            ->and($table->items)->toBe($rows)
            ->and(lt_loose($table)->pagination)->toBe(['total_items' => 120, 'per_page' => 50, 'total_pages' => 3])
            ->and($primed)->toBe(['post', [7, 9]])
            ->and((fn (): array => $this->_column_headers)->call($table))->toBe([$table->get_columns(), [], $table->get_sortable_columns(), 'title']);
    });

    it('binds the search terms and the paging into the prepared statements', function (): void {
        $wpdb = lt_wpdb(1, []);
        $GLOBALS['wpdb'] = $wpdb;
        lt_redefine('UAMS_MRA_Store::results_table', static fn (): string => 'T');
        $_REQUEST['s'] = 'hero';
        $table = new UAMS_MRA_List_Table;
        lt_loose($table)->pagenum = 3;

        $table->prepare_items();

        expect($wpdb->queries)->toBe([
            "PREPARED SELECT COUNT(*) FROM T WHERE 1=1 AND (title LIKE '%hero%' OR file LIKE '%hero%')",
            "PREPARED SELECT * FROM T WHERE 1=1 AND (title LIKE '%hero%' OR file LIKE '%hero%') ORDER BY bytes DESC, attachment_id DESC LIMIT 50 OFFSET 100",
        ]);
    });

    it('starts at the first row on page one and never before it', function (): void {
        $wpdb = lt_wpdb(1, []);
        $GLOBALS['wpdb'] = $wpdb;
        lt_redefine('UAMS_MRA_Store::results_table', static fn (): string => 'T');
        $_REQUEST['s'] = 'x';
        $table = new UAMS_MRA_List_Table;

        lt_loose($table)->pagenum = 1;
        $table->prepare_items();

        expect($wpdb->queries[1])->toEndWith('LIMIT 50 OFFSET 0');

        $wpdb = lt_wpdb(1, []);
        $GLOBALS['wpdb'] = $wpdb;
        lt_loose($table)->pagenum = 0;
        $table->prepare_items();

        expect($wpdb->queries[1])->toEndWith('LIMIT 50 OFFSET 0');
    });

    it('sorts by a named column in the named direction', function (string $orderby, string $order, string $expected): void {
        $wpdb = lt_wpdb(1, []);
        $GLOBALS['wpdb'] = $wpdb;
        lt_redefine('UAMS_MRA_Store::results_table', static fn (): string => 'T');
        $_REQUEST['orderby'] = $orderby;
        $_REQUEST['order'] = $order;
        $table = new UAMS_MRA_List_Table;

        $table->prepare_items();

        expect($wpdb->queries[1])->toContain('ORDER BY '.$expected.', attachment_id DESC');
    })->with([
        'title ascending' => ['title', 'asc', 'title ASC'],
        'title in capitals' => ['TITLE', 'ASC', 'title ASC'],
        'bytes descending' => ['bytes', 'desc', 'bytes DESC'],
        'date' => ['post_date', 'asc', 'post_date ASC'],
        'id' => ['attachment_id', 'desc', 'attachment_id DESC'],
        'an unknown column falls back to bytes' => ['title; DROP TABLE x', 'asc', 'bytes ASC'],
        'an unknown direction falls back to descending' => ['title', 'sideways', 'title DESC'],
    ]);

    it('rounds a partial last page up', function (): void {
        $GLOBALS['wpdb'] = lt_wpdb(101, []);
        lt_redefine('UAMS_MRA_Store::results_table', static fn (): string => 'T');
        $table = new UAMS_MRA_List_Table;

        $table->prepare_items();

        expect(lt_loose($table)->pagination['total_pages'])->toBe(3);

        $GLOBALS['wpdb'] = lt_wpdb(100, []);
        $table->prepare_items();

        expect(lt_loose($table)->pagination['total_pages'])->toBe(2);
    });

    it('does not prime anything when there are no rows', function (): void {
        $GLOBALS['wpdb'] = lt_wpdb(0, []);
        lt_redefine('UAMS_MRA_Store::results_table', static fn (): string => 'T');
        when('update_meta_cache')->alias(static function (): void {
            throw new RuntimeException('no rows to prime');
        });
        $table = new UAMS_MRA_List_Table;

        $table->prepare_items();

        expect($table->items)->toBe([])
            ->and(lt_loose($table)->pagination['total_items'])->toBe(0);
    });
});

describe('the cells', function (): void {
    beforeEach(function (): void {
        when('get_edit_post_link')->alias(static fn ($id): string => 'http://x/edit/'.$id);
        when('wp_get_attachment_url')->alias(static fn ($id): string => 'http://x/file/'.$id);
    });

    it('renders a checkbox that carries the attachment id', function (): void {
        expect(lt_table()->column_cb(lt_row(['attachment_id' => '42abc'])))->toBe('<input type="checkbox" name="ids[]" value="42" />');
    });

    it('prints the id as a number, not as the stored text', function (): void {
        $out = lt_table()->column_title(lt_row(['attachment_id' => '07']));

        expect($out)->toContain('href="http://x/edit/7"')
            ->and($out)->toContain('ID 7 &middot;');
    });

    it('reads a padded zero as zero in the status, parent and reference cells', function (): void {
        lt_redefine('UAMS_MRA_Store::get_refs', static function (): array {
            throw new RuntimeException('no refs to fetch');
        });
        $table = lt_table();

        expect($table->column_status(lt_row(['is_attached' => '00', 'ref_count' => '00'])))->toStartWith('<span class="uams-mra-badge uams-mra-badge-warn">Unattached</span>')
            ->and($table->column_status(lt_row(['ref_count' => '00'])))->toContain('No references')
            ->and($table->column_attached(lt_row(['parent_id' => '00'])))->toBe('<span class="uams-mra-muted">Nothing</span>')
            ->and($table->column_refs(lt_row(['ref_count' => '00'])))->toBe('<span class="uams-mra-muted">None found</span>');
    });

    describe('column_thumb', function (): void {
        it('shows a small thumbnail for an image', function (): void {
            when('wp_attachment_is_image')->justReturn(true);
            $seen = [];
            when('wp_get_attachment_image')->alias(static function ($id, $size, $icon, $attr) use (&$seen): string {
                $seen = [$id, $size, $icon, $attr];

                return '<img src="t.jpg">';
            });

            expect(lt_table()->column_thumb(lt_row(['attachment_id' => '7'])))->toBe('<img src="t.jpg">')
                ->and($seen)->toBe([7, [60, 60], true, ['alt' => '']]);
        });

        it('falls back to the generic icon when the image cannot be built', function (): void {
            when('wp_attachment_is_image')->justReturn(true);
            when('wp_get_attachment_image')->justReturn('');

            expect(lt_table()->column_thumb(lt_row()))->toBe('<span class="dashicons dashicons-media-default" aria-hidden="true"></span>');
        });

        it('shows the generic icon for a file that is not an image, without asking for a thumbnail', function (): void {
            when('wp_attachment_is_image')->justReturn(false);
            when('wp_get_attachment_image')->alias(static function (): string {
                throw new RuntimeException('not an image');
            });

            expect(lt_table()->column_thumb(lt_row()))->toBe('<span class="dashicons dashicons-media-default" aria-hidden="true"></span>');
        });
    });

    describe('column_title', function (): void {
        it('shows the title as an edit link, the file, the id and mime, and two row actions', function (): void {
            expect(lt_table()->column_title(lt_row()))->toBe(
                '<strong><a href="http://x/edit/7">Hero</a></strong>'
                .'<div class="uams-mra-file">2024/05/hero.jpg</div>'
                .'<div class="uams-mra-meta">ID 7 &middot; image/jpeg</div>'
                .'[actions edit | view]'
                .'<a href="http://x/edit/7">Edit</a>'
                .'<a href="http://x/file/7" target="_blank" rel="noopener">Open file</a>'
            );
        });

        it('says so when the title is empty', function (): void {
            expect(lt_table()->column_title(lt_row(['title' => ''])))->toContain('<a href="http://x/edit/7">(no title)</a>');
        });
    });

    describe('column_status', function (): void {
        it('shows only the in-use badge for an attached, referenced file', function (): void {
            expect(lt_table()->column_status(lt_row()))->toBe('<span class="uams-mra-badge uams-mra-badge-ok">In use</span>');
        });

        it('adds the unattached badge first', function (): void {
            expect(lt_table()->column_status(lt_row(['is_attached' => '0'])))->toBe(
                '<span class="uams-mra-badge uams-mra-badge-warn">Unattached</span> <span class="uams-mra-badge uams-mra-badge-ok">In use</span>'
            );
        });

        it('shows no references when there are none', function (): void {
            expect(lt_table()->column_status(lt_row(['ref_count' => '0', 'ref_count_strict' => '0'])))->toBe(
                '<span class="uams-mra-badge uams-mra-badge-danger">No references</span>'
            );
        });

        it('shows an ID-only reference when no strict reference exists', function (): void {
            expect(lt_table()->column_status(lt_row(['ref_count' => '3', 'ref_count_strict' => '0'])))->toBe(
                '<span class="uams-mra-badge uams-mra-badge-info">ID reference only</span>'
            );
        });

        it('names a deleted or trashed parent', function (): void {
            expect(lt_table()->column_status(lt_row(['parent_status' => 'missing'])))->toBe(
                '<span class="uams-mra-badge uams-mra-badge-ok">In use</span> <span class="uams-mra-badge uams-mra-badge-warn">Parent deleted</span>'
            )
                ->and(lt_table()->column_status(lt_row(['parent_status' => 'trash'])))->toBe(
                    '<span class="uams-mra-badge uams-mra-badge-ok">In use</span> <span class="uams-mra-badge uams-mra-badge-warn">Parent in trash</span>'
                )
                ->and(lt_table()->column_status(lt_row(['parent_status' => 'publish'])))->toBe(
                    '<span class="uams-mra-badge uams-mra-badge-ok">In use</span>'
                );
        });

        it('ends with the render badge when the page was read', function (): void {
            expect(lt_table()->column_status(lt_row(['render_state' => 'error'])))->toBe(
                '<span class="uams-mra-badge uams-mra-badge-ok">In use</span> <span class="uams-mra-badge uams-mra-badge-warn">Page unreachable</span>'
            );
        });
    });

    describe('render_badge', function (): void {
        it('is empty when no state is recorded', function (): void {
            expect(lt_badge(lt_row()))->toBe('')
                ->and(lt_badge(lt_row(['render_state' => ''])))->toBe('')
                ->and(lt_badge(lt_row(['render_state' => 'bogus'])))->toBe('');
        });

        it('maps each state to its class and label', function (string $state, string $class, string $label): void {
            $out = lt_badge(lt_row(['render_state' => $state]));

            expect($out)->toContain('uams-mra-badge-'.$class.'"')
                ->and($out)->toContain('>'.$label.'</span>');
        })->with([
            ['found', 'ok', 'Shown on the page'],
            ['offsite', 'info', 'Same name served elsewhere'],
            ['absent', 'danger', 'Not on the page'],
            ['error', 'warn', 'Page unreachable'],
            ['skipped', 'warn', 'Page not checked'],
        ]);

        it('links to the page for the states that found something or did not', function (string $state): void {
            $out = lt_badge(lt_row(['render_state' => $state, 'render_url' => 'http://x/page']));

            expect($out)->toStartWith('<a href="http://x/page" target="_blank" rel="noopener"><span')
                ->and($out)->toEndWith('</span></a>');
        })->with(['absent', 'found', 'offsite']);

        it('does not link for the states that read nothing', function (string $state): void {
            expect(lt_badge(lt_row(['render_state' => $state, 'render_url' => 'http://x/page'])))->toStartWith('<span');
        })->with(['error', 'skipped']);

        it('does not link without a URL', function (): void {
            expect(lt_badge(lt_row(['render_state' => 'found', 'render_url' => ''])))->toStartWith('<span')
                ->and(lt_badge(lt_row(['render_state' => 'found'])))->toStartWith('<span');
        });

        it('prints the class, the title and the label in that order', function (): void {
            expect(lt_badge(lt_row(['render_state' => 'error', 'render_note' => 'HTTP 500'])))->toBe(
                '<span class="uams-mra-badge uams-mra-badge-warn" title="HTTP 500">Page unreachable</span>'
            )
                ->and(lt_badge(lt_row(['render_state' => 'error'])))->toBe(
                    '<span class="uams-mra-badge uams-mra-badge-warn">Page unreachable</span>'
                );
        });

        it('titles the badge with when the page was read', function (): void {
            expect(lt_badge(lt_row(['render_state' => 'found', 'render_checked_at' => '2024-05-01 10:00:00'])))->toContain(' title="Page read 2024-05-01 10:00:00"');
        });

        it('leaves the title off for an unset or zero timestamp', function (): void {
            expect(lt_badge(lt_row(['render_state' => 'found', 'render_checked_at' => '0000-00-00 00:00:00'])))->not->toContain('title=')
                ->and(lt_badge(lt_row(['render_state' => 'found', 'render_checked_at' => ''])))->not->toContain('title=')
                ->and(lt_badge(lt_row(['render_state' => 'found'])))->not->toContain('title=');
        });

        it('leads the title with the note, then the date', function (): void {
            expect(lt_badge(lt_row(['render_state' => 'absent', 'render_note' => 'HTTP 404', 'render_checked_at' => '2024-05-01 10:00:00'])))->toContain(' title="HTTP 404 -- Page read 2024-05-01 10:00:00"')
                ->and(lt_badge(lt_row(['render_state' => 'absent', 'render_note' => 'HTTP 404'])))->toContain(' title="HTTP 404"');
        });

        it('escapes the class and the title it prints', function (): void {
            $out = lt_badge(lt_row(['render_state' => 'absent', 'render_note' => 'a "quote"']));

            expect($out)->toContain('title="a &quot;quote&quot;"');
        });
    });

    describe('column_attached', function (): void {
        it('says nothing is attached when there is no parent', function (): void {
            expect(lt_table()->column_attached(lt_row(['parent_id' => '0'])))->toBe('<span class="uams-mra-muted">Nothing</span>');
        });

        it('links to the parent by its title', function (): void {
            when('get_the_title')->justReturn('About us');

            expect(lt_table()->column_attached(lt_row(['parent_id' => '12'])))->toBe('<a href="http://x/edit/12">About us</a>');
        });

        it('falls back to the id when the parent has no title', function (): void {
            when('get_the_title')->justReturn('');

            expect(lt_table()->column_attached(lt_row(['parent_id' => '12'])))->toBe('<a href="http://x/edit/12">#12</a>');
        });

        it('names a parent that no longer exists', function (): void {
            when('get_the_title')->justReturn('');
            when('get_edit_post_link')->justReturn();

            expect(lt_table()->column_attached(lt_row(['parent_id' => '12'])))->toBe('Post 12 (deleted)');
        });
    });

    describe('column_refs', function (): void {
        it('says none were found when the count is zero, without asking the store', function (): void {
            lt_redefine('UAMS_MRA_Store::get_refs', static function (): array {
                throw new RuntimeException('no refs to fetch');
            });

            expect(lt_table()->column_refs(lt_row(['ref_count' => '0'])))->toBe('<span class="uams-mra-muted">None found</span>');
        });

        it('lists the first five references and links the ones that live on a post', function (): void {
            $asked = [];
            lt_redefine('UAMS_MRA_Store::get_refs', static function ($id, $limit) use (&$asked): array {
                $asked = [$id, $limit];

                return [
                    (object) ['source_label' => 'Page A', 'source_type' => 'content', 'source_id' => '03', 'confidence' => '2'],
                    (object) ['source_label' => 'Meta B', 'source_type' => 'postmeta', 'source_id' => '4', 'confidence' => '2'],
                    (object) ['source_label' => 'Rev C', 'source_type' => 'revision', 'source_id' => '5', 'confidence' => '2'],
                    (object) ['source_label' => 'RevMeta D', 'source_type' => 'revision_meta', 'source_id' => '6', 'confidence' => '2'],
                    (object) ['source_label' => 'Option E', 'source_type' => 'option', 'source_id' => '7', 'confidence' => '2'],
                ];
            });

            expect(lt_table()->column_refs(lt_row(['attachment_id' => '9', 'ref_count' => '5'])))->toBe(
                '<ul class="uams-mra-refs">'
                .'<li><a href="http://x/edit/3">Page A</a></li>'
                .'<li><a href="http://x/edit/4">Meta B</a></li>'
                .'<li><a href="http://x/edit/5">Rev C</a></li>'
                .'<li><a href="http://x/edit/6">RevMeta D</a></li>'
                .'<li>Option E</li>'
                .'</ul>'
            )
                ->and($asked)->toBe([9, 5]);
        });

        it('marks a reference found by ID only, with or without a link', function (): void {
            lt_redefine('UAMS_MRA_Store::get_refs', static fn (): array => [
                (object) ['source_label' => 'Page A', 'source_type' => 'content', 'source_id' => '3', 'confidence' => '1'],
                (object) ['source_label' => 'Opt', 'source_type' => 'option', 'source_id' => '0', 'confidence' => '1'],
                (object) ['source_label' => 'Strict', 'source_type' => 'option', 'source_id' => '0', 'confidence' => '2'],
            ]);

            $out = lt_table()->column_refs(lt_row(['ref_count' => '3']));

            expect($out)->toContain('<li><a href="http://x/edit/3">Page A</a> <span class="uams-mra-muted">(by ID)</span></li>')
                ->and($out)->toContain('<li>Opt <span class="uams-mra-muted">(by ID)</span></li>')
                ->and($out)->toContain('<li>Strict</li>');
        });

        it('counts the references it did not list', function (): void {
            lt_redefine('UAMS_MRA_Store::get_refs', static fn (): array => [
                (object) ['source_label' => 'A', 'source_type' => 'option', 'source_id' => '0', 'confidence' => '2'],
            ]);

            expect(lt_table()->column_refs(lt_row(['ref_count' => '1201'])))->toEndWith('</ul><span class="uams-mra-muted">and 1,200 more</span>');
        });

        it('says nothing more when every reference was listed', function (): void {
            lt_redefine('UAMS_MRA_Store::get_refs', static fn (): array => [
                (object) ['source_label' => 'A', 'source_type' => 'option', 'source_id' => '0', 'confidence' => '2'],
            ]);

            expect(lt_table()->column_refs(lt_row(['ref_count' => '1'])))->toEndWith('</ul>');
        });
    });

    describe('column_bytes', function (): void {
        it('formats the size to one decimal', function (): void {
            $seen = [];
            when('size_format')->alias(static function ($bytes, $decimals) use (&$seen): string {
                $seen = [$bytes, $decimals];

                return '2.0 KB';
            });

            expect(lt_table()->column_bytes(lt_row(['bytes' => '2048'])))->toBe('2.0 KB')
                ->and($seen)->toBe([2048, 1]);
        });

        it('says the file is missing when it has no size', function (): void {
            expect(lt_table()->column_bytes(lt_row(['bytes' => '0'])))->toBe('<span class="uams-mra-muted">File missing</span>');
        });
    });

    describe('column_date', function (): void {
        it('formats the upload date with the site format', function (): void {
            when('get_option')->alias(static fn (string $name): string => $name === 'date_format' ? 'F j, Y' : '');
            when('mysql2date')->alias(static fn (string $format, string $date): string => $format.'|'.$date);

            expect(lt_table()->column_date(lt_row()))->toBe('F j, Y|2024-05-01 10:00:00');
        });
    });

    describe('no_items', function (): void {
        it('tells people to scan or pick another filter', function (): void {
            ob_start();
            lt_table()->no_items();

            expect(ob_get_clean())->toBe('No media matches this filter. Run a scan if you have not yet, or pick another filter above.');
        });
    });

    describe('column_triage', function (): void {
        beforeEach(function (): void {
            when('get_post_meta')->alias(static fn ($id, $key, $single): string => match ($key) {
                UAMS_MRA_Triage::META_DISPOSITION => 'keep',
                UAMS_MRA_Triage::META_NOTE => 'ask Pat',
                default => '',
            });
            lt_redefine('UAMS_MRA_Triage::tier', static fn ($row): string => 'ask');
        });

        it('renders the tier, a button per disposition with the set one pressed, and the note', function (): void {
            $tiers = UAMS_MRA_Triage::tiers();

            expect(lt_table()->column_triage(lt_row()))->toBe(
                '<div class="uams-mra-tri" data-id="7" data-tier="ask" data-d="keep">'
                .'<span class="uams-mra-tier uams-mra-tier-ask" title="'.$tiers['ask']['desc'].'">'.$tiers['ask']['label'].'</span>'
                .'<span class="uams-mra-tri-buttons">'
                .'<button type="button" class="uams-mra-tri-btn" data-d="keep" aria-pressed="true">Keep</button>'
                .'<button type="button" class="uams-mra-tri-btn" data-d="ask" aria-pressed="false">Ask</button>'
                .'<button type="button" class="uams-mra-tri-btn" data-d="delete" aria-pressed="false">Delete</button>'
                .'</span>'
                .'<input type="text" class="uams-mra-tri-note" value="ask Pat" maxlength="200" placeholder="Note, or who to ask…" aria-label="Note about this file" />'
                .'<span class="uams-mra-tri-state" aria-live="polite"></span>'
                .'</div>'
            );
        });

        it('hides the note when there is neither a disposition nor a note', function (): void {
            when('get_post_meta')->justReturn('');

            expect(lt_table()->column_triage(lt_row()))->toContain('aria-label="Note about this file" hidden />');
        });

        it('shows the note when only a disposition is set', function (): void {
            when('get_post_meta')->alias(static fn ($id, $key, $single): string => $key === UAMS_MRA_Triage::META_DISPOSITION ? 'ask' : '');

            expect(lt_table()->column_triage(lt_row()))->not->toContain('hidden')
                ->and(lt_table()->column_triage(lt_row()))->toContain('data-d="ask"');
        });

        it('shows the note when only a note is set', function (): void {
            when('get_post_meta')->alias(static fn ($id, $key, $single): string => $key === UAMS_MRA_Triage::META_NOTE ? 'later' : '');

            expect(lt_table()->column_triage(lt_row()))->not->toContain('hidden')
                ->and(lt_table()->column_triage(lt_row()))->toContain('value="later"');
        });

        it('presses no button when nothing is set', function (): void {
            when('get_post_meta')->justReturn('');

            expect(lt_table()->column_triage(lt_row()))->not->toContain('aria-pressed="true"');
        });
    });
});

// cspell:ignore dashicons
