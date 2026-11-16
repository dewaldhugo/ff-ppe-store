<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Product;

/** Publish exactly one product and return it. Every other product becomes a draft. */
function onlyPublished(): Product
{
    // The fixture catalogue seeds image rows whose files are not on the faked disk.
    DB::table('media')->delete();

    $product = Product::query()->where('status', 'published')->firstOrFail();
    Product::query()->whereKeyNot($product->id)->update(['status' => 'draft']);

    return $product;
}

function attachImage(Product $product): void
{
    $image = imagecreatetruecolor(40, 40);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    $product->addMediaFromString($png)
        ->usingFileName('boot.png')
        ->toMediaCollection(config('lunar.media.collection'));
}

beforeEach(function (): void {
    Storage::fake('products');
});

it('fails and lists the first 20 product ids when published products have no image', function (): void {
    DB::table('media')->delete();

    $template = Product::query()->where('status', 'published')->firstOrFail();

    for ($i = 0; $i < 15; $i++) {
        $template->replicate()->save();
    }

    $published = Product::query()->where('status', 'published')->count();

    expect($published)->toBeGreaterThan(20);

    $this->artisan('catalogue:verify-media')
        ->expectsOutputToContain("{$published} published products with no original image. First 20 product IDs:")
        ->assertFailed();
});

it('passes when every published product has its image and conversions', function (): void {
    attachImage(onlyPublished());

    $this->artisan('catalogue:verify-media')
        ->expectsOutputToContain('Checked 1 published products')
        ->assertSuccessful();
});

it('ignores draft products', function (): void {
    Product::query()->update(['status' => 'draft']);

    $this->artisan('catalogue:verify-media')->assertSuccessful();
});

it('reports a missing generated conversion', function (): void {
    $product = onlyPublished();
    attachImage($product);

    $media = $product->refresh()->getFirstMedia(config('lunar.media.collection'));
    $generated = $media->generated_conversions;
    unset($generated['medium']);
    $media->generated_conversions = $generated;
    $media->save();

    $this->artisan('catalogue:verify-media')
        ->expectsOutputToContain('1 published products with an image missing a generated conversion')
        ->expectsOutputToContain((string) $product->id)
        ->assertFailed();
});

it('only checks the files on disk with --check-files', function (): void {
    $product = onlyPublished();
    attachImage($product);

    $media = $product->refresh()->getFirstMedia(config('lunar.media.collection'));
    Storage::disk('products')->delete($media->getPathRelativeToRoot('thumb'));

    $this->artisan('catalogue:verify-media')->assertSuccessful();

    $this->artisan('catalogue:verify-media', ['--check-files' => true])
        ->expectsOutputToContain('1 published products whose conversion file is missing on disk')
        ->assertFailed();
});

it('reports a missing original file with --check-files', function (): void {
    $product = onlyPublished();
    attachImage($product);

    $media = $product->refresh()->getFirstMedia(config('lunar.media.collection'));
    Storage::disk('products')->delete($media->getPathRelativeToRoot());

    $this->artisan('catalogue:verify-media', ['--check-files' => true])
        ->expectsOutputToContain('1 published products whose original image file is missing on disk')
        ->assertFailed();
});

it('writes nothing', function (): void {
    $product = onlyPublished();
    attachImage($product);
    $before = [DB::table('media')->count(), count(Storage::disk('products')->allFiles())];

    $this->artisan('catalogue:verify-media', ['--check-files' => true])->assertSuccessful();

    expect([DB::table('media')->count(), count(Storage::disk('products')->allFiles())])->toBe($before);
});
