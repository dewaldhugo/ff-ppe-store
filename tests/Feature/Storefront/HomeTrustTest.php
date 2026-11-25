<?php

declare(strict_types=1);

use Tests\Support\HomeSpotlights;

function trustStrip(string $html): string
{
    preg_match('#<section[^>]*data-home-trust.*?</section>#s', $html, $section);

    return $section[0] ?? '';
}

it('shows the three claims, in order, and no delivery claim', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();
    $strip = trustStrip($html);

    preg_match_all('#<li[^>]*>\s*<svg.*?</svg>\s*(.*?)\s*</li>#s', $strip, $claims);

    expect($claims[1])->toBe(['Exclusive trade pricing', 'Free wear trials for businesses', 'Safety footwear to EN ISO 20345'])
        ->and(strtolower($strip))->not->toContain('deliver')
        ->and($strip)->toMatch('#<h2 id="home-trust" class="sr-only">#');
});

it('sits inside the hero at the bottom, as a full width band of bordered thirds with an icon per claim', function (): void {
    $html = $this->get(route('home'))->getContent();
    $strip = trustStrip($html);

    expect(strpos($html, 'data-home-trust'))->toBeLessThan(strpos($html, 'data-home-industries'))
        ->and(strpos($html, 'data-home-trust'))->toBeLessThan(strpos($html, 'data-home-products'))
        ->and($strip)->toContain('trust-scrim')->toContain('w-full')
        ->and($strip)->toContain('divide-x')->toContain('divide-white/30')
        ->and($strip)->toContain('flex-1')->toContain('basis-0')
        ->and($strip)->toContain('text-[1.134rem]')->not->toContain('text-[1.05rem]')->toContain('font-normal')->not->toContain('font-semibold')
        ->and($strip)->not->toContain('container')
        ->and($strip)->not->toContain('bg-surface-muted')
        ->and(substr_count($strip, 'data-trust-block'))->toBe(3)
        ->and(substr_count($strip, '<svg'))->toBe(3);
});

it('is the last thing inside the hero section, below its content', function (): void {
    $html = $this->get(route('home'))->getContent();

    $heroStart = (int) strpos($html, 'data-home-hero');
    $trust = (int) strpos($html, 'data-home-trust');
    $afterTrust = substr($html, $trust);

    // The hero's closing tag comes right after the strip's own: nothing else sits between them.
    preg_match('#^.*?</section>\s*</section>#s', $afterTrust, $tail);

    expect($trust)->toBeGreaterThan($heroStart)
        ->and($trust)->toBeGreaterThan((int) strpos($html, 'data-hero-text'))
        ->and($tail[0] ?? '')->not->toContain('data-home-industries');
});

it('is hidden on a phone and shown from the sm breakpoint', function (): void {
    $html = $this->get(route('home'))->getContent();

    preg_match('#<section[^>]*data-home-trust[^>]*>#', $html, $open);

    expect($open[0] ?? '')->toContain('hidden')->toContain('sm:block');
});

it('sits after the hero', function (): void {
    $html = $this->get(route('home'))->getContent();

    expect(strpos($html, 'data-home-hero'))->toBeLessThan(strpos($html, 'data-home-trust'));
});

it('gives each claim its own icon', function (): void {
    preg_match_all('#<svg[^>]*>\s*<path d="([^"]+)"#', trustStrip($this->get(route('home'))->getContent()), $paths);

    expect($paths[1])->toHaveCount(3)->and(array_unique($paths[1]))->toHaveCount(3);
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
