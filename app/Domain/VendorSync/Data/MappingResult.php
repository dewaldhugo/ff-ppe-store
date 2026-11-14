<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Data;

/**
 * What a mapper made of one raw record: a product, an out-of-scope reason,
 * or an error. Messages are report entries (dropped codes, skipped rows).
 */
final readonly class MappingResult
{
    /**
     * @param  list<string>  $messages
     */
    private function __construct(
        public ?NormalisedProduct $product,
        public ?string $outOfScope,
        public ?string $error,
        public array $messages,
    ) {}

    /**
     * @param  list<string>  $messages
     */
    public static function product(NormalisedProduct $product, array $messages = []): self
    {
        return new self($product, null, null, $messages);
    }

    public static function outOfScope(string $reason): self
    {
        return new self(null, $reason, null, []);
    }

    /**
     * @param  list<string>  $messages
     */
    public static function error(string $error, array $messages = []): self
    {
        return new self(null, null, $error, $messages);
    }
}
