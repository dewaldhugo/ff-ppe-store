<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Menu;

/**
 * The picture on a top-level category card on /shop. One file per contract
 * handle in `public/images/categories/{handle}.jpg`; a category without a file
 * (a new one added in the admin panel) simply shows no picture.
 */
final class CategoryCardImage
{
    public const string DIRECTORY = 'images/categories';

    /** Pixel size of the files, used for the width and height attributes. */
    public const int WIDTH = 263;

    public const int HEIGHT = 318;

    /**
     * @return array{src: string, width: int, height: int}|null
     */
    public static function for(?string $handle): ?array
    {
        if ($handle === null || preg_match('/^[a-z0-9-]+$/', $handle) !== 1) {
            return null;
        }

        $path = self::DIRECTORY.'/'.$handle.'.jpg';

        if (! is_file(public_path($path))) {
            return null;
        }

        return ['src' => asset($path), 'width' => self::WIDTH, 'height' => self::HEIGHT];
    }
}
