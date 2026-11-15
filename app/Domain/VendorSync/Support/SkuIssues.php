<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Support;

/**
 * Aggregates per-SKU report entries into one line per product and reason,
 * for example:
 *
 *   OLIVE [273934]: 20 SKUs with cost at or above RRP; stock set to 0 (SKUs: 1349550, 1461710, ...)
 *
 * While mapping, a mapper adds an issue to its message list as an array
 * `[reason, sku, detail?]`. `collapse()` then turns those into one line per
 * reason and leaves ordinary string messages where they are.
 */
final class SkuIssues
{
    public const string COST_ABOVE_RRP = 'cost_above_rrp';

    public const string NOT_PER_PAIR = 'not_per_pair';

    public const string UNKNOWN_SIZE = 'unknown_size';

    public const string NO_COST = 'no_cost';

    public const string DUPLICATE = 'duplicate';

    public const string NO_COLOUR = 'no_colour';

    public const string UNKNOWN_VAT = 'unknown_vat';

    public const string GHOST = 'ghost';

    public const string NO_EU_SIZE = 'no_eu_size';

    /** SKUs listed per line before the list is cut short. */
    private const int LISTED = 40;

    /**
     * @param  list<string|array{0: string, 1: string, 2?: string}>  $messages
     */
    public static function count(array $messages, string $reason): int
    {
        return count(array_filter($messages, fn (mixed $message): bool => is_array($message) && $message[0] === $reason));
    }

    /**
     * @param  list<string|array{0: string, 1: string, 2?: string}>  $messages
     * @return list<string>
     */
    public static function collapse(string $label, array $messages): array
    {
        $lines = [];
        $issues = [];

        foreach ($messages as $message) {
            if (is_string($message)) {
                $lines[] = $message;

                continue;
            }

            $issues[$message[0]]['skus'][] = $message[1];

            if (isset($message[2]) && $message[2] !== '') {
                $issues[$message[0]]['details'][$message[2]] = true;
            }
        }

        foreach ($issues as $reason => $issue) {
            $count = count($issue['skus']);
            $skus = $count === 1 ? '1 SKU' : "{$count} SKUs";
            $shown = array_slice(array_keys($issue['details'] ?? []), 0, 10);
            $details = implode(', ', array_map(strval(...), $shown))
                .(count($issue['details'] ?? []) > 10 ? ', ...' : '');

            $text = match ($reason) {
                self::COST_ABOVE_RRP => "{$skus} with cost at or above RRP; stock set to 0",
                self::NOT_PER_PAIR => "{$skus} not sold per single pair; not imported",
                self::UNKNOWN_SIZE => "{$skus} with a size that is not in the size chart ({$details}); not imported",
                self::NO_COST => "{$skus} with no cost; not imported",
                self::DUPLICATE => "{$skus} repeating a colour and size ({$details}); skipped",
                self::NO_COLOUR => "{$skus} with no colour; product drafted",
                self::UNKNOWN_VAT => "{$skus} with a VAT rate that is not 0 or 20% ({$details}); not imported",
                self::GHOST => "{$skus} that are placeholder rows (no barcode, no EU size, placeholder measurements); not imported",
                self::NO_EU_SIZE => "{$skus} at a zero VAT rate with no EU size, so the size scale is unknown; not imported",
                default => "{$skus}: {$reason}",
            };

            $listed = array_slice($issue['skus'], 0, self::LISTED);
            $more = $count > self::LISTED ? ', ... and '.($count - self::LISTED).' more' : '';

            $lines[] = "{$label}: {$text} (SKUs: ".implode(', ', $listed).$more.')';
        }

        return $lines;
    }
}
