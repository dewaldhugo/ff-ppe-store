<?php

declare(strict_types=1);

// The buying guides row on the home page (<x-home.guides>), from config/homepage.php: a card each for the size guide and the
// technical features page, between the video and the testimonials.

/** The guides section of the home page, or an empty string. */
function guidesSection(string $html): string
{
    preg_match('#<section[^>]*data-home-guides.*?</section>#s', $html, $section);

    return $section[0] ?? '';
}

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('shows a card for the size guide and one for the technical features, each linking by route', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#data-home-guide>.*?<h3[^>]*>(.*?)</h3>.*?<a href="([^"]+)"#s', $section, $cards, PREG_SET_ORDER);

    expect($section)->toContain('Buying guides')->toContain('aria-labelledby="home-guides"')->toContain('id="home-guides"')
        ->and($cards)->toHaveCount(2)
        ->and($cards[0][1])->toBe('Size guide')
        ->and($cards[0][2])->toBe(route('size-guide'))
        ->and($cards[1][1])->toBe('Technical features')
        ->and($cards[1][2])->toBe(route('technical-features'))
        ->and($section)->toContain('Open the size guide')->toContain('See the technical features');
});

it('puts an icon next to each heading, a ruler for the size guide and a shield for the technical features', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<div class="flex items-center gap-3">\s*<span[^>]*data-guide-icon>\s*<svg[^>]*aria-hidden="true">(.*?)</svg>\s*</span>\s*<h3[^>]*>(.*?)</h3>#s', $section, $cards, PREG_SET_ORDER);

    expect($cards)->toHaveCount(2)
        ->and($cards[0][2])->toBe('Size guide')
        ->and($cards[0][1])->toContain('M7.5 7.5v3M12 7.5v4.5M16.5 7.5v3')
        ->and($cards[1][2])->toBe('Technical features')
        ->and($cards[1][1])->toContain('M9 12.75 11.25 15 15 9.75');
});

it('puts a photo behind each card under a dark overlay, with white text', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<img[^>]*data-guide-image[^>]*>#', $section, $images);
    preg_match_all('#<span[^>]*data-guide-overlay[^>]*>#', $section, $overlays);
    preg_match_all('#<h3 class="([^"]*)"#', $section, $headings);

    expect($images[0])->toHaveCount(2)
        ->and($images[0][0])->toContain(asset('images/home/guide-size-guide.jpg'))
        ->and($images[0][1])->toContain(asset('images/home/guide-technical-features.jpg'))
        ->and($overlays[0])->toHaveCount(2)
        ->and($overlays[0][0])->toContain('bg-brand-navy/75')->toContain('aria-hidden="true"')
        ->and($headings[1][0])->toContain('text-white')->not->toContain('text-ink')
        ->and($section)->toContain('surface-dark')->not->toContain('bg-white ');

    // Decorative, lazy and sized, like every image below the fold.
    foreach ($images[0] as $img) {
        expect($img)->toContain('alt=""')->toContain('loading="lazy"')->toContain('width="1000"')->toContain('height="459"')->toContain('object-cover');
    }
});

it('has the two photos on disk at the size the config says, small enough for a card', function (): void {
    $items = (require config_path('homepage.php'))['guides']['items'];

    foreach ($items as $item) {
        $path = public_path('images/home/'.$item['image']['file']);

        expect(is_file($path))->toBeTrue("Missing {$path}")
            ->and(getimagesize($path)[0])->toBe($item['image']['width'])
            ->and(getimagesize($path)[1])->toBe($item['image']['height'])
            ->and(filesize($path))->toBeLessThan(120_000);
    }
});

it('shows a plain white card, with dark text, for an item with no photo', function (): void {
    config(['homepage.guides.items' => [['route' => 'size-guide', 'icon' => 'ruler', 'title' => 'Plain', 'text' => 'No photo']]]);

    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    expect($section)->not->toContain('data-guide-image')->not->toContain('data-guide-overlay')->not->toContain('surface-dark')
        ->and($section)->toContain('bg-white')->toContain('text-ink');
});

it('shows no icon for an item with none, or an unknown one, and keeps its heading', function (): void {
    config(['homepage.guides.items' => [
        ['route' => 'size-guide', 'title' => 'Plain', 'text' => 'No icon'],
        ['route' => 'technical-features', 'icon' => 'no-such-icon', 'title' => 'Unknown', 'text' => 'Bad icon'],
    ]]);

    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    expect($section)->not->toContain('data-guide-icon')->toContain('Plain')->toContain('Unknown');
});

it('sits after the video and before the testimonials', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(strpos($html, 'data-home-video'))->toBeLessThan(strpos($html, 'data-home-guides'))
        ->and(strpos($html, 'data-home-guides'))->toBeLessThan(strpos($html, 'data-home-testimonials'));
});

it('makes the whole card the link, and keeps the arrow decorative', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    // Two icons beside the headings and two arrows in the links: four pictures, all decoration.
    preg_match_all('#<svg[^>]*>#', $section, $pictures);

    expect($section)->toContain('after:absolute after:inset-0')
        ->and(substr_count($section, '<a '))->toBe(2)
        ->and($pictures[0])->toHaveCount(4);

    foreach ($pictures[0] as $picture) {
        expect($picture)->toContain('aria-hidden="true"');
    }
});

it('skips an item whose route does not exist, and leaves the section out when none is left', function (): void {
    config(['homepage.guides.items' => [
        ['route' => 'no-such-route', 'title' => 'Ghost', 'text' => 'Nothing', 'link' => 'Go'],
        ['route' => 'size-guide', 'title' => 'Size guide', 'text' => 'Sizes', 'link' => 'Open'],
    ]]);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(guidesSection($html))->toContain('Size guide')->not->toContain('Ghost');

    config(['homepage.guides.items' => [['route' => 'no-such-route', 'title' => 'Ghost']]]);

    expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-home-guides')->not->toContain('Buying guides');
});

it('escapes what the config says', function (): void {
    config(['homepage.guides.items' => [['route' => 'size-guide', 'title' => '<b>Bold</b>', 'text' => 'A & B', 'link' => '<i>Go</i>']]]);

    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    expect($section)->not->toContain('<b>Bold</b>')->not->toContain('<i>Go</i>')
        ->and($section)->toContain('&lt;b&gt;Bold&lt;/b&gt;')->toContain('A &amp; B');
});

it('keeps the new copy free of dashes', function (): void {
    $config = require config_path('homepage.php');

    $texts = [$config['brands']['title']];

    // Every string in the guides config (title, intro, and each item's words, icon and image file).
    array_walk_recursive($config['guides'], function (mixed $value) use (&$texts): void {
        $texts[] = (string) $value;
    });

    foreach ($texts as $text) {
        expect($text)->not->toContain('—')->not->toContain('–');
    }
});
