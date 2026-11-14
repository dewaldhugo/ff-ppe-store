<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Data;

/**
 * What a mapper made of one raw record: a product, an out-of-scope reason,
 * or an error.
 *
 * Messages are report entries (dropped codes, skipped rows). Facts are
 * `[group, value]` pairs the run report tallies into distinct value sets,
 * for example `['unknown_size', '35/36']`.
 */
final readonly class MappingResult
{
    /**
     * @param  list<string>  $messages
     * @param  list<array{0: string, 1: string}>  $facts
     */
    private function __construct(
        public ?NormalisedProduct $product,
        public ?string $outOfScope,
        public ?string $error,
        public array $messages,
        public array $facts = [],
    ) {}

    /**
     * @param  list<string>  $messages
     * @param  list<array{0: string, 1: string}>  $facts
     */
    public static function product(NormalisedProduct $product, array $messages = [], array $facts = []): self
    {
        return new self($product, null, null, $messages, $facts);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $facts
     */
    public static function outOfScope(string $reason, array $facts = []): self
    {
        return new self(null, $reason, null, [], $facts);
    }

    /**
     * @param  list<string>  $messages
     * @param  list<array{0: string, 1: string}>  $facts
     */
    public static function error(string $error, array $messages = [], array $facts = []): self
    {
        return new self(null, null, $error, $messages, $facts);
    }
}
