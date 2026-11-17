<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Data;

/**
 * One vendor product exactly as fetched.
 *
 * `hashable` is the payload without fields that change on every fetch
 * (file dates, stock owned by a separate feed). It defaults to the payload.
 */
final readonly class RawRecord
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<array-key, mixed>|null  $hashable
     */
    public function __construct(
        public string $externalId,
        public array $payload = [],
        public ?array $hashable = null,
        // Set by a source that can tell a record is out of scope without
        // fetching it (an excluded brand in a list response).
        public ?string $outOfScope = null,
        // Set when the record could not be fetched.
        public ?string $error = null,
        // Set by a source that had to ask again (retries, recoveries, diagnostics).
        public ?FetchOutcome $fetch = null,
        // The vendor's own name for this product, when the source has it
        // cheaply (a list response, a CSV row) without waiting on the
        // mapper. Used only for the importer's progress output; null falls
        // back to the external id.
        public ?string $name = null,
    ) {}

    public function hash(): string
    {
        return hash('sha256', self::canonical($this->hashable ?? $this->payload));
    }

    /**
     * Stable JSON: associative keys sorted at every level, lists kept in
     * order.
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function canonical(array $value): string
    {
        return json_encode(self::sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
