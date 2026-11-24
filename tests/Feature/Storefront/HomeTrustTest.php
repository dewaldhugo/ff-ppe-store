<?php

declare(strict_types=1);

use Tests\Support\HomeSpotlights;

function trustStrip(string $html): string
{
    preg_match('#<section[^>]*data-home-trust.*?</section>#s', $html, $section);

    return $section[0] ?? '';
}

it('shows the three confirmed claims and no delivery claim', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();
    $strip = trustStrip($html);

    preg_match_all('#<li[^>]*>\s*<svg.*?</svg>\s*(.*?)\s*</li>#s', $strip, $claims);

    expect($claims[1])->toBe(['Prices exclusive of VAT', 'Safety footwear to EN ISO 20345', 'Wide range of sizes'])
        ->and(strtolower($strip))->not->toContain('deliver')
        ->and($strip)->toMatch('#<h2 id="home-trust" class="sr-only">#');
});

it('sits straight under the hero, before the industries slider and the products, on a centred light grey band with an icon per claim', function (): void {
    $html = $this->get(route('home'))->getContent();
    $strip = trustStrip($html);

    expect(strpos($html, 'data-home-trust'))->toBeLessThan(strpos($html, 'data-home-industries'))
        ->and(strpos($html, 'data-home-trust'))->toBeLessThan(strpos($html, 'data-home-products'))
        ->and($strip)->toContain('bg-surface-muted')
        ->and($strip)->toContain('sm:justify-center')
        ->and($strip)->toContain('text-[1.05rem]')
        ->and(substr_count($strip, '<svg'))->toBe(3);
});

it('sits after the hero', function (): void {
    $html = $this->get(route('home'))->getContent();

    expect(strpos($html, 'data-home-hero'))->toBeLessThan(strpos($html, 'data-home-trust'));
});

it('reads its claims from config and skips empty slots', function (): void {
    config()->set('homepage.trust', ['One claim', '', '  ', 'Another claim']);

    preg_match_all('#<li[^>]*>\s*<svg.*?</svg>\s*(.*?)\s*</li>#s', trustStrip($this->get(route('home'))->getContent()), $claims);

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
    HomeSpotlights::seed();

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1);

    foreach (['data-home-hero' => 'home-hero-title', 'data-home-trust' => 'home-trust', 'data-home-products' => 'home-products', 'data-home-featured' => 'home-featured'] as $marker => $id) {
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
    foreach (['home.blade.php', 'components/home/hero.blade.php', 'components/home/product-picks.blade.php', 'components/home/standard-chips.blade.php', 'components/home/trust-strip.blade.php', 'components/home/brand-strip.blade.php', 'components/home/guides.blade.php'] as $file) {
        expect((string) file_get_contents(resource_path('views/'.$file)))->not->toMatch('#href="/#');
    }
});

it('ends the home page with the service strip, just above the footer', function (): void {
    HomeSpotlights::seed();

    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<section[^>]*data-home-service.*?</section>#s', $html, $section);
    $strip = $section[0] ?? '';

    expect($strip)->toContain('aria-labelledby="home-service"')
        ->and($strip)->toContain('UK &amp; EU Shipping')
        ->and($strip)->toContain('Best Offers')
        ->and($strip)->toContain('Secure Payments')
        ->and($strip)->toContain('24/7 Support')
        ->and(substr_count($strip, '<svg'))->toBe(4)
        ->and(substr_count($strip, 'aria-hidden="true"'))->toBe(4)
        ->and(strpos($html, 'data-home-featured'))->toBeLessThan(strpos($html, 'data-home-service'));

    // Nothing but the end of <main> sits between the strip and the footer.
    $after = substr($html, (int) strpos($html, 'data-home-service'));
    $between = substr($after, (int) strpos($after, '</section>') + 10, (int) strpos($after, '<footer') - (int) strpos($after, '</section>') - 10);

    expect($between)->not->toContain('<section');
});
