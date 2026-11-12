<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Keys;

/**
 * Kinds of Lunar record the data contract identifies by handle where Lunar
 * itself has no handle column.
 */
enum KeyType: string
{
    case TaxClass = 'tax_class';
    case ProductType = 'product_type';
    case Collection = 'collection';
    case TaxZone = 'tax_zone';
}
