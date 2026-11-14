<?php

/**
 * The tier of a file whose parent page has been read, with WordPress mocked.
 *
 * Only a fetched page that does not display the file settles the question the
 * verify tier asks. A page that could not be read must never read as "unused".
 *
 * @see includes/class-triage.php
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\when;

it('drops an attached image to low risk once its page was read and does not show it', function (): void {
    expect(UAMS_MRA_Triage::tier(['is_attached' => 1, 'mime' => 'image/jpeg', 'render_state' => 'absent']))->toBe('clear');
});

it('drops an attached document to the ask tier once its page was read and does not show it', function (): void {
    expect(UAMS_MRA_Triage::tier(['is_attached' => 1, 'mime' => 'application/pdf', 'render_state' => 'absent']))->toBe('ask');
});

it('keeps an attached file in verify when the page request failed or was skipped', function (string $state): void {
    expect(UAMS_MRA_Triage::tier(['is_attached' => 1, 'mime' => 'image/jpeg', 'render_state' => $state]))->toBe('verify');
})->with([
    'error' => ['error'],
    'skipped' => ['skipped'],
    'offsite' => ['offsite'],
    'not yet read' => [''],
]);

it('selects tiers in SQL the same way tier() selects them in PHP', function (): void {
    when('esc_sql')->returnArg();

    expect(UAMS_MRA_Triage::tier_where('verify'))->toBe("is_attached = 1 AND render_state <> 'absent'")
        ->and(UAMS_MRA_Triage::tier_where('clear'))->toStartWith("( is_attached = 0 OR render_state = 'absent' ) AND NOT ")
        ->and(UAMS_MRA_Triage::tier_where('ask'))->toStartWith("( is_attached = 0 OR render_state = 'absent' ) AND ")
        ->and(UAMS_MRA_Triage::tier_where('anything else'))->toBe('1=1');
});
