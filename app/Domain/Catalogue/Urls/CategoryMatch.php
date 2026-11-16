<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls;

/**
 * The outcome of resolving a requested category path: either the collection
 * it names, or the path it should be redirected to.
 */
final readonly class CategoryMatch
{
    private function __construct(
        public ?int $collectionId,
        public ?string $redirectTo,
    ) {}

    public static function found(int $collectionId): self
    {
        return new self($collectionId, null);
    }

    /** @param string $path root-relative, e.g. /shop/safety-footwear */
    public static function redirect(string $path): self
    {
        return new self(null, $path);
    }
}
