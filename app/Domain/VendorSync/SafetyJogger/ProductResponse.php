<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\SafetyJogger;

/**
 * One `GET /product/{id}` answer: the decoded detail, the status, and the
 * allow-listed response headers (see ResponseHeaders).
 */
final readonly class ProductResponse
{
    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public array $detail,
        public int $status,
        public array $headers,
    ) {}
}
