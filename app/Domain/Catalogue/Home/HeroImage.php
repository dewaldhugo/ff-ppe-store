<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Home;

use Closure;
use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * The product photo beside the home hero. It lives at one fixed path. While the
 * file is missing, the hero has no image column at all.
 */
final class HeroImage
{
    public const SOURCE = 'resources/images/hero/hero.webp';

    /** @var Closure(): string */
    private readonly Closure $resolveUrl;

    public function __construct(private readonly ?string $path = null, ?Closure $resolveUrl = null)
    {
        $this->resolveUrl = $resolveUrl ?? static fn (): string => Vite::asset(self::SOURCE);
    }

    /**
     * @return array{url: string, width: int, height: int}|null
     */
    public function get(): ?array
    {
        $path = $this->path ?? base_path(self::SOURCE);

        if (! is_file($path) || filesize($path) === 0) {
            return null;
        }

        $size = @getimagesize($path);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        try {
            $url = ($this->resolveUrl)();
        } catch (Throwable) {
            // Not in the Vite manifest yet (assets not built).
            return null;
        }

        if ($url === '') {
            return null;
        }

        return ['url' => $url, 'width' => $size[0], 'height' => $size[1]];
    }
}
