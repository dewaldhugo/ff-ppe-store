<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Normalisers;

/**
 * Keeps the feature codes of the data contract and reports the rest.
 * Unknown codes are dropped, never invented or renamed.
 */
final readonly class Features
{
    /**
     * @param  list<string>  $contractCodes
     */
    public function __construct(private array $contractCodes) {}

    /**
     * @param  list<string>  $codes
     * @return array{0: list<string>, 1: list<string>} Known codes in order of appearance, then unknown codes.
     */
    public function normalise(array $codes): array
    {
        $known = [];
        $unknown = [];

        foreach ($codes as $code) {
            $code = strtoupper(trim($code));

            if ($code === '') {
                continue;
            }

            if (in_array($code, $this->contractCodes, true)) {
                $known[$code] = true;
            } else {
                $unknown[$code] = true;
            }
        }

        return [array_map(strval(...), array_keys($known)), array_map(strval(...), array_keys($unknown))];
    }
}
