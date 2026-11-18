<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

/**
 * The industries a customer picks from when registering. The slug is what is stored
 * (Lunar customer meta, `industry`); the label is what is shown.
 */
final class Industries
{
    private const array LIST = [
        'assembly' => 'Assembly',
        'automotive' => 'Automotive',
        'catering' => 'Catering and hospitality',
        'chemical' => 'Chemical',
        'cleaning' => 'Cleaning',
        'construction' => 'Construction',
        'food-and-beverage' => 'Food and beverage',
        'logistics' => 'Logistics and warehousing',
        'manufacturing' => 'Manufacturing and production',
        'medical' => 'Medical and healthcare',
        'mining' => 'Mining',
        'oil-and-gas' => 'Oil and gas',
        'security' => 'Security and tactical',
        'uniform' => 'Uniform and workwear',
        'other' => 'Other',
    ];

    /**
     * @return array<string, string> Slug => label, in display order.
     */
    public static function all(): array
    {
        return self::LIST;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::LIST);
    }

    public static function label(?string $slug): ?string
    {
        return $slug === null ? null : (self::LIST[$slug] ?? null);
    }
}
