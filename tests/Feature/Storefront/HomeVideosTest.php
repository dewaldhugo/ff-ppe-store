<?php

declare(strict_types=1);

use App\Admin\Resources\HomeVideoResource;
use App\Admin\Resources\HomeVideoResource\Pages\CreateHomeVideo;
use App\Admin\Resources\HomeVideoResource\Pages\EditHomeVideo;
use App\Admin\Resources\HomeVideoResource\Pages\ListHomeVideos;
use App\Domain\Catalogue\Home\HomeVideos;
use App\Models\HomeVideo;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Lunar\Admin\Models\Staff;

// Home videos: added in the admin by web address or upload, listed there, shown on the home page and on the Videos page (in the main menu).

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

function homeVideo(array $overrides = []): HomeVideo
{
    return HomeVideo::query()->create([
        'label' => 'Toe caps',
        'caption' => 'How a toe cap protects.',
        'source' => HomeVideo::URL,
        'url' => 'https://cdn.example.test/toecaps.mp4',
        'position' => 1,
        'active' => true,
        ...$overrides,
    ]);
}

function videoAdmin(array $permissions = [], bool $admin = true): Staff
{
    $staff = Staff::factory()->create(['admin' => $admin]);

    foreach ($permissions as $permission) {
        $staff->givePermissionTo($permission);
    }

    test()->actingAs($staff, 'staff');
    Filament::setCurrentPanel(Filament::getPanel('lunar'));

    return $staff;
}

// --- What the pages show ---------------------------------------------------------------------------

it('uses the standard videos of the config while none have been added', function (): void {
    $videos = app(HomeVideos::class)->active();

    expect($videos)->toHaveCount(count(config('homepage.videos')))
        ->and(array_column($videos, 'label'))->toBe(array_column(config('homepage.videos'), 'label'));
});

it('uses the videos added in the admin instead, active ones in position order, with the frame one second in', function (): void {
    homeVideo(['label' => 'Second', 'url' => 'https://cdn.example.test/two.mp4', 'position' => 2]);
    homeVideo(['label' => 'First', 'url' => 'https://cdn.example.test/one.mp4', 'position' => 1]);
    homeVideo(['label' => 'Hidden', 'url' => 'https://cdn.example.test/three.mp4', 'position' => 0, 'active' => false]);

    $videos = app(HomeVideos::class)->active();

    expect(array_column($videos, 'label'))->toBe(['First', 'Second'])
        ->and($videos[0]['src'])->toBe('https://cdn.example.test/one.mp4#t=1');
});

it('serves an uploaded file from the public disk', function (): void {
    Storage::fake(HomeVideo::DISK);

    homeVideo(['source' => HomeVideo::UPLOAD, 'url' => null, 'file' => 'videos/home/boot.mp4']);

    expect(app(HomeVideos::class)->active()[0]['src'])->toBe(Storage::disk(HomeVideo::DISK)->url('videos/home/boot.mp4').'#t=1');
});

it('gives a video its link only while the page it names exists', function (): void {
    homeVideo(['link_route' => 'technical-features', 'link_label' => '']);
    homeVideo(['label' => 'Gone', 'url' => 'https://cdn.example.test/gone.mp4', 'position' => 2, 'link_route' => 'no-such-route']);

    $videos = app(HomeVideos::class)->active();

    expect($videos[0]['link'])->toBe(['route' => 'technical-features', 'label' => 'See the technical features'])
        ->and($videos[1]['link'])->toBeNull();
});

it('shows the added videos on the home page', function (): void {
    homeVideo(['label' => 'Only one']);

    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-video.*?</section>#s', $html, $section);

    expect(substr_count($section[0] ?? '', '<video'))->toBe(1)->and($section[0])->toContain('Only one')->toContain('https://cdn.example.test/toecaps.mp4#t=1');
});

it('copies the standard videos into the list once, and not again', function (): void {
    $added = app(HomeVideos::class)->importStandard();

    expect($added)->toBe(count(config('homepage.videos')))
        ->and(HomeVideo::query()->count())->toBe($added)
        ->and(app(HomeVideos::class)->importStandard())->toBe(0)
        ->and(HomeVideo::query()->count())->toBe($added);
});

// --- The Videos page and the menu ------------------------------------------------------------------

it('has a Videos page listing every active video, none of them autoplaying', function (): void {
    expect(route('videos', absolute: false))->toBe('/videos');

    homeVideo();
    homeVideo(['label' => 'Midsoles', 'url' => 'https://cdn.example.test/mid.mp4', 'position' => 2]);
    homeVideo(['label' => 'Hidden', 'url' => 'https://cdn.example.test/h.mp4', 'position' => 3, 'active' => false]);

    $html = $this->get(route('videos'))->assertOk()->getContent();

    expect($html)->toContain('<title>Videos | ')->and(substr_count($html, 'data-video-item'))->toBe(2)
        ->and($html)->not->toContain('Hidden')->not->toMatch('/<video[^>]*\sautoplay/')->toContain('data-video-caption');
});

it('has the Videos page in the standard list when none have been added', function (): void {
    $html = $this->get(route('videos'))->assertOk()->getContent();

    expect(substr_count($html, 'data-video-item'))->toBe(count(config('homepage.videos')));
});

it('puts Videos in the main menu and the mobile menu, and marks it current on its page', function (): void {
    $html = $this->get(route('videos'))->assertOk()->getContent();

    preg_match('#<nav aria-label="Main".*?</nav>#s', $html, $main);
    preg_match('#<nav aria-label="Mobile".*?</nav>#s', $html, $mobile);

    expect($main[0] ?? '')->toMatch('#<a href="'.preg_quote(route('videos'), '#').'"[^>]*aria-current="page"[^>]*>Videos#')
        ->and($mobile[0] ?? '')->toContain('href="'.route('videos').'"');

    $other = $this->get(route('about'))->assertOk()->getContent();

    expect($other)->toMatch('#<a href="'.preg_quote(route('videos'), '#').'"(?![^>]*aria-current)[^>]*>Videos#');
});

// --- The admin -------------------------------------------------------------------------------------

it('lists the existing videos in the admin, with their source', function (): void {
    videoAdmin();

    $first = homeVideo(['label' => 'By address']);
    $second = homeVideo(['label' => 'By upload', 'source' => HomeVideo::UPLOAD, 'url' => null, 'file' => 'videos/home/x.mp4', 'position' => 2]);

    Livewire::test(ListHomeVideos::class)->assertCanSeeTableRecords([$first, $second])->assertSee('Web address')->assertSee('Uploaded file');
});

it('adds a video by web address', function (): void {
    videoAdmin();

    Livewire::test(CreateHomeVideo::class)
        ->fillForm(['label' => 'Slip resistance', 'caption' => 'How soles grip.', 'source' => HomeVideo::URL, 'url' => 'https://cdn.example.test/slip.mp4', 'link_route' => 'shop', 'active' => true])
        ->call('create')->assertHasNoFormErrors();

    $video = HomeVideo::query()->firstOrFail();

    expect($video->label)->toBe('Slip resistance')->and($video->url)->toBe('https://cdn.example.test/slip.mp4')->and($video->file)->toBeNull()
        ->and($video->link_route)->toBe('shop');
});

it('only takes an https address of an MP4 or WebM file', function (string $url): void {
    videoAdmin();

    Livewire::test(CreateHomeVideo::class)
        ->fillForm(['label' => 'Bad', 'source' => HomeVideo::URL, 'url' => $url])
        ->call('create')->assertHasFormErrors(['url']);

    expect(HomeVideo::query()->count())->toBe(0);
})->with(['http://cdn.example.test/a.mp4', 'https://www.youtube.com/watch?v=abc123', 'https://cdn.example.test/a.pdf']);

it('adds a video by upload, and the file is stored on the public disk', function (): void {
    Storage::fake(HomeVideo::DISK);
    videoAdmin();

    Livewire::test(CreateHomeVideo::class)
        ->fillForm(['label' => 'Uploaded', 'source' => HomeVideo::UPLOAD, 'file' => UploadedFile::fake()->create('boots.mp4', 500, 'video/mp4'), 'active' => true])
        ->call('create')->assertHasNoFormErrors();

    $video = HomeVideo::query()->firstOrFail();

    expect($video->source)->toBe(HomeVideo::UPLOAD)->and($video->url)->toBeNull()->and($video->file)->not->toBeNull();

    Storage::disk(HomeVideo::DISK)->assertExists($video->file);
});

it('needs a file for an upload and refuses a file that is not a video', function (): void {
    Storage::fake(HomeVideo::DISK);
    videoAdmin();

    Livewire::test(CreateHomeVideo::class)->fillForm(['label' => 'No file', 'source' => HomeVideo::UPLOAD])->call('create')->assertHasFormErrors(['file']);
    Livewire::test(CreateHomeVideo::class)
        ->fillForm(['label' => 'Wrong', 'source' => HomeVideo::UPLOAD, 'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
        ->call('create')->assertHasFormErrors(['file']);

    expect(HomeVideo::query()->count())->toBe(0);
});

it('edits a video and switching to a web address removes the uploaded file', function (): void {
    Storage::fake(HomeVideo::DISK);
    videoAdmin();

    Storage::disk(HomeVideo::DISK)->put('videos/home/old.mp4', 'x');
    $video = homeVideo(['source' => HomeVideo::UPLOAD, 'url' => null, 'file' => 'videos/home/old.mp4']);

    Livewire::test(EditHomeVideo::class, ['record' => $video->getKey()])
        ->fillForm(['source' => HomeVideo::URL, 'url' => 'https://cdn.example.test/new.mp4'])
        ->call('save')->assertHasNoFormErrors();

    expect($video->fresh()->url)->toBe('https://cdn.example.test/new.mp4')->and($video->fresh()->file)->toBeNull();

    Storage::disk(HomeVideo::DISK)->assertMissing('videos/home/old.mp4');
});

it('deletes the uploaded file with the video', function (): void {
    Storage::fake(HomeVideo::DISK);
    Storage::disk(HomeVideo::DISK)->put('videos/home/gone.mp4', 'x');

    $video = homeVideo(['source' => HomeVideo::UPLOAD, 'url' => null, 'file' => 'videos/home/gone.mp4']);
    $video->delete();

    Storage::disk(HomeVideo::DISK)->assertMissing('videos/home/gone.mp4');
});

it('adds the standard videos from a button on the list', function (): void {
    videoAdmin();

    Livewire::test(ListHomeVideos::class)->callAction('importStandard')->assertNotified();

    expect(HomeVideo::query()->count())->toBe(count(config('homepage.videos')));
});

it('is not for a member of staff with no permissions', function (): void {
    videoAdmin(admin: false);

    expect(HomeVideoResource::canViewAny())->toBeFalse();

    $this->get(HomeVideoResource::getUrl('index'))->assertForbidden();
});

it('is for staff who manage products', function (): void {
    videoAdmin(['catalog:manage-products'], admin: false);

    expect(HomeVideoResource::canViewAny())->toBeTrue();

    $this->get(HomeVideoResource::getUrl('index'))->assertOk();
});

it('uses UK English and no em dashes in its copy', function (): void {
    foreach (['views/pages/videos.blade.php'] as $file) {
        $text = strip_tags((string) file_get_contents(resource_path($file)));

        expect($text)->not->toContain('—');
    }
});
