<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last known storefront path of a category collection.
 */
final class CollectionPathSnapshot extends Model
{
    public const CREATED_AT = null;

    protected $guarded = [];
}
