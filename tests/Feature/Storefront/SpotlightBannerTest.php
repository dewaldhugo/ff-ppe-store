<?php

declare(strict_types=1);

use App\Admin\Resources\SpotlightBannerResource;
use App\Admin\Resources\SpotlightBannerResource\Pages\CreateSpotlightBanner;
use App\Admin\Resources\SpotlightBannerResource\Pages\EditSpotlightBanner;
use App\Admin\Resources\SpotlightBannerResource\Pages\ListSpotlightBanners;
use App\Domain\Catalogue\Home\HomeSpotlight;
use App\Models\SpotlightBanner;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Product;

// The home page's Product Spotlight campaigns are banners with a product, managed in the admin (Spotlight banners); one active campaign
// is picked at random on every page load. While none have been added the campaigns of config/homepage.php are used.

beforeEach(function (): void {
    config()->set('store.noindex', true);
    Storage::fake(SpotlightBanner::DISK);
});

/** A banner whose picture is really on the (fake) disk, for a published product. */
function spotlightBanner(Product $product, array $overrides = []): SpotlightBanner
{
    $path = 'spotlights/'.uniqid('banner-', true).'.jpg';

    Storage::disk(SpotlightBanner::DISK)->put($path, UploadedFile::fake()->image('banner.jpg', 600, 750)->getContent());

    return SpotlightBanner::query()->create([
        'product_id' => $product->id,
        'image' => $path,
        'alt' => null,
        'active' => true,
        'position' => 1,
        ...$overrides,
    ]);
}

function publishedProducts(int $count = 2): array
{
    return Product::query()->where('status', 'published')->orderBy('id')->limit($count)->get()->all();
}

function spotlightAdmin(array $permissions = [], bool $admin = true): Staff
{
    $staff = Staff::factory()->create(['admin' => $admin]);

    foreach ($permissions as $permission) {
        $staff->givePermissionTo($permission);
    }

    test()->actingAs($staff, 'staff');
    Filament::setCurrentPanel(Filament::getPanel('lunar'));

    return $staff;
}

// --- What the home page shows ----------------------------------------------------------------------

it('uses the campaigns of the config while no banner has been added', function (): void {
    $slugs = array_map(fn (array $campaign): string => $campaign['spotlight']['slug'], app(HomeSpotlight::class)->available());

    expect($slugs)->each->toBeIn(array_column(config('homepage.spotlights'), 'slug'));
});

it('uses the banners added in the admin instead, with the product each is about', function (): void {
    [$one, $two] = publishedProducts();

    spotlightBanner($one, ['position' => 1]);
    spotlightBanner($two, ['position' => 2]);

    $available = app(HomeSpotlight::class)->available();

    expect($available)->toHaveCount(2)
        ->and(array_map(fn (array $campaign): int => $campaign['product']->id, $available))->toBe([$one->id, $two->id])
        ->and($available[0]['spotlight']['image_url'])->toContain('spotlights/')
        ->and($available[0]['spotlight']['width'])->toBe(600)->and($available[0]['spotlight']['height'])->toBe(750)
        // The product's name stands in when the banner has no description of its own.
        ->and($available[0]['spotlight']['alt'])->toBe((string) $one->translateAttribute('name'));
});

it('leaves out a banner that is switched off, or whose product is not published, or whose picture is gone', function (): void {
    [$one, $two] = publishedProducts();

    spotlightBanner($one, ['active' => false]);
    $draft = spotlightBanner($two);
    $draft->product->update(['status' => 'draft']);
    $missing = spotlightBanner($one, ['position' => 3]);
    Storage::disk(SpotlightBanner::DISK)->delete($missing->image);

    expect(app(HomeSpotlight::class)->available())->toBe([])
        ->and(app(HomeSpotlight::class)->pick())->toBeNull();
});

it('shows the banner picture and the product on the home page', function (): void {
    [$one] = publishedProducts(1);

    spotlightBanner($one, ['alt' => 'A boot on a plinth']);

    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-featured.*?</section>#s', $html, $section);

    expect($section[0] ?? '')->toContain('data-spotlight')->toContain('alt="A boot on a plinth"')->toContain('spotlights/')
        ->toContain('width="600"')->toContain('height="750"')->toContain(route('product', $one->defaultUrl->slug));
});

it('picks one of the active banners at random on each page load', function (): void {
    [$one, $two] = publishedProducts();

    spotlightBanner($one);
    spotlightBanner($two, ['position' => 2]);

    $seen = [];

    foreach (range(1, 60) as $ignored) {
        $seen[app(HomeSpotlight::class)->pick()['product']->id] = true;
    }

    expect(array_keys($seen))->toEqualCanonicalizing([$one->id, $two->id]);
});

it('shows no spotlight when banners exist but none can be shown', function (): void {
    [$one] = publishedProducts(1);

    spotlightBanner($one, ['active' => false]);

    $this->get(route('home'))->assertOk()->assertDontSee('data-home-featured', false);
});

// --- The admin -------------------------------------------------------------------------------------

it('lists the existing banners in the admin with their product', function (): void {
    spotlightAdmin();
    [$one, $two] = publishedProducts();

    $first = spotlightBanner($one);
    $second = spotlightBanner($two, ['position' => 2]);

    Livewire::test(ListSpotlightBanners::class)->assertCanSeeTableRecords([$first, $second])->assertSee((string) $one->translateAttribute('name'));
});

it('adds a banner with a product and a picture', function (): void {
    spotlightAdmin();
    [$one] = publishedProducts(1);

    Livewire::test(CreateSpotlightBanner::class)
        ->fillForm(['product_id' => $one->id, 'image' => UploadedFile::fake()->image('campaign.jpg', 800, 1000), 'alt' => 'Our campaign', 'active' => true])
        ->call('create')->assertHasNoFormErrors();

    $banner = SpotlightBanner::query()->firstOrFail();

    expect($banner->product_id)->toBe($one->id)->and($banner->alt)->toBe('Our campaign');

    Storage::disk(SpotlightBanner::DISK)->assertExists($banner->image);
});

it('needs a product and a picture, and refuses a file that is not an image', function (): void {
    spotlightAdmin();
    [$one] = publishedProducts(1);

    Livewire::test(CreateSpotlightBanner::class)->fillForm(['product_id' => null])->call('create')->assertHasFormErrors(['product_id', 'image']);
    Livewire::test(CreateSpotlightBanner::class)
        ->fillForm(['product_id' => $one->id, 'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
        ->call('create')->assertHasFormErrors(['image']);

    expect(SpotlightBanner::query()->count())->toBe(0);
});

it('edits a banner, and replacing the picture deletes the old one', function (): void {
    spotlightAdmin();
    [$one, $two] = publishedProducts();

    $banner = spotlightBanner($one);
    $old = $banner->image;

    Livewire::test(EditSpotlightBanner::class, ['record' => $banner->getKey()])
        ->fillForm(['product_id' => $two->id, 'image' => UploadedFile::fake()->image('new.jpg', 800, 1000), 'active' => false])
        ->call('save')->assertHasNoFormErrors();

    expect($banner->fresh()->product_id)->toBe($two->id)->and($banner->fresh()->active)->toBeFalse()->and($banner->fresh()->image)->not->toBe($old);

    Storage::disk(SpotlightBanner::DISK)->assertMissing($old);
    Storage::disk(SpotlightBanner::DISK)->assertExists($banner->fresh()->image);
});

it('deletes the picture with the banner', function (): void {
    [$one] = publishedProducts(1);

    $banner = spotlightBanner($one);
    $path = $banner->image;
    $banner->delete();

    Storage::disk(SpotlightBanner::DISK)->assertMissing($path);
});

it('copies the standard campaigns into the list once, with their pictures, and not again', function (): void {
    $added = app(HomeSpotlight::class)->importStandard();

    expect($added)->toBe(count(app(HomeSpotlight::class)->available()))->and($added)->toBeGreaterThan(0)
        ->and(SpotlightBanner::query()->count())->toBe($added)
        ->and(app(HomeSpotlight::class)->importStandard())->toBe(0);

    foreach (SpotlightBanner::query()->get() as $banner) {
        Storage::disk(SpotlightBanner::DISK)->assertExists($banner->image);
    }
});

it('adds the standard banners from a button on the list', function (): void {
    spotlightAdmin();

    Livewire::test(ListSpotlightBanners::class)->callAction('importStandard')->assertNotified();

    expect(SpotlightBanner::query()->count())->toBeGreaterThan(0);
});

it('is not for a member of staff with no permissions', function (): void {
    spotlightAdmin(admin: false);

    expect(SpotlightBannerResource::canViewAny())->toBeFalse();

    $this->get(SpotlightBannerResource::getUrl('index'))->assertForbidden();
});

it('is for staff who manage products', function (): void {
    spotlightAdmin(['catalog:manage-products'], admin: false);

    expect(SpotlightBannerResource::canViewAny())->toBeTrue();

    $this->get(SpotlightBannerResource::getUrl('index'))->assertOk();
});
