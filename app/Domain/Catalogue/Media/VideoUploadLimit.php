<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Media;

/**
 * The largest product video the admin can really take. The store allows 200 MB (MAX_KILOBYTES), but PHP refuses a bigger upload
 * before the store sees it (`upload_max_filesize` for the file, `post_max_size` for the request), and then the browser only
 * says the upload failed. So the upload field is limited to what this server's PHP accepts, and tells the person that limit
 * and why, so a file that is too big is turned away at once with a clear message instead of failing part way.
 *
 * The web server's own body limit (nginx `client_max_body_size`) cannot be seen from PHP, so it is not part of this number;
 * docs/launch-checklist.md lists it with the PHP settings.
 */
final class VideoUploadLimit
{
    /** The largest video, in kilobytes (200 MB), whatever the server allows. */
    public const int MAX_KILOBYTES = 204800;

    /** What PHP reports for its two limits (`ini_get`), as strings like "2M"; overridable in tests. */
    public function __construct(
        private readonly ?string $uploadMaxFilesize = null,
        private readonly ?string $postMaxSize = null,
    ) {}

    /** The limit in kilobytes: the smallest of the store's own and PHP's two. */
    public function kilobytes(): int
    {
        $limits = [self::MAX_KILOBYTES];

        foreach ([$this->uploadMaxFilesize ?? (string) ini_get('upload_max_filesize'), $this->postMaxSize ?? (string) ini_get('post_max_size')] as $setting) {
            $kilobytes = intdiv($this->bytes($setting), 1024);

            // 0 and -1 mean no limit in PHP.
            if ($kilobytes > 0) {
                $limits[] = $kilobytes;
            }
        }

        return max(1, min($limits));
    }

    /** True when this server's PHP takes less than the store's 200 MB. */
    public function isReducedByServer(): bool
    {
        return $this->kilobytes() < self::MAX_KILOBYTES;
    }

    /** The limit as people read it, like "200 MB" or "2 MB". */
    public function label(): string
    {
        $kilobytes = $this->kilobytes();

        return $kilobytes >= 1024 ? rtrim(rtrim(number_format($kilobytes / 1024, 1, '.', ''), '0'), '.').' MB' : $kilobytes.' KB';
    }

    /** What to tell the person about the limit, and what to do when this server's is lower than the store's. */
    public function helperText(): string
    {
        $text = 'MP4 or WebM, up to '.$this->label().' each.';

        if ($this->isReducedByServer()) {
            $text .= ' This server\'s PHP settings (upload_max_filesize and post_max_size) only allow '.$this->label().', less than the '
                .((int) (self::MAX_KILOBYTES / 1024)).' MB the store allows: to upload larger videos ask whoever looks after the server to raise both, or add the video by its web address instead.';
        }

        return $text;
    }

    /** @return array<string, string> The messages for the upload field's validation rules. */
    public function validationMessages(): array
    {
        return [
            'max' => 'This video is bigger than the '.$this->label().' this server accepts. Make it smaller, or add the video by its web address instead.',
            'uploaded' => 'The video could not be uploaded. It may be bigger than the '.$this->label().' this server accepts, or the connection dropped. Try again, make it smaller, or add the video by its web address instead.',
            'mimetypes' => 'This file is not a video the store can play. Upload an MP4 or WebM file.',
            'file' => 'This is not a file the store can take. Upload an MP4 or WebM video.',
        ];
    }

    private function bytes(string $setting): int
    {
        $setting = trim($setting);
        $number = (int) $setting;

        return match (strtoupper(substr($setting, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
