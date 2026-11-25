<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Display;

use App\Domain\Catalogue\Collections\CategoryTree;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\KeyType;
use Lunar\Models\Product;

/**
 * The "Key features" block of a product page: a picture, a heading and a short explanation for each feature the product has, in
 * the wording Safety Jogger uses on its own site (Heat insulated has none there, so it uses the store's own).
 *
 * Which tiles a product gets comes from two places, on footwear only (the pictures show shoes). The vendor's own key points (the
 * `key_points` attribute, in the vendor's order) name the features of a shoe, including ones that are not codes (nano carbon
 * toecap, 3D mesh, SJ Foam, warm lining); a point is matched to a tile by its pattern. Then the product's `features` codes (data
 * contract) add the tiles for the features that are codes, that the key points did not already give. A tile is shown only when
 * its picture is in `public/images/features`, so adding a feature is dropping the file in the folder and adding a line here.
 * Where a product has both slip resistance tiles it shows SRC only, which covers SR. Descriptions are not read: they mention
 * features in passing ("not waterproof") and gave matches that were wrong.
 */
final class FeatureHighlights
{
    /** The folder under `public/` that holds the pictures. */
    private const string FOLDER = 'images/features';

    /**
     * Tile => [heading, picture file, text, patterns a key point may match, feature codes]. In the order the tiles are shown when
     * the key points do not decide it.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: list<string>, 4: list<string>}>
     */
    private const array TILES = [
        'metal_free' => ['Metal free', 'metal_free.png', 'Metal free safety shoes are in general lighter than regular safety shoes. They are also very beneficial for professionals who have to pass through metal detectors several times a day.', ['/^metal[- ]?free$/i'], ['METAL_FREE']],
        'sr' => ['Slip resistance (SR)', 'sr.png', 'Replaces the previously used term of SRA+SRB=SRC. SR means the slip test has been executed on tiles contaminated with soap and with oil.', ['/^slip resistance( \(sr\))?$/i'], ['SR']],
        'src' => ['SRC slip resistance', 'src.png', 'SRC slip resistant soles pass both SRA and SRB slip resistant tests. They are tested on steel and ceramic surfaces.', ['/\bsrc\b/i'], ['SRC']],
        'esd' => ['Electrostatic discharge (ESD)', 'electrostatic_discharge.png', 'ESD provides the controlled discharge of electrostatic energy that can damage electronic components and avoids risks of ignition resulting from electrostatic charges. Volume resistance between 100 KiloOhm and 100 MegaOhm.', ['/^electrostatic discharge( \(esd\))?$/i', '/^esd\b/i'], ['ESD']],
        'antistatic' => ['Antistatic', 'antistatic.png', 'Antistatic footwear prevents static build-up and discharges it effectively. Volume resistance between 100 KiloOhm and 1 GigaOhm.', ['/^anti-?static/i'], ['A']],
        'scuff_cap' => ['Scuff cap (SC)', 'scuff_cap.png', 'Separately tested material to cover the toe cap area to reduce abrasion of the upper material (e.g. during kneeling operations) and extend usability of the safety shoe.', ['/^scuff cap/i'], ['SC']],
        'cold_insulated' => ['Cold insulated (CI)', 'cold_insulated.png', 'Cold insulated (CI) safety shoes keep your feet warm. They are worn in cold environments.', ['/^cold insulated/i'], ['CI']],
        'heat_insulated' => ['Heat insulated (HI)', 'heat_insulated.png', 'The sole insulates the foot against hot ground.', ['/^heat insulated/i'], ['HI']],
        'heat_resistant_outsole' => ['Heat resistant outsole (HRO)', 'heat_resistant_outsole.png', 'The outsole resists high temperatures up to 300°C.', ['/^heat resistant outsole/i', '/^outsole heat resistant/i'], ['HRO']],
        'ladder_grip' => ['Ladder grip (LG)', 'ladder_grip.png', 'Especially defined contour in the shank area of a safety shoe to provide additional safety while standing on ladders.', ['/ladder grip/i'], ['LG']],
        'oil_fuel_resistant' => ['Oil & fuel resistant', 'oil_fuel_resistant.png', 'The outsole is resistant against oil and fuel.', ['/^oil (&|and) fuel resistant/i', '/^oil resistant outsole/i'], ['FO']],
        'waterproof' => ['Waterproof', 'waterproof.png', 'Waterproof footwear prevents liquids to enter into the shoe.', ['/waterproof/i'], ['WR']],
        'water_resistant_upper' => ['Water resistant upper (WRU)', 'water_resistant_upper.png', 'Prevents penetration of water if not permanently exposed to high levels.', ['/^water resistant (full grain )?(leather )?upper/i'], ['WRU']],
        'warm_lining' => ['Warm lining', 'warm_lining.png', 'Keeps your feet warm and dry in cold environments.', ['/^warm lining/i'], []],
        'breathable_upper' => ['Breathable upper', 'breathable_upper.png', 'Increased moisture and temperature management for extended wearer comfort.', ['/^breathable upper/i'], []],
        'breathable_leather_upper' => ['Breathable, leather upper', 'breathable_leather_upper.png', 'Natural leather provides a high degree of wearer comfort combined with durability in versatile applications.', ['/^breathable,? leather upper/i'], []],
        'nano_carbon_toecap' => ['Nano carbon toecap', 'nano_carbon_toecap.png', 'Ultralight high-tech material, metalfree with no thermal or electrical conductivity.', ['/nano carbon/i'], []],
        'puncture_resistant_lightweight' => ['Puncture resistant lightweight midsole', 'puncture_resistant_lightweight.png', 'Metal free, super flexible and ultralight puncture resistant midsole. Covers 100% of the bottom area of the last, no thermal conductivity.', ['/^puncture resistant lightweight/i'], []],
        '3d_mesh' => ['3D mesh', '3d_mesh.png', 'Three-dimensional produced distance mesh to provide increased moisture and temperature management.', ['/^3d mesh/i'], []],
        'sj_foam' => ['SJ foam', 'sj_foam.png', 'Removable comfortable antistatic footbed providing fit, guidance and optimum shock absorption in heel and forefoot. Breathable and moisture absorbing.', ['/^sj foam/i'], []],
        'tls' => ['Twist Lock System (TLS)', 'tls.png', 'The TLS closure lets you tighten and loosen the shoe with one hand in any conditions, including while wearing safety gloves, for a fast, safe and precise fit with more comfort.', ['/\btls\b|twist lock/i'], []],
    ];

    /**
     * @return list<array{code: string, title: string, text: string, image: string}>
     */
    public function for(Product $product): array
    {
        if (! $this->isFootwear($product)) {
            return [];
        }

        $chosen = [];

        // The vendor's key points first, in its order.
        foreach ((array) $product->attr('key_points') as $point) {
            $name = trim((string) preg_replace('/\s+/', ' ', (string) $point));

            foreach (self::TILES as $tile => $definition) {
                foreach ($definition[3] as $pattern) {
                    if (preg_match($pattern, $name) === 1) {
                        $chosen[$tile] = true;

                        break;
                    }
                }
            }
        }

        // Then the tiles the product's feature codes give.
        $features = $product->attr('features');
        $codes = array_map(fn (mixed $code): string => trim((string) $code), is_array($features) ? $features : []);

        foreach (self::TILES as $tile => $definition) {
            if (array_intersect($definition[4], $codes) !== []) {
                $chosen[$tile] = true;
            }
        }

        // SRC covers SR: one slip resistance tile.
        if (isset($chosen['src'])) {
            unset($chosen['sr']);
        }

        $tiles = [];

        foreach (array_keys($chosen) as $tile) {
            [$title, $file, $text] = self::TILES[$tile];

            if (is_file(public_path(self::FOLDER.'/'.$file))) {
                $tiles[] = ['code' => $tile, 'title' => $title, 'text' => $text, 'image' => asset(self::FOLDER.'/'.$file)];
            }
        }

        return $tiles;
    }

    /**
     * The pictures show footwear, so a jacket or a glove never gets them, whatever its key points say: the product must be in a
     * category under Safety Footwear or Non-Safety Footwear.
     */
    private function isFootwear(Product $product): bool
    {
        $leaves = array_merge(...array_map(CategoryTree::leavesUnder(...), CategoryTree::FOOTWEAR_TOPS));

        foreach ($product->collections as $collection) {
            if (in_array(CatalogueKeys::handleFor(KeyType::Collection, $collection), $leaves, true)) {
                return true;
            }
        }

        return false;
    }
}
