<?php

/**
 * UAMS_MRA_Triage::tier(), with WordPress mocked.
 *
 * @see includes/class-triage.php
 */

declare(strict_types=1);

it('puts an attached file in the verify tier whatever its type', function (string $mime): void {
    expect(UAMS_MRA_Triage::tier(['is_attached' => 1, 'mime' => $mime]))->toBe('verify');
})->with([
    'image' => ['image/jpeg'],
    'document' => ['application/pdf'],
    'video' => ['video/mp4'],
]);

it('asks the team about an unattached file that travels outside the site', function (string $mime): void {
    expect(UAMS_MRA_Triage::tier(['is_attached' => 0, 'mime' => $mime]))->toBe('ask');
})->with([
    'pdf' => ['application/pdf'],
    'spreadsheet' => ['application/vnd.ms-excel'],
    'video' => ['video/mp4'],
    'audio' => ['audio/mpeg'],
]);

it('treats an unattached image as low risk', function (): void {
    expect(UAMS_MRA_Triage::tier(['is_attached' => 0, 'mime' => 'image/png']))->toBe('clear');
});

it('treats a row with no mime type as low risk', function (): void {
    expect(UAMS_MRA_Triage::tier(['is_attached' => 0]))->toBe('clear');
});

it('lets a filter add a type to the ask tier', function (): void {
    Brain\Monkey\Filters\expectApplied('uams_mra_distributable_mimes')
        ->once()
        ->andReturn(['image/png']);

    expect(UAMS_MRA_Triage::tier(['is_attached' => 0, 'mime' => 'image/png']))->toBe('ask');
});
