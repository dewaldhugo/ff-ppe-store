<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Collections;

/**
 * The collections the data contract names (docs/data-contract.md,
 * Collections): the customer-facing category tree and the `clearance` range.
 *
 * The one definition. The foundation seeder creates what is missing from it,
 * and `catalogue:restructure-categories` moves a database that was seeded
 * with an earlier tree into this shape. Order here is the menu order.
 */
final class CategoryTree
{
    public const string CATEGORIES = 'categories';

    public const string RANGES = 'ranges';

    /** Collection group handle => [group name, tree of handle => [name, children]]. */
    public const array GROUPS = [
        self::CATEGORIES => ['Categories', [
            'safety-footwear' => ['Safety Footwear', [
                'safety-boots' => ['Safety Boots', []],
                'safety-shoes' => ['Safety Shoes', []],
                'safety-trainers' => ['Safety Trainers', []],
                'safety-wellingtons' => ['Safety Wellingtons', []],
                'occupational-footwear' => ['Occupational Footwear', []],
                'metal-free-metatarsal' => ['Metal-Free & Metatarsal', []],
            ]],
            'non-safety-footwear' => ['Non-Safety Footwear', [
                'casual-footwear' => ['Casual, Formal & Work Shoes', []],
                'boots' => ['Boots', []],
                'sandals-summer-shoes' => ['Sandals & Summer Shoes', []],
                'sports-trainers' => ['Sports & Trainers', []],
                'hiking-outdoor' => ['Hiking & Outdoor', []],
                'wellingtons' => ['Wellingtons & Waders', []],
                'slippers' => ['Slippers', []],
                'plimsolls' => ['Plimsolls', []],
            ]],
            'apparel' => ['Apparel', [
                'jackets-fleeces' => ['Jackets, Body Warmers & Fleeces', []],
                'trousers-shorts' => ['Trousers & Shorts', []],
                'tshirts-polos-shirts' => ['T-shirts, Polos & Shirts', []],
                'sweatshirts-hoodies' => ['Sweatshirts & Hoodies', []],
                'activewear-loungewear' => ['Activewear & Loungewear', []],
                'base-layers' => ['Base Layers', []],
                'coveralls' => ['Coveralls', []],
            ]],
            'ppe-gear' => ['PPE & Gear', [
                'gloves' => ['Gloves', []],
                'eye-face-protection' => ['Eye & Face Protection', []],
                'fall-protection' => ['Fall Protection', []],
                'overshoes' => ['Overshoes', []],
                'hard-hats-helmets' => ['Hard Hats & Helmets', []],
                'hearing-protection' => ['Hearing Protection', []],
                'respiratory-protection' => ['Respiratory Protection', []],
                'other-ppe' => ['Other PPE', []],
            ]],
            'accessories' => ['Accessories', [
                'socks' => ['Socks', []],
                'hats-beanies-scarves' => ['Hats, Beanies & Scarves', []],
                'insoles-laces-shoe-care' => ['Insoles, Laces & Shoe Care', []],
                'bags-wallets' => ['Bags & Wallets', []],
                'sunglasses' => ['Sunglasses', []],
                'other-accessories' => ['Other Accessories', []],
            ]],
        ]],
        self::RANGES => ['Ranges', [
            'clearance' => ['Clearance', []],
        ]],
    ];

    /**
     * Handles whose contract name changed: handle => the name it was seeded
     * with before. A collection still carrying that old name is renamed by
     * the restructure command; a name edited in the admin panel is left alone.
     */
    public const array RENAMED_FROM = [
        'wellingtons' => 'Wellingtons',
        'casual-footwear' => 'Casual Footwear',
    ];

    /**
     * Every handle in the categories group with its parent handle (null for a
     * top-level category), in menu order.
     *
     * @return array<string, ?string>
     */
    public static function parents(): array
    {
        $parents = [];

        foreach (self::GROUPS[self::CATEGORIES][1] as $handle => [, $children]) {
            $parents[$handle] = null;

            foreach (array_keys($children) as $child) {
                $parents[$child] = $handle;
            }
        }

        return $parents;
    }

    /**
     * The handles of the leaf categories: the only ones a product is put in.
     *
     * @return list<string>
     */
    public static function leaves(): array
    {
        return array_keys(array_filter(self::parents(), fn (?string $parent): bool => $parent !== null));
    }
}
