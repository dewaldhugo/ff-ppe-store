<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use Lunar\Models\OrderLine;

/**
 * How an order line's colour and sizes are worded wherever an order is shown as a list or a sentence (the order page, the
 * confirmation page, the order email): the colour, then the UK size, then the EU size, as they were when the order was placed
 * (the line's meta, copied by SnapshotLineOptions). The invoice and its PDF show the same three as table columns.
 *
 * A footwear size is worded `UK 9`; a size that is not a UK footwear size (a garment size) is worded as it is. A line placed
 * before the meta was kept falls back to Lunar's plain option text.
 */
final class LineDetails
{
    /**
     * Colour, UK size and EU size, in that order, leaving out what the line does not have.
     *
     * @return list<string>
     */
    public static function parts(OrderLine $line): array
    {
        $size = $line->meta['size'] ?? null;
        $eu = $line->meta['eu_size'] ?? null;

        return array_values(array_filter([
            $line->meta['colour'] ?? null,
            filled($size) ? (($line->meta['size_is_uk'] ?? false) ? 'UK ' : '').$size : null,
            filled($eu) ? 'EU '.$eu : null,
        ], fn (mixed $part): bool => filled($part)));
    }

    /** The parts as one string, or the line's own option text when it has none. */
    public static function text(OrderLine $line): string
    {
        $parts = self::parts($line);

        return $parts === [] ? trim((string) $line->option) : implode(', ', $parts);
    }
}
