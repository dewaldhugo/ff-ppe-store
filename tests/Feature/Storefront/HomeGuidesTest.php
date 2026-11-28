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

/**
 * The size guide card as a photo behind the card (an `image` item), beside the technical features card as it is: for the tests of the
 * photo and its overlay, which the default cards no longer use.
 */
function usePhotoGuideCard(): void
{
    $items = config('homepage.guides.items');

    unset($items[0]['panel']);
    $items[0]['image'] = ['file' => 'guide-size-guide-panel.jpg', 'width' => 816, 'height' => 550];

    config(['homepage.guides.items' => $items]);
}

it('shows a card for the size guide and one for the technical features, each linking by route', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#data-home-guide>.*?<h3[^>]*>(.*?)</h3>.*?<a href="([^"]+)"#s', $section, $cards, PREG_SET_ORDER);

    expect($section)->toContain('Buying guides')->toContain('aria-labelledby="home-guides"')->toContain('id="home-guides"')
        ->and($cards)->toHaveCount(2)
        ->and($cards[0][1])->toBe('Size guide')
        ->and($cards[0][2])->toBe(route('size-guide'))
        ->and($cards[1][1])->toBe('Technical features')
        ->and($cards[1][2])->toBe(route('technical-features'))
        ->and($section)->toContain('Open the size guide')->toContain('See technical features')->not->toContain('See the technical features');
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

it('can still put a photo behind a card under a charcoal overlay, with white text on both cards', function (): void {
    usePhotoGuideCard();

    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<img[^>]*data-guide-image[^>]*>#', $section, $images);
    preg_match_all('#<span[^>]*data-guide-overlay[^>]*>#', $section, $overlays);
    preg_match_all('#<h3 class="([^"]*)"#', $section, $headings);

    // One photo card (the size guide's, as set up here); the technical features card keeps its diagram panel.
    expect($images[0])->toHaveCount(1)
        ->and($images[0][0])->toContain(asset('images/home/guide-size-guide-panel.jpg'))
        ->and($overlays[0])->toHaveCount(1)
        ->and($overlays[0][0])->toContain('bg-brand-charcoal/70')->not->toContain('bg-brand-navy')->toContain('aria-hidden="true"')
        ->and($headings[1])->toHaveCount(2)
        ->and($headings[1][0])->toContain('text-white')->not->toContain('text-ink')
        ->and($headings[1][1])->toContain('text-white')->not->toContain('text-ink')
        ->and($section)->toContain('surface-dark');

    // Decorative, lazy and sized, like every image below the fold.
    expect($images[0][0])->toContain('alt=""')->toContain('loading="lazy"')->toContain('width="816"')->toContain('height="550"')->toContain('object-cover');
});

it('has the two panel pictures on disk at the size the config says, small enough for a card', function (): void {
    $items = (require config_path('homepage.php'))['guides']['items'];

    foreach ($items as $item) {
        $picture = $item['image'] ?? $item['panel'];
        $path = public_path('images/home/'.$picture['file']);

        expect(is_file($path))->toBeTrue("Missing {$path}")
            ->and(getimagesize($path)[0])->toBe($picture['width'])
            ->and(getimagesize($path)[1])->toBe($picture['height'])
            ->and(filesize($path))->toBeLessThan(120_000);
    }

    // The old background photos of the two cards are gone: nothing uses them.
    expect(is_file(public_path('images/home/guide-size-guide.jpg')))->toBeFalse()
        ->and(is_file(public_path('images/home/guide-technical-features.jpg')))->toBeFalse();
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

it('draws each photo card on brand-charcoal, like the footer and the survey band, not navy', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<div class="([^"]*group relative[^"]*)">#', $section, $cards);

    expect($cards[1])->toHaveCount(2);

    foreach ($cards[1] as $card) {
        expect($card)->toContain('bg-brand-charcoal')->toContain('border-brand-charcoal')->toContain('surface-dark')
            ->not->toContain('bg-brand-navy')->toContain('min-h-[14rem]')->not->toContain('min-h-[16rem]');
    }
});

it('keeps the overlay at 62% charcoal or more across a photo card, since the words run its full width over a bright photo', function (): void {
    usePhotoGuideCard();

    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<span[^>]*data-guide-overlay[^>]*>#', $section, $overlays);

    expect($overlays[0])->toHaveCount(1);

    foreach ($overlays[0] as $overlay) {
        // An even 70% below lg, and from lg a gradient from 80% to 62%: never under the 62% that a bright photo needs for white text to keep 4.5:1.
        expect($overlay)->toContain('bg-brand-charcoal/70')->toContain('lg:bg-transparent')->toContain('lg:bg-linear-to-r')
            ->toContain('lg:from-brand-charcoal/80')->toContain('lg:to-brand-charcoal/62')
            ->not->toContain('/85')->not->toContain('/95')->not->toContain('/20')->not->toContain('lg:via');
    }

    // The words run the full width of their column: no maximum width on the paragraph.
    preg_match_all('#<p class="([^"]*)">(?!<)#', $section, $paragraphs);

    $cardParagraphs = collect($paragraphs[1])->filter(fn (string $class): bool => str_contains($class, 'flex-1'));

    expect($cardParagraphs)->toHaveCount(2)
        ->and($cardParagraphs->contains(fn (string $class): bool => str_contains($class, 'max-w')))->toBeFalse();
});

it('adds the file\'s modified time to the address of each panel picture, so a replaced one is fetched again', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<img[^>]*data-guide-diagram[^>]*>#', $section, $pictures);

    expect($pictures[0])->toHaveCount(2);

    foreach ([['guide-size-guide-panel.jpg', 0], ['guide-technical-features-diagram.webp', 1]] as [$file, $index]) {
        expect($pictures[0][$index])->toContain('src="'.asset('images/home/'.$file).'?v='.filemtime(public_path('images/home/'.$file)).'"');
    }
});

it('adds the file\'s modified time to the address of a card photo too', function (): void {
    usePhotoGuideCard();

    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match('#<img[^>]*data-guide-image[^>]*>#', $section, $photo);

    expect($photo[0])->toContain('src="'.asset('images/home/guide-size-guide-panel.jpg').'?v='.filemtime(public_path('images/home/guide-size-guide-panel.jpg')).'"');
});

it('makes the link a teal button with navy words, white on hover, that still covers the whole card', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<a href="[^"]+" class="([^"]*)"#', $section, $links);

    expect($links[1])->toHaveCount(2);

    foreach ($links[1] as $class) {
        expect($class)->toContain('bg-brand-teal')->toContain('text-brand-navy')->toContain('rounded-md')->toContain('px-5')->toContain('py-2.5')
            ->toContain('w-fit')->toContain('hover:bg-white')->toContain('after:absolute after:inset-0')
            ->not->toContain('hover:underline')->not->toContain('text-white');
    }
});

it('puts the icon on a teal chip with a navy icon on a photo card, and a light teal one on a plain card', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<span class="([^"]*)" data-guide-icon>#', $section, $chips);

    expect($chips[1])->toHaveCount(2);

    foreach ($chips[1] as $chip) {
        expect($chip)->toContain('bg-brand-teal')->not->toContain('bg-brand-teal-light')->toContain('text-brand-navy')->not->toContain('bg-white/15');
    }

    config(['homepage.guides.items' => [['route' => 'size-guide', 'icon' => 'ruler', 'title' => 'Plain', 'text' => 'No photo', 'link' => 'Go']]]);

    $plain = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match('#<span class="([^"]*)" data-guide-icon>#', $plain, $chip);
    preg_match('#<a href="[^"]+" class="([^"]*)"#', $plain, $link);

    expect($chip[1])->toContain('bg-brand-teal-light')->toContain('text-brand-navy')
        // The button is the same on a plain card, with a light teal hover (white would vanish on it).
        ->and($link[1])->toContain('bg-brand-teal')->toContain('hover:bg-brand-teal-light')->not->toContain('hover:bg-white');
});

it('puts a picture on a white panel beside the words on both cards, shown whole and with no overlay', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<li class="flex" data-home-guide>.*?</li>#s', $section, $cards);

    expect($cards[0])->toHaveCount(2);

    foreach ($cards[0] as $card) {
        preg_match('#<div[^>]*data-guide-panel[^>]*>#', $card, $panel);
        preg_match('#<img[^>]*data-guide-diagram[^>]*>#', $card, $picture);
        preg_match('#<div class="([^"]*)"[^>]*data-guide-words>#', $card, $words);

        expect($panel[0])->toContain('bg-white')->toContain('rounded-md')->toContain('lg:basis-1/2')
            ->and($picture[0])->toContain('width="816"')->toContain('height="550"')->toContain('loading="lazy"')->toContain('h-auto w-full')->not->toContain('object-cover')
            // No photo and no overlay behind the card: the picture is on its panel, not a background.
            ->and($card)->not->toContain('data-guide-image')->not->toContain('data-guide-overlay')
            ->and($card)->toContain('bg-brand-charcoal')->toContain('lg:flex-row')
            // The words take their half beside it.
            ->and($words[1])->toContain('lg:basis-1/2');
    }

    // Each has its own words for the picture, not an empty alt.
    preg_match_all('#<img[^>]*data-guide-diagram[^>]*alt="([^"]+)"#s', $section, $alts);

    expect($alts[1])->toHaveCount(2)
        ->and($alts[1][0])->toContain('safety boot')->toContain('scaffold')
        ->and($alts[1][1])->toContain('A cutaway of a Safety Jogger safety boot');
});

it('has no panel on a card that has a photo, and the row layout only on a card with a panel', function (): void {
    usePhotoGuideCard();

    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<li class="flex" data-home-guide>.*?</li>#s', $section, $cards);

    [$photo, $panel] = $cards[0];

    expect($photo)->toContain('data-guide-image')->not->toContain('data-guide-panel')->not->toContain('lg:flex-row')->not->toContain('lg:basis-1/2')
        ->and($panel)->toContain('data-guide-panel')->toContain('lg:flex-row');
});

it('puts the panel below the words on a phone, and keeps each card one link', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<li class="flex" data-home-guide>.*?</li>#s', $section, $cards);

    foreach ($cards[0] as $card) {
        // The words come first in the markup, the panel after them (so it is below them until lg, where the row starts); the panel has a top
        // margin for that and none from lg.
        expect(strpos($card, 'data-guide-words'))->toBeLessThan(strpos($card, 'data-guide-panel'))
            ->and($card)->toContain('mt-6')->toContain('lg:mt-0')
            // One link, and it still covers the whole card (the panel is not a second link).
            ->and(substr_count($card, '<a '))->toBe(1)
            ->and($card)->toContain('after:absolute after:inset-0');
    }
});

it('gives a card with no alt words for its panel an empty alt, and prefers a photo to a panel', function (): void {
    config(['homepage.guides.items' => [[
        'route' => 'size-guide', 'title' => 'Both', 'text' => 'A photo and a panel',
        'image' => ['file' => 'guide-size-guide.jpg', 'width' => 1000, 'height' => 459],
        'panel' => ['file' => 'guide-technical-features-diagram.webp', 'width' => 816, 'height' => 550],
    ], [
        'route' => 'technical-features', 'title' => 'Panel only', 'text' => 'No alt',
        'panel' => ['file' => 'guide-technical-features-diagram.webp', 'width' => 816, 'height' => 550],
    ]]]);

    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<li class="flex" data-home-guide>.*?</li>#s', $section, $cards);

    // A card with a photo shows the photo and no panel; a card with a panel and no alt words has alt="".
    expect($cards[0][0])->toContain('data-guide-image')->not->toContain('data-guide-panel')
        ->and($cards[0][1])->toContain('data-guide-panel')->toContain('alt=""');
});

it('runs the size guide photo edge to edge in its panel, and keeps the padding round the technical features diagram', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<div class="([^"]*)" data-guide-panel>#', $section, $panels);

    expect($panels[1])->toHaveCount(2)
        // The size guide's photo: no padding, and the corners of the panel clip the picture.
        ->and($panels[1][0])->not->toContain('p-3')->toContain('overflow-hidden')->toContain('rounded-md')->toContain('bg-white')
        // The diagram has a white margin, as before.
        ->and($panels[1][1])->toContain('p-3')->toContain('overflow-hidden')->toContain('bg-white');
});

it('lets an item choose whether its panel has padding', function (): void {
    config(['homepage.guides.items' => [
        ['route' => 'size-guide', 'title' => 'Bare', 'text' => 'No padding', 'panel' => ['file' => 'guide-size-guide-panel.jpg', 'width' => 816, 'height' => 550, 'padded' => false]],
        ['route' => 'technical-features', 'title' => 'Padded', 'text' => 'Padding', 'panel' => ['file' => 'guide-technical-features-diagram.webp', 'width' => 816, 'height' => 550]],
    ]]);

    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<div class="([^"]*)" data-guide-panel>#', $section, $panels);

    expect($panels[1][0])->not->toContain('p-3')->and($panels[1][1])->toContain('p-3');
});

it('sets the card paragraphs 10% smaller: 0.9rem, not the 1rem of body text', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<p class="([^"]*)">(?!<)#', $section, $paragraphs);

    $card = collect($paragraphs[1])->filter(fn (string $class): bool => str_contains($class, 'flex-1'));

    expect($card)->toHaveCount(2);

    foreach ($card as $class) {
        expect($class)->toContain('text-[0.9rem]')->toContain('text-white');
    }
});

it('words the technical features button "See technical features"', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<a href="([^"]+)" class="[^"]*">\s*(.*?)\s*<svg#s', $section, $links, PREG_SET_ORDER);

    expect($links)->toHaveCount(2)
        ->and($links[0][2])->toBe('Open the size guide')
        ->and($links[1][1])->toBe(route('technical-features'))
        ->and($links[1][2])->toBe('See technical features');
});

it('lines the bottom of the panel up with the bottom of the button, from lg where they sit side by side', function (): void {
    $section = guidesSection($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<li class="flex" data-home-guide>\s*<div class="([^"]*)"#s', $section, $cards);

    expect($cards[1])->toHaveCount(2);

    foreach ($cards[1] as $class) {
        // A row that lines its two blocks up at the bottom: the words block ends with the button, the panel ends with its picture.
        expect($class)->toContain('lg:flex-row')->toContain('lg:items-end')->not->toContain('lg:items-center')->not->toContain('lg:items-start');
    }

    // The button is the last thing in the words block, so its bottom is the bottom of that block.
    preg_match_all('#data-guide-words>.*?(</a>)\s*</div>\s*@?#s', $section, $words);

    foreach ($words[0] as $block) {
        expect(substr_count($block, '<a '))->toBe(1);
    }
});
