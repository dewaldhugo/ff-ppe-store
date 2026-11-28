<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use App\Models\HomeVideo;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * The videos the home page and the Videos page show. They are the ones added in the admin (Home videos), active ones in their
 * position order; while none have been added at all, the list in config/homepage.php (`videos`) is used, so the page was never
 * empty. Each is an array with `label`, `src` (a #t=1 fragment makes the player show the frame one second in), `caption`, and
 * `link` (a route name that exists and a label) when it has one.
 */
final class HomeVideos
{
    /** The pages that a video may link to, by route name, for the admin's drop-down. */
    public const array LINKS = [
        'shop' => 'Shop safety footwear',
        'technical-features' => 'See the technical features',
        'size-guide' => 'Find your size',
        'wear-trials' => 'Ask about wear trials',
        'ppe-health-check' => 'Take the PPE Health Check',
        'contact' => 'Contact us',
    ];

    /** @return list<array{label: string, src: string, caption: ?string, link: ?array{route: string, label: string}, loop?: bool, preload?: string}> */
    public function active(): array
    {
        if (! Schema::hasTable('home_videos') || ! HomeVideo::query()->exists()) {
            return array_values((array) config('homepage.videos', []));
        }

        return HomeVideo::query()->where('active', true)->orderBy('position')->orderBy('id')->get()
            ->map(function (HomeVideo $video): ?array {
                $address = $video->address();

                if ($address === null) {
                    return null;
                }

                $link = null;

                if (filled($video->link_route) && Route::has($video->link_route)) {
                    $link = ['route' => $video->link_route, 'label' => filled($video->link_label) ? (string) $video->link_label : (self::LINKS[$video->link_route] ?? 'Find out more')];
                }

                return [
                    'label' => $video->label,
                    'src' => str_contains($address, '#') ? $address : $address.'#t=1',
                    'caption' => $video->caption,
                    'link' => $link,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Copies the standard videos of config/homepage.php into the admin's list, for a store that wants to edit them there. A video
     * whose address is already in the list is left alone, so it can be run again.
     */
    public function importStandard(): int
    {
        $position = (int) HomeVideo::query()->max('position');
        $added = 0;

        foreach ((array) config('homepage.videos', []) as $video) {
            if (HomeVideo::query()->where('url', $video['src'])->exists()) {
                continue;
            }

            HomeVideo::query()->create([
                'label' => (string) $video['label'],
                'caption' => $video['caption'] ?? null,
                'source' => HomeVideo::URL,
                'url' => (string) $video['src'],
                'link_route' => $video['link']['route'] ?? null,
                'link_label' => $video['link']['label'] ?? null,
                'position' => ++$position,
                'active' => true,
            ]);

            $added++;
        }

        return $added;
    }
}
