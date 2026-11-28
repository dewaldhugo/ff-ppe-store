<?php

declare(strict_types=1);

use App\Domain\Catalogue\Menu\CategoryCardImage;

// The home page's Shop by category section: the five top-level categories as on /shop, each with its picture
// (resources/views/home.blade.php, App\Domain\Catalogue\Menu\CategoryCardImage).

it('shows Shop by category after the industries slider and before the PPE Health Check', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    $industries = (int) strpos($html, 'data-home-industries');
    $categories = (int) strpos($html, 'data-home-categories');
    $healthCheck = (int) strpos($html, 'data-home-health-check');

    expect($categories)->toBeGreaterThan($industries)
        ->and($categories)->toBeLessThan($healthCheck)
        ->and($html)->toContain('Shop by category')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('has the five category cards, each a link to its category with its picture', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<div[^>]*data-home-categories.*?</ul>#s', $html, $section);

    expect(substr_count($section[0], 'data-category-image'))->toBe(5)
        ->and($section[0])->toContain('lg:grid-cols-5');

    foreach (['safety-footwear', 'non-safety-footwear', 'apparel', 'ppe-gear', 'accessories'] as $handle) {
        expect($section[0])->toContain(asset(CategoryCardImage::DIRECTORY.'/'.$handle.'.jpg'));
    }

    // One link a card: the category's name, stretched over the whole card.
    expect(substr_count($section[0], 'after:absolute after:inset-0'))->toBe(5);
});

it('has the same cards on the home page as on /shop', function (): void {
    $card = fn (string $html): array => (function () use ($html): array {
        preg_match_all('#<a href="([^"]+)" class="[^"]*after:absolute[^"]*"#', $html, $links);

        return $links[1];
    })();

    expect($card($this->get(route('home'))->getContent()))->toBe($card($this->get(route('shop'))->getContent()))
        ->and($card($this->get(route('shop'))->getContent()))->toHaveCount(5);
});
