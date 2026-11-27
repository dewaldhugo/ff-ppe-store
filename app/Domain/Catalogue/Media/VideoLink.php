<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Media;

/**
 * Works out how to show a video that staff gave by web address. Only three kinds are accepted, all over https, so nothing
 * else is ever put in a frame or a player on the product page:
 *
 * - YouTube (a watch, short or embed link, or youtu.be), shown in a privacy-friendly youtube-nocookie.com frame;
 * - Vimeo (vimeo.com/123456789, with the share hash of an unlisted video if it has one), shown in player.vimeo.com;
 * - a direct link to an MP4 or WebM file, shown in a video player.
 *
 * Anything else gives null, and the admin refuses it.
 */
final class VideoLink
{
    public const string FRAME = 'frame';

    public const string FILE = 'file';

    /**
     * @return ?array{kind: 'frame'|'file', src: string, type: ?string}
     */
    public static function parse(string $url): ?array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower(preg_replace('#^(www|m)\.#i', '', (string) ($parts['host'] ?? '')) ?? '');
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        $youtube = self::youtubeId($host, $path, $query);

        if ($youtube !== null) {
            return ['kind' => self::FRAME, 'src' => 'https://www.youtube-nocookie.com/embed/'.$youtube, 'type' => null];
        }

        if ($host === 'vimeo.com' && preg_match('#^/(\d{5,12})(?:/([0-9a-f]{6,20}))?/?$#i', $path, $match) === 1) {
            return ['kind' => self::FRAME, 'src' => 'https://player.vimeo.com/video/'.$match[1].(isset($match[2]) ? '?h='.$match[2] : ''), 'type' => null];
        }

        if ($host === 'player.vimeo.com' && preg_match('#^/video/(\d{5,12})/?$#', $path, $match) === 1) {
            $hash = $query['h'] ?? null;

            return ['kind' => self::FRAME, 'src' => 'https://player.vimeo.com/video/'.$match[1].(is_string($hash) && preg_match('#^[0-9a-f]{6,20}$#i', $hash) === 1 ? '?h='.$hash : ''), 'type' => null];
        }

        if ($host !== '' && preg_match('#\.(mp4|webm)$#i', $path, $match) === 1) {
            return ['kind' => self::FILE, 'src' => trim($url), 'type' => 'video/'.strtolower($match[1])];
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $query
     */
    private static function youtubeId(string $host, string $path, array $query): ?string
    {
        $id = null;

        if ($host === 'youtu.be') {
            $id = ltrim($path, '/');
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#^/(?:embed|shorts|live|v)/([\w-]+)/?$#', $path, $match) === 1) {
                $id = $match[1];
            }
        }

        return is_string($id) && preg_match('#^[\w-]{6,20}$#', $id) === 1 ? $id : null;
    }
}
