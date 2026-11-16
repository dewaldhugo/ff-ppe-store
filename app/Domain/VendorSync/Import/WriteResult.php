<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

final readonly class WriteResult
{
    /**
     * @param  list<string>  $draftReasons
     * @param  list<string>  $messages
     * @param  list<HeldPrice>  $held  Variants whose cost, price and compare price were kept.
     * @param  list<HeldCurrency>  $heldCurrency  Variants kept at their stored GBP values because the response was not in GBP.
     */
    public function __construct(
        public int $productId,
        public bool $created,
        public string $status,
        public array $draftReasons,
        public array $messages,
        public array $held = [],
        public array $heldCurrency = [],
    ) {}

    /**
     * Drafted by a rule, as opposed to waiting for its first image.
     */
    public function draftedByRule(): bool
    {
        return array_diff($this->draftReasons, [DraftReason::NO_IMAGE]) !== [];
    }
}
