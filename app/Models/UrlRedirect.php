<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A retired storefront path and the path that replaced it. Paths are
 * root-relative, e.g. /shop/safety-footwear/safety-boots.
 */
final class UrlRedirect extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];
}
