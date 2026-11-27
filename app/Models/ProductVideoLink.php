<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\Product;

/**
 * A video staff added to a product by web address, in the Product videos section of the Edit Product screen. The product
 * page shows it after the vendor's own video and the uploaded files, in `position` order (App\Domain\Catalogue\Media\VideoLink
 * works out how).
 *
 * @property int $id
 * @property int $product_id
 * @property string $url
 * @property int $position
 */
final class ProductVideoLink extends Model
{
    protected $fillable = ['product_id', 'url', 'position'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
