<?php

declare(strict_types=1);

function trustStrip(string $html): string
{
    preg_match('#<section[^>]*data-home-trust.*?</section>#s', $html, $section);

    return $section[0] ?? '';
}

it('shows the three confirmed claims and no delivery claim', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();
    $strip = trustStrip($html);

    preg_match_all('#<li[^>]*>\s*<span[^>]*></span>\s*(.*?)\s*</li>#s', $strip, $claims);

    expect($claims[1])->toBe(['Prices exclusive of VAT', 'Safety footwear to EN ISO 20345', 'Wide range of sizes'])
        ->and(strtolower($strip))->not->toContain('deliver')
        ->and($strip)->toMatch('#<h2 id="home-trust" class="sr-only">#');
});

it('sits directly under the hero, before the category tiles', function (): void {
    $html = $this->get(route('home'))->getContent();

    expect(strpos($html, 'data-home-hero'))->toBeLessThan(strpos($html, 'data-home-trust'))
        ->and(strpos($html, 'data-home-trust'))->toBeLessThan(strpos($html, 'data-home-categories'));
});

it('reads its claims from config and skips empty slots', function (): void {
    config()->set('homepage.trust', ['One claim', '', '  ', 'Another claim']);

    preg_match_all('#<li[^>]*>\s*<span[^>]*></span>\s*(.*?)\s*</li>#s', trustStrip($this->get(route('home'))->getContent()), $claims);

    expect($claims[1])->toBe(['One claim', 'Another claim']);
});

it('renders a delivery claim once the client confirms it', function (): void {
    config()->set('homepage.trust', ['A', 'B', 'C', 'Delivery claim']);

    expect(trustStrip($this->get(route('home'))->getContent()))->toContain('Delivery claim');
});

it('renders nothing when every slot is empty', function (): void {
    config()->set('homepage.trust', ['', '']);

    expect(trustStrip($this->get(route('home'))->getContent()))->toBe('');
});

it('ships the fourth slot empty', function (): void {
    $config = require config_path('homepage.php');

    expect($config['trust'])->toHaveCount(4)->and($config['trust'][3])->toBe('');
});

it('is semantic: one h1, a heading for every section, and lazy images below the fold', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1);

    foreach (['data-home-hero' => 'home-hero-title', 'data-home-trust' => 'home-trust', 'data-home-categories' => 'home-categories', 'data-home-standards' => 'home-standards'] as $marker => $id) {
        preg_match('#<section[^>]*'.$marker.'[^>]*>#', $html, $open);

        expect($open[0] ?? '')->toContain('aria-labelledby="'.$id.'"')
            ->and($html)->toContain('id="'.$id.'"');
    }

    $from = (int) strpos($html, 'data-home-trust');
    $below = substr($html, $from, (int) strpos($html, '</main>') - $from);
    preg_match_all('#<img[^>]*>#', $below, $images);

    foreach ($images[0] as $img) {
        expect($img)->toContain('loading="lazy"')->toContain('width=')->toContain('height=');
    }
});

it('has no hard-coded paths in the home templates', function (): void {
    foreach (['home.blade.php', 'components/home/hero.blade.php', 'components/home/category-tiles.blade.php', 'components/home/standard-chips.blade.php', 'components/home/trust-strip.blade.php'] as $file) {
        expect((string) file_get_contents(resource_path('views/'.$file)))->not->toMatch('#href="/#');
    }
});
