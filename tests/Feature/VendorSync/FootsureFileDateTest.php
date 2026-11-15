<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Lunar\Models\Product;
use Tests\Support\VendorSync;

/*
 * The real Footsure feed dates its files month/day/year: 11/15/2026 06:05:26.
 * Read as day/month/year that is "month 15", which PHP rolls over into
 * March 2027 without an error. A date in the future always looked fresh, so
 * the staleness rule could never fire.
 */

beforeEach(function (): void {
    VendorSync::boot();
    VendorSync::fakeHttp();
});

function fileWithDate(string $date): void
{
    VendorSync::footsureFile(fn (array $rows): array => array_map(function (array $row) use ($date): array {
        $row['File_Create_Date'] = $date;

        return $row;
    }, array_slice($rows, 0, 8)));
}

it('accepts a month/day/year date created a few hours ago', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-15 17:00:00', 'Europe/London'));
    fileWithDate('11/15/2026 06:05:26');

    $report = VendorSync::import('FS');

    expect($report->status)->toBe('succeeded')
        ->and(Product::query()->count())->toBeGreaterThan(0);
});

it('rejects a stale month/day/year date, which the old reading accepted as the future', function (): void {
    // Two days later. Read as day/month/year the file would be dated March 2027.
    Carbon::setTestNow(Carbon::parse('2026-11-17 18:00:00', 'Europe/London'));
    fileWithDate('11/15/2026 06:05:26');

    $report = VendorSync::import('FS');

    expect($report->status)->toBe('aborted')
        ->and($report->messages[0])->toContain('Stale Footsure feed')
        ->and($report->messages[0])->toContain('11/15/2026 06:05:26')
        ->and(Product::query()->count())->toBe(0);
});

it('rejects a date in the future instead of treating it as fresh', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-15 17:00:00', 'Europe/London'));
    fileWithDate('11/20/2026 06:05:26');

    $report = VendorSync::import('FS');

    expect($report->status)->toBe('aborted')
        ->and($report->messages[0])->toContain('is in the future')
        ->and($report->messages[0])->toContain('Check the date format')
        ->and(Product::query()->count())->toBe(0);
});

it('allows a small clock difference ahead of now', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-15 06:00:00', 'Europe/London'));
    fileWithDate('11/15/2026 06:05:26');

    expect(VendorSync::import('FS')->status)->toBe('succeeded');
});

it('rejects a date that is only valid as day/month/year, and other malformed dates', function (string $date): void {
    Carbon::setTestNow(Carbon::parse('2026-11-15 17:00:00', 'Europe/London'));
    fileWithDate($date);

    $report = VendorSync::import('FS');

    expect($report->status)->toBe('aborted')
        ->and($report->messages[0])->toContain('unreadable File_Create_Date')
        ->and($report->messages[0])->toContain("'{$date}'")
        ->and($report->messages[0])->toContain('m/d/Y H:i:s')
        ->and(Product::query()->count())->toBe(0);
})->with([
    'day first' => '25/12/2026 06:05:26',
    'month 15 read as March' => '15/11/2026 06:05:26',
    'day 40' => '11/40/2026 06:05:26',
    'ISO' => '2026-11-15 06:05:26',
    'blank' => '',
    'no time' => '11/15/2026',
]);

it('uses the format from config', function (): void {
    config()->set('vendorsync.footsure.file_date_format', 'd/m/Y H:i:s');
    Carbon::setTestNow(Carbon::parse('2026-11-15 17:00:00', 'Europe/London'));
    fileWithDate('15/11/2026 06:05:26');

    expect(VendorSync::import('FS')->status)->toBe('succeeded');
});

it('reads the fixture file date, which is valid in the real format', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 07:20:00', 'Europe/London'));
    VendorSync::footsureFile();

    expect(VendorSync::import('FS')->status)->toBe('succeeded');
});
