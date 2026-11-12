<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Keys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property KeyType $key_type
 * @property string $handle
 * @property string $model_type
 * @property int $model_id
 */
final class CatalogueKey extends Model
{
    protected $table = 'catalogue_keys';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_type' => KeyType::class,
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
