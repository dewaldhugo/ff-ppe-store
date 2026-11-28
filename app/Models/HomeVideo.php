<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A video of the home page's "See how it works" section and the Videos page, added in the admin (Home videos): a direct web
 * address of an MP4 or WebM file, or a file uploaded to the public disk. App\Domain\Catalogue\Home\HomeVideos turns the active ones
 * into what the pages show. Deleting a video deletes its uploaded file.
 *
 * @property int $id
 * @property string $label
 * @property ?string $caption
 * @property string $source `url` or `upload`
 * @property ?string $url
 * @property ?string $file Path on the public disk.
 * @property ?string $link_route
 * @property ?string $link_label
 * @property int $position
 * @property bool $active
 */
final class HomeVideo extends Model
{
    public const string DISK = 'public';

    public const string DIRECTORY = 'videos/home';

    public const string URL = 'url';

    public const string UPLOAD = 'upload';

    protected $fillable = ['label', 'caption', 'source', 'url', 'file', 'link_route', 'link_label', 'position', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        self::deleting(function (self $video): void {
            if (filled($video->file)) {
                Storage::disk(self::DISK)->delete($video->file);
            }
        });

        // Replacing the file or switching to a web address leaves the old file behind: take it away.
        self::updating(function (self $video): void {
            $old = $video->getOriginal('file');

            if (filled($old) && $old !== $video->file) {
                Storage::disk(self::DISK)->delete($old);
            }
        });
    }

    /** The address the player loads: the web address, or the uploaded file's. */
    public function address(): ?string
    {
        if ($this->source === self::UPLOAD) {
            return filled($this->file) ? Storage::disk(self::DISK)->url($this->file) : null;
        }

        return filled($this->url) ? $this->url : null;
    }
}
