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
            $details = implode(', ', array_map(strval(...), array_keys($issue['details'] ?? [])));

            $text = match ($reason) {
                self::COST_ABOVE_RRP => "{$skus} with cost at or above RRP; stock set to 0",
                self::NOT_PER_PAIR => "{$skus} not sold per single pair; not imported",
                self::UNKNOWN_SIZE => "{$skus} with a size that is not in the size chart ({$details}); not imported",
                self::NO_COST => "{$skus} with no cost; not imported",
                default => "{$skus}: {$reason}",
            };

            $listed = array_slice($issue['skus'], 0, self::LISTED);
            $more = $count > self::LISTED ? ', ... and '.($count - self::LISTED).' more' : '';

            $lines[] = "{$label}: {$text} (SKUs: ".implode(', ', $listed).$more.')';
        }

        return $lines;
    }
}
