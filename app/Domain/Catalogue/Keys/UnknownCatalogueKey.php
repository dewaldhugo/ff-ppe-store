<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Keys;

use RuntimeException;

final class UnknownCatalogueKey extends RuntimeException
{
    public static function notRegistered(KeyType $type, string $handle): self
    {
        return new self(sprintf(
            'No %s is registered with the handle "%s". Run CatalogueFoundationSeeder, or check the handle against the data contract.',
            str_replace('_', ' ', $type->value),
            $handle,
        ));
    }

    public static function recordMissing(KeyType $type, string $handle): self
    {
        return new self(sprintf(
            'The %s registered with the handle "%s" no longer exists. Run CatalogueFoundationSeeder to recreate it.',
            str_replace('_', ' ', $type->value),
            $handle,
        ));
    }
}
