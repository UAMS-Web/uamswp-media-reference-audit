<?php

/**
 * UAMS_MRA_CLI: the scan, list, delete and why commands, with WP-CLI, the store and the scanner mocked.
 *
 * WP-CLI is not loaded here, so a stand-in records every call the commands make.
 * Its error() and confirm() stop the run by throwing, as the real ones end the
 * process, so a command that carried on after a refusal would show.
 *
 * @see includes/class-cli.php
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\when;

mutates(UAMS_MRA_CLI::class);

/**
 * A stand-in for WP_Error, which WordPress defines and the Unit suite does not load.
 *
 * Aliased at run time rather than declared as `WP_Error`, so static analysis keeps reading the real class.
 */
final class CliFakeError
{
    public function __construct(private readonly string $message = '') {}

    public function get_error_message(): string
    {
        return $this->message;
    }
}

if (! class_exists('WP_Error')) {
    class_alias(CliFakeError::class, 'WP_Error');
}

if (! class_exists('WP_CLI')) {
    /**
     * Thrown by the stand-in where the real WP-CLI would end the process.
     */
    final class CliHalt extends RuntimeException {}

    /**
     * What the commands call on WP-CLI, recorded in order.
     */
    class CliStandIn
    {
        /** @var list<array{string, mixed}> */
        public static array $calls = [];

        public static bool $decline = false;

        public static function log(mixed $message): void
        {
            self::$calls[] = ['log', $message];
        }

        public static function line(mixed $message): void
        {
            self::$calls[] = ['line', $message];
        }

        public static function success(mixed $message): void
        {
            self::$calls[] = ['success', $message];
        }

        public static function warning(mixed $message): void
        {
            self::$calls[] = ['warning', $message];
        }

        public static function error(mixed $message): void
        {
            self::$calls[] = ['error', $message];

            /** @var scalar|null $text */
            $text = $message;

            throw new CliHalt((string) $text);
        }

        /**
         * @param  array<string, mixed>  $assoc_args
         */
        public static function confirm(mixed $question, array $assoc_args = []): void
        {
            self::$calls[] = ['confirm', [$question, $assoc_args]];

            if (self::$decline) {
                throw new CliHalt('declined');
            }
        }
    }
}

if (! class_exists('WP_CLI', false)) {
    class_alias(CliStandIn::class, 'WP_CLI');
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__.'/../../includes/class-store.php';
require_once __DIR__.'/../../includes/class-scanner.php';
require_once __DIR__.'/../../includes/class-cli.php';

/**
 * Replace a method of a loaded class for the rest of the test.
 */
function cli_redefine(string $target, callable $replacement): void
{
    /** @var callable $redefine */
    $redefine = cli_loose('Patchwork\\redefine');
    $redefine($target, $replacement);
}

/**
 * Hand a value over with its type hidden, as a caller outside this code may.
 */
function cli_loose(mixed $value): mixed
{
    return $value;
}

/**
 * A progress bar that counts its ticks.
 *
 * @return object{ticks: int, finished: int}
 */
function cli_bar(): object
{
    return new class
    {
        public int $ticks = 0;

        public int $finished = 0;

        public function tick(): void
        {
            $this->ticks++;
        }

        public function finish(): void
        {
            $this->finished++;
        }
    };
}

/**
 * A $wpdb that records its queries and deletes and answers from a script.
 *
 * @return object{queries: list<string>, outputs: list<mixed>, deletes: list<array{string, array<string, int>, array<string>}>}
 */
function cli_wpdb(mixed $rows = []): object
{
    return new class($rows)
    {
        /** @var list<string> */
        public array $queries = [];

        /** @var list<mixed> */
        public array $outputs = [];

        /** @var list<array{string, array<string, int>, array<string>}> */
        public array $deletes = [];

        public function __construct(private mixed $rows) {}

        public function prepare(string $query, mixed ...$args): string
        {
            /** @var list<bool|float|int|string|null> $values */
            $values = $args;

            return vsprintf($query, $values);
        }

        public function get_results(string $query, mixed $output = null): mixed
        {
            $this->queries[] = $query;
            $this->outputs[] = $output;

            return $this->rows;
        }

        /**
         * @param  array<string, int>  $where
         * @param  array<string>  $format
         */
        public function delete(string $table, array $where, array $format): int
        {
            $this->deletes[] = [$table, $where, $format];

            return 1;
        }
    };
}

// What the commands handed to format_items(), in order.
$GLOBALS['cli_format_calls'] = [];

/**
 * What the commands handed to format_items(), in order.
 *
 * @return list<array{string, list<array<string, mixed>>, list<string>}>
 */
function cli_format_calls(): array
{
    /** @var list<array{string, list<array<string, mixed>>, list<string>}> $calls */
    $calls = $GLOBALS['cli_format_calls'];

    return $calls;
}

/**
 * Everything the commands have done to WP-CLI so far.
 *
 * @return list<array{string, mixed}>
 */
function cli_calls(): array
{
    return CliStandIn::$calls;
}

/**
 * The messages of one kind of WP-CLI call.
 *
 * @return list<mixed>
 */
function cli_messages(string $kind): array
{
    return array_values(array_map(static fn (array $call): mixed => $call[1], array_filter(CliStandIn::$calls, static fn (array $call): bool => $call[0] === $kind)));
}

/**
 * Make the store answer without a database.
 */
function cli_store(string $table = 'wp_uams_mra_results'): void
{
    cli_redefine('UAMS_MRA_Store::results_table', static fn (): string => $table);
}

beforeEach(function (): void {
    CliStandIn::$calls = [];
    CliStandIn::$decline = false;
    $GLOBALS['cli_format_calls'] = [];

    // The real size_format() takes a number; a string byte count left uncast would reach it unchanged.
    when('size_format')->alias(static fn ($bytes, $decimals = 0): string => is_int($bytes) ? $bytes.'B@'.$decimals : throw new LogicException('size_format() got a '.gettype($bytes)));
    when('wp_list_pluck')->alias(static fn (array $list, string $field): array => array_column($list, $field));
    when('WP_CLI\Utils\format_items')->alias(static function ($format, $items, $fields): void {
        /** @var list<array{mixed, mixed, mixed}> $calls */
        $calls = $GLOBALS['cli_format_calls'];
        $calls[] = [$format, $items, $fields];
        $GLOBALS['cli_format_calls'] = $calls;
    });
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

describe('scan', function (): void {
    /**
     * Script a scan: start() answers, then each step() answers the next state.
     *
     * @param  list<array{step: string, message: string}>  $steps
     * @param  array<string, int>  $summary
     * @return array{steps: int, installs: int, starts: int}
     */
    $scan = static function (array $start, array $steps, array $assoc = [], array $summary = []): array {
        $seen = ['steps' => 0, 'installs' => 0, 'starts' => 0];
        $queue = $steps;

        cli_redefine('UAMS_MRA_Store::install', static function () use (&$seen): void {
            $seen['installs']++;
        });
        cli_redefine('UAMS_MRA_Scanner::start', static function () use (&$seen, $start): array {
            $seen['starts']++;

            return $start;
        });
        cli_redefine('UAMS_MRA_Scanner::step', static function () use (&$seen, &$queue): array {
            $seen['steps']++;

            return array_shift($queue) ?? ['step' => 'done', 'message' => 'ran out'];
        });
        cli_redefine('UAMS_MRA_Store::summary', static fn (): array => array_merge(
            ['total' => 12, 'unattached' => 5, 'unreferenced' => 4, 'orphaned' => 3, 'orphaned_bytes' => 2048],
            $summary
        ));

        (new UAMS_MRA_CLI)->scan([], $assoc);

        return $seen;
    };

    it('installs the tables, starts, steps until done and reports the summary', function () use ($scan): void {
        $seen = $scan(
            ['step' => 'posts', 'message' => 'Starting'],
            [['step' => 'posts', 'message' => 'Posts'], ['step' => 'meta', 'message' => 'Meta'], ['step' => 'done', 'message' => 'Finished']]
        );

        expect($seen)->toBe(['steps' => 3, 'installs' => 1, 'starts' => 1])
            ->and(cli_messages('log'))->toBe(['Posts', 'Meta', 'Finished'])
            ->and(cli_messages('success'))->toBe(['12 media files. 5 unattached, 4 unreferenced, 3 both (2048B@1 reclaimable).']);
    });

    it('stops with the error message when start() refuses, without stepping or reporting', function (): void {
        $seen = ['steps' => 0];

        when('is_wp_error')->alias(static fn ($value): bool => $value instanceof WP_Error);
        cli_redefine('UAMS_MRA_Store::install', static function (): void {});
        cli_redefine('UAMS_MRA_Scanner::start', static fn (): CliFakeError => new CliFakeError('A repoint is running.'));
        cli_redefine('UAMS_MRA_Scanner::step', static function () use (&$seen): array {
            $seen['steps']++;

            return ['step' => 'done', 'message' => ''];
        });
        cli_redefine('UAMS_MRA_Store::summary', static function (): array {
            throw new LogicException('the summary must not be read after a refusal');
        });

        expect(fn () => (new UAMS_MRA_CLI)->scan([], []))->toThrow(CliHalt::class, 'A repoint is running.')
            ->and($seen['steps'])->toBe(0)
            ->and(cli_messages('error'))->toBe(['A repoint is running.'])
            ->and(cli_messages('success'))->toBe([]);
    });

    it('does not step at all when the scan starts out done', function () use ($scan): void {
        $seen = $scan(['step' => 'done', 'message' => 'Nothing to do'], []);

        expect($seen['steps'])->toBe(0)
            ->and(cli_messages('log'))->toBe([])
            ->and(cli_messages('success'))->toHaveCount(1);
    });

    it('logs a message once while it stays the same and again when it comes back', function () use ($scan): void {
        $scan(
            ['step' => 'posts', 'message' => ''],
            [
                ['step' => 'posts', 'message' => 'A'],
                ['step' => 'posts', 'message' => 'A'],
                ['step' => 'posts', 'message' => 'B'],
                ['step' => 'posts', 'message' => 'A'],
                ['step' => 'done', 'message' => 'A'],
            ]
        );

        expect(cli_messages('log'))->toBe(['A', 'B', 'A']);
    });

    it('does not log an empty first message', function () use ($scan): void {
        $scan(['step' => 'posts', 'message' => ''], [['step' => 'posts', 'message' => ''], ['step' => 'done', 'message' => '']]);

        expect(cli_messages('log'))->toBe([]);
    });

    it('stays quiet with the quiet flag, whatever its value, but still reports the summary', function (mixed $value) use ($scan): void {
        $scan(['step' => 'posts', 'message' => 'x'], [['step' => 'posts', 'message' => 'Posts'], ['step' => 'done', 'message' => 'Done']], ['quiet' => $value]);

        expect(cli_messages('log'))->toBe([])
            ->and(cli_messages('success'))->toHaveCount(1);
    })->with([true, '', 0, false]);

    it('reports each count from the summary', function () use ($scan): void {
        $scan(['step' => 'done', 'message' => ''], [], [], ['total' => 1, 'unattached' => 2, 'unreferenced' => 3, 'orphaned' => 4, 'orphaned_bytes' => 5]);

        expect(cli_messages('success'))->toBe(['1 media files. 2 unattached, 3 unreferenced, 4 both (5B@1 reclaimable).']);
    });
});

describe('list', function (): void {
    beforeEach(function (): void {
        cli_store('T');
    });

    /**
     * Run the list command over rows and return what it asked the database.
     *
     * @param  list<array<string, mixed>>|null  $rows
     * @param  array<string, mixed>  $assoc
     * @return object{queries: list<string>, outputs: list<mixed>, deletes: list<array{string, array<string, int>, array<string>}>}
     */
    $list = static function (?array $rows, array $assoc = []): object {
        $wpdb = cli_wpdb($rows);
        $GLOBALS['wpdb'] = $wpdb;

        (new UAMS_MRA_CLI)->list([], $assoc);

        return $wpdb;
    };

    $row = static fn (array $over = []): array => array_merge([
        'attachment_id' => '7',
        'file' => '2024/05/a.jpg',
        'bytes' => '2048',
        'parent_id' => '3',
        'ref_count' => '2',
        'ref_count_strict' => '1',
        'post_date' => '2024-05-01 10:00:00',
    ], $over);

    it('asks for the orphaned rows, largest first, 100 at most, as associative arrays, by default', function () use ($list): void {
        $wpdb = $list([]);

        expect($wpdb->queries)->toBe(['SELECT * FROM T WHERE ref_count = 0 AND is_attached = 0 ORDER BY bytes DESC LIMIT 100'])
            ->and($wpdb->outputs)->toBe(['ARRAY_A']);
    });

    it('filters by the named status', function (string $status, string $where) use ($list): void {
        $wpdb = $list([], ['status' => $status]);

        expect($wpdb->queries)->toBe(['SELECT * FROM T WHERE '.$where.' ORDER BY bytes DESC LIMIT 100']);
    })->with([
        ['all', '1=1'],
        ['orphaned', 'ref_count = 0 AND is_attached = 0'],
        ['unreferenced', 'ref_count = 0'],
        ['unattached', 'is_attached = 0'],
        ['weak', 'ref_count > 0 AND ref_count_strict = 0'],
        ['referenced', 'ref_count > 0'],
        'an unknown status falls back to orphaned' => ['bogus', 'ref_count = 0 AND is_attached = 0'],
    ]);

    it('takes the limit as a number', function () use ($list): void {
        expect($list([], ['limit' => '25abc'])->queries[0])->toEndWith('LIMIT 25');
    });

    it('says so when nothing matched, for no rows and for a failed read', function (?array $rows) use ($list): void {
        $list($rows);

        expect(cli_messages('success'))->toBe(['Nothing matched that filter.'])
            ->and($GLOBALS['cli_format_calls'])->toBe([])
            ->and(cli_messages('line'))->toBe([]);
    })->with([[[]], [null]]);

    it('prints only the ids on one line for the ids format', function () use ($list, $row): void {
        $list([$row(['attachment_id' => '7']), $row(['attachment_id' => '9']), $row(['attachment_id' => '11'])], ['format' => 'ids']);

        expect(cli_messages('line'))->toBe(['7 9 11'])
            ->and($GLOBALS['cli_format_calls'])->toBe([]);
    });

    it('hands the rows to the table formatter with the column set, by default as a table', function () use ($list, $row): void {
        $list([$row()]);

        expect($GLOBALS['cli_format_calls'])->toBe([[
            'table',
            [['id' => '7', 'file' => '2024/05/a.jpg', 'size' => '2048B@1', 'attached' => '3', 'refs' => '2', 'strong' => '1', 'uploaded' => '2024-05-01']],
            ['id', 'file', 'size', 'attached', 'refs', 'strong', 'uploaded'],
        ]])
            ->and(cli_messages('line'))->toBe([]);
    });

    it('passes the chosen format through', function (string $format) use ($list, $row): void {
        $list([$row()], ['format' => $format]);

        expect(cli_format_calls()[0][0])->toBe($format);
    })->with(['csv', 'json', 'table']);

    it('leaves the attached column blank for a file with no parent', function (mixed $parent) use ($list, $row): void {
        $list([$row(['parent_id' => $parent])]);

        expect(cli_format_calls()[0][1][0]['attached'])->toBe('');
    })->with(['0', 0, '', null]);

    it('keeps one output row per result row, in order', function () use ($list, $row): void {
        $list([$row(['attachment_id' => '1']), $row(['attachment_id' => '2'])]);

        expect(array_column(cli_format_calls()[0][1], 'id'))->toBe(['1', '2']);
    });
});

describe('delete', function (): void {
    beforeEach(function (): void {
        cli_store('T');
    });

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{object{queries: list<string>, outputs: list<mixed>, deletes: list<array{string, array<string, int>, array<string>}>}, object{ticks: int, finished: int}}
     */
    $setup = static function (array $rows): array {
        $wpdb = cli_wpdb($rows);
        $GLOBALS['wpdb'] = $wpdb;
        $bar = cli_bar();
        when('WP_CLI\Utils\make_progress_bar')->alias(static function ($label, $count) use ($bar): object {
            $GLOBALS['cli_bar_args'] = [$label, $count];

            return $bar;
        });

        return [$wpdb, $bar];
    };

    $rows = static fn (): array => [
        ['attachment_id' => '7', 'bytes' => '1000', 'file' => 'a.jpg'],
        ['attachment_id' => '9', 'bytes' => '500', 'file' => 'b.pdf'],
    ];

    it('refuses any status but orphaned and unreferenced before touching the database', function (string $status) use ($setup): void {
        [$wpdb] = $setup([]);

        expect(fn () => (new UAMS_MRA_CLI)->delete([], ['status' => $status]))->toThrow(CliHalt::class, 'Refusing to delete anything outside the orphaned or unreferenced sets.')
            ->and($wpdb->queries)->toBe([])
            ->and(cli_messages('error'))->toBe(['Refusing to delete anything outside the orphaned or unreferenced sets.']);
    })->with(['all', 'unattached', 'weak', 'referenced', 'bogus', '']);

    it('asks for the orphaned rows by default, largest first, 500 at most, and also requires no references', function () use ($setup): void {
        [$wpdb] = $setup([]);

        (new UAMS_MRA_CLI)->delete([], []);

        expect($wpdb->queries)->toBe(['SELECT attachment_id, bytes, file FROM T WHERE ref_count = 0 AND is_attached = 0 AND ref_count = 0 ORDER BY bytes DESC LIMIT 500'])
            ->and($wpdb->outputs)->toBe(['ARRAY_A']);
    });

    it('asks for the unreferenced rows when told to, with the limit as a number', function () use ($setup): void {
        [$wpdb] = $setup([]);

        (new UAMS_MRA_CLI)->delete([], ['status' => 'unreferenced', 'limit' => '40x']);

        expect($wpdb->queries)->toBe(['SELECT attachment_id, bytes, file FROM T WHERE ref_count = 0 AND ref_count = 0 ORDER BY bytes DESC LIMIT 40']);
    });

    it('says there is nothing to delete, without asking anyone, for no rows and for a failed read', function (?array $found) use ($setup): void {
        $setup($found ?? []);
        $GLOBALS['wpdb'] = cli_wpdb($found);

        (new UAMS_MRA_CLI)->delete([], []);

        expect(cli_calls())->toBe([['success', 'Nothing to delete.']]);
    })->with([[[]], [null]]);

    it('lists what would go and stops on a dry run, whatever the flag holds', function (mixed $flag) use ($setup, $rows): void {
        [$wpdb] = $setup($rows());
        when('wp_delete_attachment')->alias(static function (): never {
            throw new RuntimeException('a dry run deleted something');
        });

        /** @var bool|string $dry */
        $dry = $flag;

        (new UAMS_MRA_CLI)->delete([], ['dry-run' => $dry]);

        expect(cli_calls())->toBe([
            ['log', "7\ta.jpg\t1000B@1"],
            ['log', "9\tb.pdf\t500B@1"],
            ['success', 'Dry run. 2 files, 1500B@1 would be freed.'],
        ])
            ->and($wpdb->deletes)->toBe([]);
    })->with([true, '', 0]);

    it('asks before deleting for real, with the count and the size, and passes the flags on', function () use ($setup, $rows): void {
        $setup($rows());
        when('wp_delete_attachment')->justReturn(true);

        (new UAMS_MRA_CLI)->delete([], ['yes' => true, 'status' => 'orphaned']);

        expect(cli_calls()[0])->toBe(['confirm', ['Permanently delete 2 files and free 1500B@1?', ['yes' => true, 'status' => 'orphaned']]]);
    });

    it('deletes nothing when the confirmation is declined', function () use ($setup, $rows): void {
        [$wpdb] = $setup($rows());
        CliStandIn::$decline = true;
        when('wp_delete_attachment')->alias(static function (): never {
            throw new RuntimeException('deleted without consent');
        });

        expect(fn () => (new UAMS_MRA_CLI)->delete([], []))->toThrow(CliHalt::class, 'declined')
            ->and($wpdb->deletes)->toBe([])
            ->and(cli_messages('success'))->toBe([]);
    });

    it('deletes each file for good, drops its row, ticks the bar and reports the total', function () use ($setup, $rows): void {
        [$wpdb, $bar] = $setup($rows());
        $deleted = [];
        when('wp_delete_attachment')->alias(static function ($id, $force) use (&$deleted): bool {
            $deleted[] = [$id, $force];

            return true;
        });

        (new UAMS_MRA_CLI)->delete([], []);

        expect($deleted)->toBe([[7, true], [9, true]])
            ->and($wpdb->deletes)->toBe([['T', ['attachment_id' => 7], ['%d']], ['T', ['attachment_id' => 9], ['%d']]])
            ->and($GLOBALS['cli_bar_args'])->toBe(['Deleting', 2])
            ->and($bar->ticks)->toBe(2)
            ->and($bar->finished)->toBe(1)
            ->and(cli_messages('success'))->toBe(['Deleted 2 files, freed 1500B@1.']);
    });

    it('does not count or drop the row of a file WordPress would not delete, but still ticks past it', function () use ($setup, $rows): void {
        [$wpdb, $bar] = $setup($rows());
        when('wp_delete_attachment')->alias(static fn ($id): bool => $id === 9);

        (new UAMS_MRA_CLI)->delete([], []);

        expect($wpdb->deletes)->toBe([['T', ['attachment_id' => 9], ['%d']]])
            ->and($bar->ticks)->toBe(2)
            ->and(cli_messages('success'))->toBe(['Deleted 1 files, freed 1500B@1.']);
    });

    it('deletes nothing and reports zero when WordPress refuses every file', function () use ($setup, $rows): void {
        [$wpdb] = $setup($rows());
        when('wp_delete_attachment')->justReturn(false);

        (new UAMS_MRA_CLI)->delete([], []);

        expect($wpdb->deletes)->toBe([])
            ->and(cli_messages('success'))->toBe(['Deleted 0 files, freed 1500B@1.']);
    });
});

describe('why', function (): void {
    $why = static function (array $args, array $refs): array {
        $asked = [];
        cli_redefine('UAMS_MRA_Store::get_refs', static function ($id, $limit) use (&$asked, $refs): array {
            $asked = [$id, $limit];

            return $refs;
        });

        // Every caller passes the positional arguments as a list of strings.
        /** @var list<string> $args */
        (new UAMS_MRA_CLI)->why($args);

        return $asked;
    };

    it('warns when nothing is recorded, and shows no table', function () use ($why): void {
        $asked = $why(['12abc'], []);

        expect($asked)->toBe([12, 100])
            ->and(cli_calls())->toBe([['warning', 'No references recorded for attachment 12.']])
            ->and($GLOBALS['cli_format_calls'])->toBe([]);
    });

    it('shows each reference as a table row', function () use ($why): void {
        $asked = $why(['5'], [
            (object) ['source_type' => 'content', 'source_id' => '3', 'source_label' => 'Page A', 'confidence' => '2', 'site_id' => '0'],
            (object) ['source_type' => 'postmeta', 'source_id' => '4', 'source_label' => 'Meta B', 'confidence' => '1', 'site_id' => '6'],
            (object) ['source_type' => 'option', 'source_id' => '0', 'source_label' => 'Opt C', 'confidence' => '3', 'site_id' => ''],
        ]);

        expect($asked)->toBe([5, 100])
            ->and($GLOBALS['cli_format_calls'])->toBe([[
                'table',
                [
                    ['type' => 'content', 'source' => '3', 'label' => 'Page A', 'confidence' => 'strong', 'site' => ''],
                    ['type' => 'postmeta', 'source' => '4', 'label' => 'Meta B', 'confidence' => 'by ID', 'site' => '6'],
                    ['type' => 'option', 'source' => '0', 'label' => 'Opt C', 'confidence' => 'strong', 'site' => ''],
                ],
                ['type', 'source', 'label', 'confidence', 'site'],
            ]])
            ->and(cli_calls())->toBe([]);
    });

    it('calls a confidence of exactly 2 strong and below it by ID', function (): void {
        cli_redefine('UAMS_MRA_Store::get_refs', static fn (): array => [
            (object) ['source_type' => 't', 'source_id' => '1', 'source_label' => 'l', 'confidence' => '1', 'site_id' => '0'],
            (object) ['source_type' => 't', 'source_id' => '1', 'source_label' => 'l', 'confidence' => '2', 'site_id' => '0'],
        ]);

        (new UAMS_MRA_CLI)->why(['1']);

        expect(array_column(cli_format_calls()[0][1], 'confidence'))->toBe(['by ID', 'strong']);
    });
});

describe('where_for', function (): void {
    it('maps each keyword to its fragment and anything else to the orphaned one', function (mixed $status, string $where): void {
        $method = new ReflectionMethod(UAMS_MRA_CLI::class, 'where_for');

        expect($method->invoke(null, $status))->toBe($where);
    })->with([
        ['all', '1=1'],
        ['orphaned', 'ref_count = 0 AND is_attached = 0'],
        ['unreferenced', 'ref_count = 0'],
        ['unattached', 'is_attached = 0'],
        ['weak', 'ref_count > 0 AND ref_count_strict = 0'],
        ['referenced', 'ref_count > 0'],
        ['bogus', 'ref_count = 0 AND is_attached = 0'],
        ['', 'ref_count = 0 AND is_attached = 0'],
    ]);
});
