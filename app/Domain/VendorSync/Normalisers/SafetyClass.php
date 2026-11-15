<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Normalisers;

/**
 * Normalises a safety class to the contract form: no spaces or hyphens
 * (`S1 P` and `SB-P` become `S1P` and `SBP`). Where a vendor gives the base
 * class and `P` separately they are combined: `SB` + `P` = `SBP`,
 * `S1` + `P` = `S1P`, and likewise `OB` and `O1`. Every other class stays as
 * it is.
 */
final class SafetyClass
{
    /** Base classes that take a separate P. */
    private const array COMBINES_WITH_P = ['SB', 'S1', 'OB', 'O1'];

    /**
     * @param  list<string>  $separateCodes  Codes the vendor lists apart from the class.
     */
    public static function normalise(?string $class, array $separateCodes = []): ?string
    {
        $class = strtoupper((string) preg_replace('/[\s\-]+/', '', (string) $class));

        if ($class === '') {
            return null;
        }

        $hasP = in_array('P', array_map(fn (string $code): string => strtoupper(trim($code)), $separateCodes), true);

        if ($hasP && in_array($class, self::COMBINES_WITH_P, true)) {
            return $class.'P';
        }

        return $class;
    }
}
