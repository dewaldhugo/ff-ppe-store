<?php

declare(strict_types=1);

use App\Domain\Catalogue\Urls\CategoryUrl;

// The home page's search with a category drop-down, and the Browse all categories link under the cards
// (resources/views/components/storefront/category-search.blade.php, App\Http\Middleware\RedirectSearchToCategory).

// --- The form on the home page ---------------------------------------------------------------

it('puts a search with a category drop-down under the intro and above the cards', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    $intro = (int) strpos($html, 'Choose a category, or search by name, brand or safety class.');
    $form = (int) strpos($html, 'data-category-search');
    $grid = (int) strpos($html, 'data-category-grid');

    expect($intro)->toBeGreaterThan(0)->and($form)->toBeGreaterThan($intro)->and($grid)->toBeGreaterThan($form);
});

it('is a plain GET form to /search with a labelled category drop-down and a labelled search box', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<form[^>]*data-category-search[^>]*>#', $html, $form);
    preg_match('#<form[^>]*data-category-search.*?</form>#s', $html, $whole);

    expect($form[0])->toContain('action="'.route('search').'"')->toContain('method="get"')->toContain('role="search"')
        ->and($whole[0])->toContain('<label for="category-search-category" class="sr-only">Category</label>')
        ->and($whole[0])->toContain('<label for="category-search-q" class="sr-only">Search products</label>')
        ->and($whole[0])->toContain('name="category"')->toContain('name="q"')
        ->and($whole[0])->toContain('type="submit"');
});

it('puts the category drop-down to the right of the search box, and the Search button last', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<form[^>]*data-category-search.*?</form>#s', $html, $form);

    $box = (int) strpos($form[0], 'data-category-search-input');
    $select = (int) strpos($form[0], 'data-category-search-select');
    $button = (int) strpos($form[0], 'type="submit"');

    // In the markup (and so in the row, and the stack on a phone): the box, then the drop-down, then the button.
    expect($box)->toBeGreaterThan(0)->and($select)->toBeGreaterThan($box)->and($button)->toBeGreaterThan($select);
});

it('draws the drop-down itself: no browser arrow, its own chevron, the box\'s border and a focus ring', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<select[^>]*data-category-search-select[^>]*>#', $html, $select);
    preg_match('#<input[^>]*data-category-search-input[^>]*>#s', $html, $input);
    preg_match('#<svg[^>]*data-category-search-chevron[^>]*>#', $html, $chevron);

    expect($select[0])->toContain('appearance-none')->toContain('pr-10')->toContain('rounded-md')->toContain('border-border-strong')
        ->toContain('focus:ring-2')->toContain('hover:border-brand-teal')->toContain('cursor-pointer')
        // The chevron does not catch clicks, so the whole select opens, and a screen reader skips it.
        ->and($chevron[0])->toContain('pointer-events-none')->toContain('aria-hidden="true"')
        // The box and the drop-down share one look: the same border, height and rounding.
        ->and($input[0])->toContain('border-border-strong')->toContain('py-2.5')->toContain('rounded-md')->toContain('focus:ring-2');
});

it('offers All categories first, then each top-level category by its handle', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    preg_match('#<select[^>]*data-category-search-select.*?</select>#s', $html, $select);
    preg_match_all('#<option value="([^"]*)">([^<]+)</option>#', $select[0], $options, PREG_SET_ORDER);

    expect(array_column($options, 1))->toBe(['', 'safety-footwear', 'non-safety-footwear', 'apparel', 'ppe-gear', 'accessories'])
        ->and($options[0][2])->toBe('All categories')
        ->and($options[1][2])->toBe('Safety Footwear');
});

it('puts a Browse all categories link to /shop at the bottom of the section, under the cards', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    // The whole section, from its opening tag to the next band (the PPE Health Check).
    preg_match('#data-home-categories.*?data-home-health-check#s', $html, $section);

    $grid = (int) strpos($html, 'data-category-grid');
    $link = (int) strpos($html, 'data-browse-all-categories');

    expect($link)->toBeGreaterThan($grid)
        ->and($section[0])->toMatch('#data-browse-all-categories.*?<a href="'.preg_quote(route('shop'), '#').'"[^>]*>Browse all categories</a>#s');
});

// --- The redirect -----------------------------------------------------------------------------

it('sends a search with a top-level category to that category\'s page, keeping the words', function (): void {
    $this->get(route('search', ['q' => 'boots', 'category' => 'safety-footwear']))
        ->assertRedirect(app(CategoryUrl::class)->forHandle('safety-footwear', ['q' => 'boots']));
});

it('sends a search with a child category to its own page', function (): void {
    $this->get(route('search', ['q' => 'black', 'category' => 'safety-boots']))
        ->assertRedirect(app(CategoryUrl::class)->forHandle('safety-boots', ['q' => 'black']));
});

it('leaves the words out of the category page when there are none', function (): void {
    $response = $this->get(route('search', ['q' => '', 'category' => 'apparel']));

    $response->assertRedirect(app(CategoryUrl::class)->forHandle('apparel'));

    expect($response->headers->get('Location'))->not->toContain('q=');
});

it('trims the words before they go on', function (): void {
    $this->get(route('search', ['q' => '  gloves  ', 'category' => 'ppe-gear']))
        ->assertRedirect(app(CategoryUrl::class)->forHandle('ppe-gear', ['q' => 'gloves']));
});

it('sends All categories (an empty category) to the plain search, so the address stays clean', function (): void {
    $this->get('/search?q=boots&category=')->assertRedirect(route('search', ['q' => 'boots']));
});

it('drops a category that is not in the catalogue, and keeps the other parts of the search', function (): void {
    $this->get('/search?q=boots&category=no-such-category&sort=price-asc')->assertRedirect(route('search', ['q' => 'boots', 'sort' => 'price-asc']));
});

it('drops a category that is not one word, such as a path or a list', function (): void {
    $this->get('/search?q=boots&category=../admin')->assertRedirect(route('search', ['q' => 'boots']));
    $this->get('/search?q=boots&category[]=safety-footwear')->assertRedirect(route('search', ['q' => 'boots']));
});

it('does not touch a search that has no category', function (): void {
    $this->get(route('search', ['q' => 'boots']))->assertOk();
});

it('does not send the site search in the header anywhere: it has no category', function (): void {
    $html = $this->get(route('about'))->assertOk()->getContent();

    preg_match('#<header.*?<form[^>]*role="search".*?</form>#s', $html, $header);

    expect($header[0])->toContain('name="q"')->not->toContain('name="category"');
});
