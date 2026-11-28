<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One set of words for the home page hero, added in the admin (Hero content): the heading, the sub-heading and the first button
 * (its wording and its link, a path of this site such as /shop or a full https address). App\Domain\Catalogue\Home\HeroContents
 * picks one active set at random on every page load.
 *
 * @property int $id
 * @property string $heading
 * @property ?string $sub_heading
 * @property string $cta_text
 * @property string $cta_link
 * @property bool $active
 * @property int $position
 */
final class HeroContent extends Model
{
    protected $fillable = ['heading', 'sub_heading', 'cta_text', 'cta_link', 'active', 'position'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer'];
    }
}
