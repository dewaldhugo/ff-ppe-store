<?php

declare(strict_types=1);

use App\Domain\Catalogue\Media\VideoLink;

// How a video that staff gave by web address is shown (App\Domain\Catalogue\Media\VideoLink).

it('turns every kind of YouTube address into a privacy friendly embed', function (string $url): void {
    expect(VideoLink::parse($url))->toBe(['kind' => 'frame', 'src' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'type' => null]);
})->with([
    'watch' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'watch with other parameters' => 'https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=10s',
    'mobile' => 'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
    'short link' => 'https://youtu.be/dQw4w9WgXcQ',
    'short link with a time' => 'https://youtu.be/dQw4w9WgXcQ?t=5',
    'shorts' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ',
    'embed' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'no cookie embed' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
    'spaces round it' => '  https://youtu.be/dQw4w9WgXcQ  ',
]);

it('turns a Vimeo address into the Vimeo player, keeping the hash of an unlisted video', function (): void {
    expect(VideoLink::parse('https://vimeo.com/76979871'))->toBe(['kind' => 'frame', 'src' => 'https://player.vimeo.com/video/76979871', 'type' => null])
        ->and(VideoLink::parse('https://vimeo.com/76979871/a1b2c3d4e5'))->toBe(['kind' => 'frame', 'src' => 'https://player.vimeo.com/video/76979871?h=a1b2c3d4e5', 'type' => null])
        ->and(VideoLink::parse('https://player.vimeo.com/video/76979871?h=a1b2c3d4e5'))->toBe(['kind' => 'frame', 'src' => 'https://player.vimeo.com/video/76979871?h=a1b2c3d4e5', 'type' => null]);
});

it('plays a direct MP4 or WebM link in a video player', function (): void {
    expect(VideoLink::parse('https://cdn.example.com/clips/Toecaps.MP4'))->toBe(['kind' => 'file', 'src' => 'https://cdn.example.com/clips/Toecaps.MP4', 'type' => 'video/mp4'])
        ->and(VideoLink::parse('https://cdn.example.com/clips/slip.webm?v=2'))->toBe(['kind' => 'file', 'src' => 'https://cdn.example.com/clips/slip.webm?v=2', 'type' => 'video/webm']);
});

it('refuses anything else', function (string $url): void {
    expect(VideoLink::parse($url))->toBeNull();
})->with([
    'plain http' => 'http://youtu.be/dQw4w9WgXcQ',
    'another site' => 'https://evil.example/embed/abc',
    'a look-alike host' => 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
    'youtube with a user part' => 'https://www.youtube.com@evil.example/watch?v=dQw4w9WgXcQ',
    'youtube without a video' => 'https://www.youtube.com/',
    'youtube channel' => 'https://www.youtube.com/@frontline',
    'a script address' => 'javascript:alert(1)',
    'a data address' => 'data:video/mp4;base64,AAAA',
    'a web page, not a file' => 'https://cdn.example.com/clips/toecaps.html',
    'an MP4 on a look-alike path' => 'https://cdn.example.com/clips/toecaps.mp4.html',
    'a Vimeo channel' => 'https://vimeo.com/channels/staffpicks',
    'not an address' => 'toecaps',
    'empty' => '',
]);

it('shows a video file one second in before it is played, unless its address already names a time', function (): void {
    expect(VideoLink::withPreviewFrame('https://cdn.example.com/a.mp4'))->toBe('https://cdn.example.com/a.mp4#t=1')
        ->and(VideoLink::withPreviewFrame('https://cdn.example.com/a.mp4?v=2'))->toBe('https://cdn.example.com/a.mp4?v=2#t=1')
        ->and(VideoLink::withPreviewFrame('https://cdn.example.com/a.mp4#t=5'))->toBe('https://cdn.example.com/a.mp4#t=5');
});
