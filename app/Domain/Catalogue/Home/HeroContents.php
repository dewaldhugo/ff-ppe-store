<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use App\Models\HeroContent;
use Illuminate\Support\Facades\Schema;

/**
 * The words of the home page hero: heading, sub-heading and the first button. One of the active sets added in the admin (Hero
 * content) is picked at random on every page load. While none have been added (or none is active) the headings of
 * config/homepage.php are used, one at random, with the standard sub-heading and a View all products button, so the hero is
 * never without words. The second button (Open a trade account) is not part of a set: it is the same with every one.
 */
final class HeroContents
{
    public const string DEFAULT_SUB_HEADING = 'EN-compliant safety footwear, head, eye, and hearing protection ready for immediate dispatch. Bulk trade pricing available.';

    public const string DEFAULT_CTA_TEXT = 'View all products';

    /** @return array{heading: string, sub_heading: ?string, cta_text: string, cta_url: string} */
    public function pick(): array
    {
        $sets = $this->sets();

        if ($sets !== []) {
            $set = $sets[random_int(0, count($sets) - 1)];

            return [
                'heading' => $set->heading,
                'sub_heading' => filled($set->sub_heading) ? $set->sub_heading : null,
                'cta_text' => $set->cta_text,
                'cta_url' => self::url($set->cta_link),
            ];
        }

        $headings = array_values(array_filter((array) config('homepage.hero_headings'), fn ($heading): bool => is_string($heading) && trim($heading) !== ''));

        return [
            'heading' => $headings === [] ? '' : $headings[random_int(0, count($headings) - 1)],
            'sub_heading' => self::DEFAULT_SUB_HEADING,
            'cta_text' => self::DEFAULT_CTA_TEXT,
            'cta_url' => route('shop'),
        ];
    }

    /** A path of this site (/shop/safety-footwear) becomes its full address; an https address is kept as it is. */
    public static function url(string $link): string
    {
        $link = trim($link);

        return str_starts_with($link, '/') ? url($link) : $link;
    }

    /** @return list<HeroContent> */
    private function sets(): array
    {
        if (! Schema::hasTable('hero_contents')) {
            return [];
        }

        return HeroContent::query()->where('active', true)->orderBy('position')->get()->all();
    }
}
