<?php

/**
 * UAMS_MRA_Repoint reading stored values safely, and the URL-still-present check.
 *
 * A stored value can name any class. The rewrite must read it without building
 * one, and must say so when a URL sits somewhere it cannot reach, rather than
 * report the row as having nothing to do.
 *
 * @see includes/class-repoint.php
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\when;

if (! class_exists('UamsMraWakeProbe')) {
    /**
     * Records whether it was ever built by unserialize().
     */
    final class UamsMraWakeProbe
    {
        public static bool $woken = false;

        public string $url = 'https://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf';

        public function __wakeup(): void
        {
            self::$woken = true;
        }
    }
}

beforeEach(function (): void {
    UamsMraWakeProbe::$woken = false;

    when('is_serialized')->alias(static fn ($value): bool => is_string($value) && preg_match('/^(?:N;|[bidsaOCE]:)/', trim($value)) === 1);
});

it('does not build the class a stored value names', function (): void {
    $stored = serialize(['probe' => new UamsMraWakeProbe, 'url' => 'https://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf']);

    UAMS_MRA_Repoint::replace_variants($stored, 'https://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf', 'https://new.example.edu/r.pdf');

    expect(UamsMraWakeProbe::$woken)->toBeFalse();
});

it('rewrites the strings around an object it cannot read and hands the object back byte for byte', function (): void {
    $old = 'https://old.example.edu/sites/x/wp-content/uploads/2020/01/report.pdf';
    $stored = serialize(['url' => $old, 'probe' => new UamsMraWakeProbe]);

    $result = UAMS_MRA_Repoint::replace_variants($stored, $old, 'https://new.example.edu/r.pdf');

    expect($result)->toBe(str_replace(
        's:'.strlen($old).':"'.$old.'";s:5:"probe"',
        's:'.strlen('https://new.example.edu/r.pdf').':"https://new.example.edu/r.pdf";s:5:"probe"',
        $stored
    ))->and(UAMS_MRA_Repoint::holds_url($result, $old))->toBeTrue();
});

it('still rewrites a stdClass, which has no methods to run', function (): void {
    $old = 'https://old.example.edu/a.jpg';
    $object = new stdClass;
    $object->src = $old;

    $result = UAMS_MRA_Repoint::replace_variants(serialize($object), $old, 'https://new.example.edu/a.jpg');

    expect(unserialize($result)->src)->toBe('https://new.example.edu/a.jpg');
});

it('says a URL is still held when it sits in text, in a variant form or in a structure', function (mixed $value): void {
    expect(UAMS_MRA_Repoint::holds_url($value, 'https://old.example.edu/a.jpg'))->toBeTrue();
})->with([
    'plain text' => ['see https://old.example.edu/a.jpg now'],
    'the http form' => ['http://old.example.edu/a.jpg'],
    'the scheme-less form' => ['//old.example.edu/a.jpg'],
    'the slash-escaped form' => ['https:\\/\\/old.example.edu\\/a.jpg'],
    'a serialized value' => [serialize(['x' => 'https://old.example.edu/a.jpg'])],
    'an array that arrived unserialized' => [['x' => 'https://old.example.edu/a.jpg']],
]);

it('says a URL is not held when it is absent or only the front of a longer one', function (mixed $value): void {
    expect(UAMS_MRA_Repoint::holds_url($value, 'https://old.example.edu/a.jpg'))->toBeFalse();
})->with([
    'nothing' => [''],
    'another file' => ['https://old.example.edu/b.jpg'],
    'a longer name' => ['https://old.example.edu/a.jpg.webp'],
    'an array without it' => [['x' => 'y']],
]);

it('does not call a stored false or a plain string unreadable', function (mixed $value): void {
    expect(UAMS_MRA_Repoint::is_unreadable($value))->toBeFalse();
})->with(['b:0;', ' b:0; ', 'plain']);

it('calls a serialized value that cannot be read unreadable, and leaves it alone', function (): void {
    $corrupt = 'a:1:{s:3:"url";s:5:"https://old.example.edu/a.jpg";}';

    expect(UAMS_MRA_Repoint::is_unreadable($corrupt))->toBeTrue()
        ->and(UAMS_MRA_Repoint::replace_variants($corrupt, 'https://old.example.edu/a.jpg', 'https://new.example.edu/a.jpg'))->toBe($corrupt);
});

it('says in the report that an identical match is by name and size, not by content', function (): void {
    when('__')->returnArg(1);

    expect(UAMS_MRA_Repoint::verdicts()['identical'])->toContain('same name and the same size')
        ->and(UAMS_MRA_Repoint::verdicts()['identical'])->toContain('not compared');
});
