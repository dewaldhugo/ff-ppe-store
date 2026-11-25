<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Display;

use Lunar\Models\Product;

/**
 * The key points under the buy box, without the ones that only repeat the Safety standard line next to them: the safety class
 * itself ("S1P") and a point that is, or ends with, one of the codes shown there ("Electrostatic Discharge (ESD)", "Slip resistance
 * (SR)"). A point the line does not say is kept, so nothing is lost: "Metal free" stays, because the line shows codes only.
 */
final class KeyPoints
{
    public function __construct(private readonly SafetyStandardPanel $panel) {}

    /**
     * @return list<string>
     */
    public function for(Product $product): array
    {
        $panel = $this->panel->for($product);
        $class = $this->normalise((string) ($panel['class'] ?? ''));
        $codes = array_map($this->normalise(...), $panel['codes'] ?? []);

        $points = [];

        foreach ((array) $product->attr('key_points') as $point) {
            $point = trim((string) $point);
            $text = $this->normalise($point);

            if ($point === '' || ($class !== '' && $text === $class) || $this->restatesCode($text, $codes)) {
                continue;
            }

            $points[] = $point;
        }

        return $points;
    }

    /**
     * @param  list<string>  $codes  Lower case.
     */
    private function restatesCode(string $text, array $codes): bool
    {
        foreach ($codes as $code) {
            if ($code !== '' && ($text === $code || str_ends_with($text, "({$code})"))) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));
    }
}
