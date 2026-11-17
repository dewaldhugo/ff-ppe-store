<?php

declare(strict_types=1);

use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\SafetyJogger\ResponseHeaders;
use App\Domain\VendorSync\SafetyJogger\SafetyJoggerScope;
use Tests\Support\VendorSync;

beforeEach(fn () => VendorSync::boot());

/**
 * The scope rule was moved out of the mapper into SafetyJoggerScope so that the
 * source can use it too. These tests pin that move as behaviour-preserving: the
 * mapper (which now delegates) and the extracted class agree on every product
 * below, and the recorded catalogue still imports the same in-scope and out of
 * scope sets as before the change.
 */

/**
 * @param  list<array{name: string, value: string}>  $articleTypes
 * @param  list<string>  $norms
 * @return array<string, mixed>
 */
function scopeDetail(array $articleTypes, array $norms): array
{
    $detail = VendorSync::detail(910001, 'SCOPEBOOT', 'Safety Jogger', 'Shoes', $norms);
    $detail['articletype'] = $articleTypes;

    return $detail;
}

it('decides scope exactly as the mapper does', function (array $articleTypes, array $norms, ?string $outOfScope): void {
    $detail = scopeDetail($articleTypes, $norms);

    $mapped = app('vendorsync.mapper.SJ')->map(new RawRecord('910001', $detail));
    $scope = new SafetyJoggerScope(config('vendorsync.safety_jogger'));

    expect($mapped->outOfScope)->toBe($outOfScope)
        ->and($scope->inScope($detail))->toBe($outOfScope === null);
})->with([
    'shoes, EN ISO 20345' => [[['name' => 'shoes', 'value' => 'Shoes']], ['EN ISO 20345:2022'], null],
    'shoes, EN ISO 20347' => [[['name' => 'shoes', 'value' => 'Shoes']], ['EN ISO 20347:2012'], null],
    'shoes, both norms' => [[['name' => 'shoes', 'value' => 'Shoes']], ['EN ISO 20347:2012', 'EN ISO 20345:2022'], null],
    'article type in capitals' => [[['name' => 'SHOES', 'value' => 'Shoes']], ['EN ISO 20345:2022'], null],
    'article type with spaces' => [[['name' => ' shoes ', 'value' => 'Shoes']], ['EN ISO 20345:2022'], null],
    'norm with spaces and an amendment' => [[['name' => 'shoes', 'value' => 'Shoes']], ['  EN ISO 20345:2022+A1:2024 '], null],
    'second article type is in scope' => [[['name' => 'workwear', 'value' => 'Workwear'], ['name' => 'shoes', 'value' => 'Shoes']], ['EN ISO 20345:2022'], null],
    // workwear, gloves and the rest need no norm at all: only shoes do.
    'workwear, no norms' => [[['name' => 'workwear', 'value' => 'Workwear']], [], null],
    'gloves, no norms' => [[['name' => 'gloves', 'value' => 'Gloves']], [], null],
    'head protection, no norms' => [[['name' => 'head_protection', 'value' => 'Head protection']], [], null],
    'fall protection, no norms' => [[['name' => 'fall_protection', 'value' => 'Fall protection']], [], null],
    'overshoes, no norms' => [[['name' => 'overshoes', 'value' => 'Overshoes']], [], null],
    'accessories, no norms' => [[['name' => 'accessories', 'value' => 'Accessories']], [], null],
    'an article type not on the list' => [[['name' => 'promotional', 'value' => 'Promotional']], ['EN ISO 20345:2022'], 'article_type'],
    'no article type' => [[], ['EN ISO 20345:2022'], 'article_type'],
    'blank article type name' => [[['name' => '  ', 'value' => 'Shoes']], ['EN ISO 20345:2022'], 'article_type'],
    'shoes, no norms' => [[['name' => 'shoes', 'value' => 'Shoes']], [], 'no_en_iso_norm'],
    'shoes, another norm' => [[['name' => 'shoes', 'value' => 'Shoes']], ['EN ISO 20346:2014'], 'no_en_iso_norm'],
    'shoes, lower case norm' => [[['name' => 'shoes', 'value' => 'Shoes']], ['en iso 20345'], 'no_en_iso_norm'],
]);

it('still imports the recorded catalogue with the same in-scope and out of scope sets', function (): void {
    VendorSync::fakeHttp(VendorSync::json('SafetyJogger/product-list.json'), [
        284096 => VendorSync::json('SafetyJogger/product-284096.json'),
        279074 => VendorSync::json('SafetyJogger/product-279074.json'),
        215754 => VendorSync::json('SafetyJogger/product-215754.json'),
        407407 => VendorSync::detail(407407, '2081', 'Safety Jogger', 'Shoes', ['EN ISO 20345:2022'], cost: 290.6, rrp: 0.0),
        388610 => VendorSync::detail(388610, 'ALKSHORT2', 'Safety Workwear', 'Workwear', ['EN ISO 13688']),
        360862 => VendorSync::detail(360862, 'BOGOTA', 'Safety Jogger Adventure', 'Shoes', []),
        296233 => VendorSync::detail(296233, 'ALIZA', 'Oxypas', 'Shoes', ['EN ISO 20347:2012'], cost: 9.9, rrp: 55.1),
    ]);

    $report = VendorSync::import('SJ', dryRun: true);

    // 388610 (Workwear) is now in scope on its own (no norm needed): created
    // and drafted, since this minimal fixture gives it no workwear category.
    expect($report->seen)->toBe(7)
        ->and($report->outOfScope)->toBe(1)
        ->and($report->tallies['out_of_scope'])->toBe(['no_en_iso_norm' => 1])
        ->and($report->currencyRefetches)->toBe(0)
        // No product in the recorded catalogue is retried: no extra request is made.
        ->and($report->counts())->toMatchArray(['seen' => 7, 'out_of_scope' => 1, 'created' => 6]);
});

it('keeps only allow-listed response headers and never one that could carry a credential', function (): void {
    $kept = ResponseHeaders::allowed([
        'Date' => 'x', 'Server' => 'y', 'Via' => 'v', 'Age' => '1', 'Cache-Control' => 'no-store', 'ETag' => 'e',
        'X-Cache' => 'MISS', 'X-Request-Id' => 'r', 'X-Amzn-RequestId' => 'a', 'CF-Ray' => 'c', 'X-Served-By' => 's',
        'X-Region' => 'eu', 'X-Currency' => 'EUR', 'X-Country' => 'GB', 'Content-Type' => 'application/json',
        'Content-Length' => '10', 'Set-Cookie' => 'c=1', 'Authorization' => 'a', 'X-Auth-Token' => 't',
        'X-Api-Key' => 'k', 'X-Secret' => 's', 'Username' => 'u', 'X-Session-Region' => 'r', 'Cookie-Region' => 'x',
    ]);

    expect(array_keys($kept))->toBe([
        'age', 'cache-control', 'cf-ray', 'date', 'etag', 'server', 'via', 'x-amzn-requestid', 'x-cache',
        'x-country', 'x-currency', 'x-region', 'x-request-id', 'x-served-by',
    ]);
});

it('cuts long header values short', function (): void {
    expect(mb_strlen(ResponseHeaders::allowed(['Via' => str_repeat('a', 1000)])['via']))->toBe(200);
});
