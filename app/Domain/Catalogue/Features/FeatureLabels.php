<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Features;

/**
 * The single source for turning the normalised feature codes in the data
 * contract into customer-facing labels and tooltips. Used by the listing
 * facets and the product specification table.
 */
final class FeatureLabels
{
    /**
     * Code => [label, tooltip].
     */
    private const array FEATURES = [
        'SRA' => ['Slip resistant (SRA)', 'Tested for slip resistance on ceramic tiles wetted with a soap solution.'],
        'SRB' => ['Slip resistant (SRB)', 'Tested for slip resistance on smooth steel with glycerol.'],
        'SRC' => ['Slip resistant (SRC)', 'Passes both the SRA and SRB slip tests: ceramic tiles with soap solution and steel with glycerol.'],
        'SR' => ['Slip resistant (SR)', 'Slip resistance on ceramic tiles with glycerol, under the 2022 edition of the standard.'],
        'ESD' => ['ESD, electrostatic dissipative', 'Controls the build-up of static electricity to protect sensitive electronic components.'],
        'CI' => ['Cold insulated (CI)', 'The sole insulates the foot against cold ground.'],
        'HI' => ['Heat insulated (HI)', 'The sole insulates the foot against hot ground.'],
        'HRO' => ['Heat resistant outsole (HRO)', 'The outsole withstands contact with surfaces up to 300°C for a short time.'],
        'WR' => ['Waterproof (WR)', 'The whole shoe resists water getting in.'],
        'FO' => ['Fuel and oil resistant outsole (FO)', 'The outsole resists damage from fuel and oil.'],
        'M' => ['Metatarsal protection (M)', 'Added protection over the top of the foot against impact.'],
        'AN' => ['Ankle protection (AN)', 'Added protection around the ankle against impact.'],
        'E' => ['Energy absorbing heel (E)', 'The heel absorbs shock to reduce fatigue.'],
        'A' => ['Antistatic (A)', 'Reduces the build-up of static electricity.'],
        'P' => ['Puncture resistant midsole (P)', 'A midsole that resists sharp objects such as nails coming through the sole.'],
        'METAL_FREE' => ['Metal free', 'Contains no metal parts, so it is lighter and suitable for sites with metal detectors.'],
    ];

    /**
     * Unknown codes are shown as they are rather than hidden, so a new code
     * from a vendor is visible until a label is added here.
     */
    public static function label(string $code): string
    {
        return self::FEATURES[$code][0] ?? str_replace('_', ' ', $code);
    }

    public static function tooltip(string $code): ?string
    {
        return self::FEATURES[$code][1] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::FEATURES);
    }
}
