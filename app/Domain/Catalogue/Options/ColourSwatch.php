<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Options;

/**
 * The colours to draw in a swatch for a colour option value.
 *
 * A vendor's own hex wins for a single colour. Many values have none (the
 * Footsure feed gives names only), so the name is read instead: "Dark
 * Beige/Black/Yellow" is three colours, drawn as three vertical bands. A
 * name that cannot be read gives an empty list and the swatch stays neutral.
 */
final class ColourSwatch
{
    /** @var array<string, string> */
    private const array NAMED = [
        'black' => '1A1A1A', 'white' => 'FFFFFF', 'off white' => 'F4F1EA', 'grey' => '8C8F94', 'gray' => '8C8F94',
        'silver' => 'C0C0C0', 'charcoal' => '34383D', 'graphite' => '4A4E54', 'anthracite' => '3A3D42', 'slate' => '5B6670',
        'steel' => '71797E', 'gunmetal' => '2C3539', 'smoke' => 'A7A9AC', 'ash' => 'B2B4B6', 'pewter' => '8E9196',
        'navy' => '1F2A44', 'midnight' => '191970', 'blue' => '1F6CB8', 'royal' => '2B4DB8', 'royal blue' => '2B4DB8',
        'sky' => '87CEEB', 'sky blue' => '87CEEB', 'cobalt' => '2250A8', 'denim' => '4B6A9B', 'ocean' => '1E6F9F',
        'blueberry' => '4A5D8F', 'indigo' => '3F3A8C', 'petrol' => '1F5A6B', 'teal' => '1C7C7D', 'turquoise' => '30B6C4',
        'aqua' => '4CC9D6', 'cyan' => '00B8D4', 'red' => 'D32F2F', 'scarlet' => 'D62F1F', 'burgundy' => '800020',
        'wine' => '722F37', 'maroon' => '6B1F2A', 'claret' => '7F1734', 'cherry' => 'B0152F', 'crimson' => 'B71C1C',
        'pink' => 'F48FB1', 'rose' => 'E8869D', 'fuchsia' => 'C2185B', 'magenta' => 'C2185B', 'coral' => 'F2705A',
        'salmon' => 'F4978E', 'purple' => '6A3A8C', 'violet' => '7B4FA3', 'lilac' => 'B79AD0', 'lavender' => 'B9A7D6',
        'plum' => '6E2C5E', 'orange' => 'F28C28', 'amber' => 'F2A413', 'rust' => 'B5532A', 'yellow' => 'F5D130',
        'lemon' => 'F5E663', 'mustard' => 'D1A21A', 'gold' => 'D4AF37', 'honey' => 'C8892B', 'green' => '2E7D32',
        'forest' => '2D5A3D', 'bottle' => '1F4D3A', 'emerald' => '1E8A5A', 'olive' => '6B7035', 'khaki' => '9C8F5A',
        'lime' => '9ACD32', 'mint' => '9ED9B8', 'sage' => '8A9A7B', 'moss' => '5E6B3A', 'camo' => '5A6B3A',
        'camouflage' => '5A6B3A', 'brown' => '6B4423', 'tan' => 'C8A16A', 'beige' => 'D9C4A1', 'sand' => 'D6C29A',
        'cream' => 'F2E8CF', 'ecru' => 'E8DFC8', 'ivory' => 'F8F4E3', 'natural' => 'E3D5B8', 'stone' => 'BDB5A6',
        'taupe' => '8B7B6B', 'cedar' => 'A0522D', 'crazyhorse' => '6B4A2E', 'crazy horse' => '6B4A2E', 'tobacco' => '7B4B2A',
        'chestnut' => '954535', 'mocha' => '5C4033', 'chocolate' => '4A2C1A', 'coffee' => '4B3621', 'walnut' => '5C4033',
        'wheat' => 'DEB887', 'caramel' => 'B5793A', 'camel' => 'C19A6B', 'bronze' => '8C6A2F', 'copper' => 'B87333',
        'oak' => '8E6B3E', 'mahogany' => '5A2A1E', 'nut' => '8B5E3C', 'dune' => 'C9B28A', 'heather' => 'A39CAF',
        'chive' => '5F7A3A', 'brick' => 'A0412D', 'marine' => '1B3A57', 'berry' => '8E2C58', 'pumpkin' => 'E3731F',
        'neutral' => 'D8CBB4', 'oxblood' => '4A0E14', 'burgun' => '800020', 'bordo' => '6D1A2B', 'bordeaux' => '5E1224',
        'petal' => 'F2C4CE', 'shadow' => '4F5256', 'mushroom' => 'A89B8C', 'espresso' => '3B2A20', 'eclipse' => '2B2D33',
        'peyote' => 'D1B98E', 'shark' => '6F7C85', 'blush' => 'F0C5C0', 'marsh' => '6E7650', 'magnet' => '5A5F63',
        'shiraz' => '5C1A2A', 'wasabi' => '9DB84A', 'bluefish' => '4E7A92', 'fern' => '4F7942', 'tornado' => '6C7378',
        'desert' => 'CDB48A', 'teak' => '8A5A2B', 'ebony' => '2A2522', 'timber' => '9A7248', 'penny' => 'B06A3B',
        'nude' => 'E3BC9A', 'sundance' => 'E0B040', 'frost' => 'DCE6EA', 'eucalyptus' => '7E9C88', 'mallard' => '2E5E4E',
        'rosette' => 'D98BA0', 'sagebrush' => '8F9A7C', 'vanilla' => 'F3E5C0', 'hummus' => 'C9B48F', 'flintstone' => '6E6A66',
        'platino' => 'B8BBBE', 'asphalt' => '3B3F44', 'sprout' => '8DBF4A', 'cornflower' => '6495ED', 'azul' => '2F6DB5',
        'huckleberry' => '4B3A6B', 'raven' => '1E1E22', 'elk' => '8B6B4A', 'grisaille' => '7B7F85', 'pyramid' => 'C2A66B',
        'hi vis yellow' => 'E6F000', 'hi vis orange' => 'FF6A13', 'hi vis green' => '8CE000', 'hi vis pink' => 'FF3EA5',
    ];

    /**
     * @return list<string> Six-digit upper-case hex values, no leading "#"; at most four.
     */
    public static function hexes(string $name, ?string $vendorHex = null): array
    {
        $vendorHex = self::valid($vendorHex);
        // "Eclipse, 32" Leg" and "34L-Dark Shadow": a size is attached to the colour, not part of it.
        $name = (string) preg_replace(['/,\s*\d+"?\s*(?:leg|inch)?\s*$/i', '/^\d+[RLS]\s*-\s*/i'], '', $name);
        $parts = preg_split('~\s*(?:/|&|\+|,|\band\b)\s*~i', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) <= 1) {
            if ($vendorHex !== null) {
                return [$vendorHex];
            }

            $one = self::named($parts[0] ?? '');

            return $one === null ? [] : [$one];
        }

        $resolved = array_map(self::named(...), array_slice($parts, 0, 4));

        if (in_array(null, $resolved, true)) {
            if ($vendorHex !== null) {
                return [$vendorHex];
            }

            $resolved = array_values(array_filter($resolved));
        }

        return $resolved;
    }

    private static function named(string $part): ?string
    {
        $key = trim((string) preg_replace('/\s+/', ' ', str_replace(['-', '_', '.'], ' ', mb_strtolower($part))));

        if ($key === '') {
            return null;
        }

        if (isset(self::NAMED[$key])) {
            return self::NAMED[$key];
        }

        if (preg_match('/^(?:dark|deep)\s+(.+)$/', $key, $m) === 1 && ($base = self::named($m[1])) !== null) {
            return self::mix($base, '000000', 0.3);
        }

        if (preg_match('/^(?:light|pale|soft|baby)\s+(.+)$/', $key, $m) === 1 && ($base = self::named($m[1])) !== null) {
            return self::mix($base, 'FFFFFF', 0.45);
        }

        if (preg_match('/^(?:bright|mid|hi vis|high vis|fluo|fluorescent|neon)\s+(.+)$/', $key, $m) === 1) {
            return self::NAMED['hi vis '.$m[1]] ?? self::named($m[1]);
        }

        // "Brick Red", "Steel Blue": the last word is usually the colour.
        foreach (array_reverse(explode(' ', $key)) as $word) {
            if (isset(self::NAMED[$word])) {
                return self::NAMED[$word];
            }
        }

        return null;
    }

    private static function mix(string $hex, string $with, float $share): string
    {
        $out = '';

        foreach ([0, 2, 4] as $i) {
            $a = hexdec(substr($hex, $i, 2));
            $b = hexdec(substr($with, $i, 2));
            $out .= str_pad(dechex((int) round($a + ($b - $a) * $share)), 2, '0', STR_PAD_LEFT);
        }

        return strtoupper($out);
    }

    private static function valid(?string $hex): ?string
    {
        $hex = strtoupper(ltrim(trim((string) $hex), '#'));

        return preg_match('/^[0-9A-F]{6}$/', $hex) === 1 ? $hex : null;
    }
}
