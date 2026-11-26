<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

/**
 * Recognises a Footsure product line that the vendor listed under several
 * ProductIDs, one per size band, so the storefront can show it once.
 *
 * Two ProductIDs are one line only when ALL of this holds:
 *
 * 1. Same brand and same short product name (case and spacing aside).
 * 2. Every fact in `ProductSignature::$facts` agrees: size scale, product
 *    type, category, safety class, standards and gender. A men's and a
 *    women's version of one name, or a certified and an uncertified one, are
 *    different products. Clearance is not one of them: a band being cleared
 *    out does not make it another product (the merged product is on
 *    clearance only when every band is).
 * 3. They share at least one colour. A ProductID per colour is a different
 *    split and is left alone.
 * 4. No colour and size pair is sold by both. Size bands do not overlap, and
 *    a pair sold twice would leave two variants a customer cannot tell apart.
 *
 * Sizes are compared by the option value the mapper made of them (`6`,
 * `Kids 6`), never by the raw UK size, so an infant UK 6 and an adult UK 6 are
 * different sizes.
 *
 * What it deliberately does not compare: descriptions and pictures. The
 * vendor writes a separate description and uses a separate picture file for
 * each ProductID of one line (the three Hi-Tec Squash trainer bands carry
 * three descriptions and three file names for the same photograph), so
 * requiring them to match would reject the very cases this exists for.
 *
 * Connected ProductIDs (A shares a colour with B, B with C) form one group,
 * and the group is merged only when no two of them collide on a pair. A
 * group that does collide is left unmerged and reported.
 */
final class SplitDetector
{
    /**
     * @param  iterable<ProductSignature>  $signatures
     */
    public function detect(iterable $signatures): SplitDetection
    {
        /** @var array<string, list<ProductSignature>> $lines */
        $lines = [];

        foreach ($signatures as $signature) {
            $lines[$signature->line][] = $signature;
        }

        $groups = [];
        $kept = [];

        foreach ($lines as $members) {
            if (count($members) < 2) {
                continue;
            }

            // Products of one line that agree on every fact.
            $byFacts = [];

            foreach ($members as $member) {
                $byFacts[json_encode($member->facts, JSON_THROW_ON_ERROR)][] = $member;
            }

            $handled = [];

            foreach ($byFacts as $candidates) {
                if (count($candidates) < 2) {
                    continue;
                }

                foreach ($this->components($candidates) as $component) {
                    if (count($component) < 2) {
                        continue;
                    }

                    foreach ($component as $member) {
                        $handled[$member->vendorProductId] = true;
                    }

                    $collision = $this->collision($component);

                    if ($collision === null) {
                        $groups[] = $this->ids($component);

                        continue;
                    }

                    $kept[] = [
                        'ids' => $this->ids($component),
                        'name' => $component[0]->label,
                        'reason' => "size {$collision[1]} in colour {$collision[0]} is sold by more than one ProductID",
                    ];
                }
            }

            $left = array_values(array_filter($members, fn (ProductSignature $member): bool => ! isset($handled[$member->vendorProductId])));

            // A product of the line that joined nothing: reported, with the
            // fact that kept it apart. A lone one only counts when the rest
            // of its line was merged or reported (it is the odd one out).
            if (count($left) >= 2 || (count($left) === 1 && $handled !== [])) {
                $kept[] = [
                    'ids' => $this->ids($left),
                    'name' => $left[0]->label,
                    'reason' => $this->reason($left, $members),
                ];
            }
        }

        usort($groups, fn (array $a, array $b): int => $this->compare($a[0], $b[0]));
        usort($kept, fn (array $a, array $b): int => $this->compare($a['ids'][0], $b['ids'][0]));

        return new SplitDetection($groups, $kept);
    }

    /**
     * Why products of one line were left apart: the first fact that differs
     * from the rest of the line, or that no colour is shared.
     *
     * @param  list<ProductSignature>  $left
     * @param  list<ProductSignature>  $line
     */
    private function reason(array $left, array $line): string
    {
        $differs = [];

        foreach (array_keys($left[0]->facts) as $fact) {
            $values = array_unique(array_map(fn (ProductSignature $member): string => $member->facts[$fact], $line));

            if (count($values) > 1) {
                $differs[] = $fact.' ('.implode(' / ', array_map(fn (string $value): string => $value === '' ? '(none)' : $value, $values)).')';
            }
        }

        if ($differs !== []) {
            return 'differs in '.implode(', ', $differs);
        }

        return 'no colour in common';
    }

    /**
     * Products joined by sharing a colour.
     *
     * @param  list<ProductSignature>  $products
     * @return list<list<ProductSignature>>
     */
    private function components(array $products): array
    {
        $parent = array_keys($products);

        $find = function (int $i) use (&$parent, &$find): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };

        $byColour = [];

        foreach ($products as $index => $product) {
            foreach ($product->colours() as $colour) {
                if (isset($byColour[$colour])) {
                    $parent[$find($index)] = $find($byColour[$colour]);
                } else {
                    $byColour[$colour] = $index;
                }
            }
        }

        $components = [];

        foreach ($products as $index => $product) {
            $components[$find($index)][] = $product;
        }

        return array_values($components);
    }

    /**
     * The first colour and size pair sold by two of the products, or null.
     *
     * @param  list<ProductSignature>  $products
     * @return array{0: string, 1: string}|null
     */
    private function collision(array $products): ?array
    {
        $seen = [];

        foreach ($products as $product) {
            foreach ($product->sizesByColour as $colour => $sizes) {
                foreach ($sizes as $size) {
                    $key = $colour."\0".$size;

                    if (isset($seen[$key])) {
                        return [$colour, $size];
                    }

                    $seen[$key] = true;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<ProductSignature>  $products
     * @return list<string>
     */
    private function ids(array $products): array
    {
        $ids = array_map(fn (ProductSignature $product): string => $product->vendorProductId, $products);
        usort($ids, $this->compare(...));

        return $ids;
    }

    /** Numeric ProductIDs in numeric order; anything else after them, as text. */
    private function compare(string $a, string $b): int
    {
        return is_numeric($a) && is_numeric($b) ? (int) $a <=> (int) $b : strcmp($a, $b);
    }
}
