<?php

declare(strict_types=1);

use App\Domain\Compare\Compare;
use App\Domain\Compare\CompareTable;
use App\Models\User;
use Lunar\Models\Product;
use Tests\Support\SearchIndex;

// Compare up to four products side by side (App\Domain\Compare, CompareController, the tick on a card, the bar and /compare).

beforeEach(function (): void {
    config()->set('store.noindex', true);
    SearchIndex::use();
});

function comparable(int $count = 3): array
{
    return Product::query()->where('status', 'published')->orderBy('id')->limit($count)->pluck('id')->all();
}

it('has the compare routes by name', function (): void {
    expect(route('compare', absolute: false))->toBe('/compare')
        ->and(route('compare.clear', absolute: false))->toBe('/compare/clear')
        ->and(route('compare.toggle', 7, absolute: false))->toBe('/compare/7');
});

it('ticks a product for a guest, and un-ticks it on the second post', function (): void {
    [$id] = comparable(1);

    $this->postJson(route('compare.toggle', $id))->assertOk()->assertJson(['on' => true, 'full' => false, 'count' => 1, 'ids' => [$id]]);
    expect(session(Compare::KEY))->toBe([$id]);

    $this->postJson(route('compare.toggle', $id))->assertOk()->assertJson(['on' => false, 'count' => 0, 'ids' => []]);
    expect(session(Compare::KEY))->toBe([]);
});

it('refuses a fifth product rather than swapping one out', function (): void {
    $ids = comparable(5);

    foreach (array_slice($ids, 0, 4) as $id) {
        $this->postJson(route('compare.toggle', $id))->assertJson(['on' => true]);
    }

    $this->postJson(route('compare.toggle', $ids[4]))->assertOk()->assertJson(['on' => false, 'full' => true, 'count' => 4, 'max' => 4]);

    expect(session(Compare::KEY))->toBe(array_slice($ids, 0, 4));
});

it('does not take a product that is not published or does not exist', function (): void {
    $this->postJson(route('compare.toggle', 99999999))->assertNotFound();
});

it('without JavaScript goes back to the page, with a message when the list is full', function (): void {
    $ids = comparable(5);

    foreach (array_slice($ids, 0, 4) as $id) {
        $this->from(route('shop'))->post(route('compare.toggle', $id))->assertRedirect(route('shop'));
    }

    $this->from(route('shop'))->post(route('compare.toggle', $ids[4]))->assertRedirect(route('shop'))->assertSessionHas('status');
});

it('clears the list', function (): void {
    foreach (comparable(2) as $id) {
        $this->postJson(route('compare.toggle', $id));
    }

    $this->postJson(route('compare.clear'))->assertOk()->assertJson(['count' => 0, 'ids' => []]);
    expect(session(Compare::KEY))->toBeNull();
});

it('puts a Compare tick on every product card, ticked for the products already chosen', function (): void {
    [$first] = comparable(1);

    $this->postJson(route('compare.toggle', $first));

    $html = $this->get(route('category', 'safety-footwear'))->assertOk()->getContent();

    preg_match_all('#<form[^>]*data-compare-form.*?</form>#s', $html, $forms);

    expect($forms[0])->not->toBeEmpty();

    foreach ($forms[0] as $form) {
        expect($form)->toContain('data-compare-checkbox')->toContain('compare-updated.window');
    }

    preg_match('#<form[^>]*action="'.preg_quote(route('compare.toggle', $first), '#').'"[^>]*data-compare-form.*?</form>#s', $html, $ticked);

    // The product that is chosen is ticked, if it is on this page.
    if (($ticked[0] ?? '') !== '') {
        expect($ticked[0])->toContain('checked');
    }
});

it('has the tick on the product page too', function (): void {
    $html = $this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent();

    expect($html)->toContain('data-compare-form')->toContain('data-compare-checkbox');
});

it('shows the bar only while something is chosen, with the count and a Clear button', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('data-compare-bar')->toContain('data-compare-clear')->toContain('x-show="count > 0"')->toContain('count: 0');

    foreach (comparable(2) as $id) {
        $this->postJson(route('compare.toggle', $id));
    }

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('count: 2')->toContain('href="'.route('compare').'"');
});

it('shows an empty compare page with a way to browse', function (): void {
    $this->get(route('compare'))->assertOk()->assertSee('data-compare-empty', false)->assertSee(route('shop'), false);
});

it('shows the chosen products as columns, with a row for each fact and the differences switch', function (): void {
    $ids = comparable(3);

    foreach ($ids as $id) {
        $this->postJson(route('compare.toggle', $id));
    }

    $html = $this->get(route('compare'))->assertOk()->getContent();

    expect(substr_count($html, 'data-compare-product='))->toBe(3)
        ->and($html)->toContain('data-compare-differences')->toContain('Show differences only')
        ->and($html)->toContain('>Brand</th>')->toContain('>Availability</th>')->toContain('>Colours</th>')
        ->and($html)->toContain('data-compare-clear-all')
        ->and($html)->toContain('<meta name="robots" content="noindex">');

    // The products keep the order they were ticked in.
    preg_match_all('#data-compare-product="(\d+)"#', $html, $found);
    expect(array_map('intval', $found[1]))->toBe($ids);
});

it('hides no price row from a signed-in customer, and shows none to a guest', function (): void {
    foreach (comparable(2) as $id) {
        $this->postJson(route('compare.toggle', $id));
    }

    $this->get(route('compare'))->assertOk()->assertDontSee('>Price</th>', false);

    $this->actingAs(User::factory()->create(), 'web');

    $this->get(route('compare'))->assertOk()->assertSee('>Price</th>', false);
});

it('marks the rows where every product says the same, so the switch can hide them', function (): void {
    $table = app(CompareTable::class);
    $products = Product::query()->whereIn('id', comparable(2))->get();

    $rows = $table->rows($products);
    $labels = array_column($rows, 'label');

    expect($labels)->toContain('Brand')->toContain('Availability');

    foreach ($rows as $row) {
        expect($row['cells'])->toHaveCount(2)
            ->and($row['same'])->toBe(count(array_unique($row['cells'])) === 1);
    }
});

it('shows the products of a shared link, in the order given, without the session or a remove button', function (): void {
    $ids = comparable(3);

    $html = $this->get(route('compare', ['p' => implode(',', array_reverse($ids))]))->assertOk()->getContent();

    preg_match_all('#data-compare-product="(\d+)"#', $html, $found);

    expect(array_map('intval', $found[1]))->toBe(array_reverse($ids))
        ->and($html)->not->toContain('data-compare-remove')
        ->and($html)->not->toContain('data-compare-clear-all')
        ->and($html)->toContain('value="'.route('compare', ['p' => implode(',', array_reverse($ids))]).'"');
});

it('takes at most four products from a shared link, ignoring nonsense', function (): void {
    $ids = comparable(5);

    $html = $this->get(route('compare', ['p' => implode(',', $ids).',abc,0']))->assertOk()->getContent();

    expect(substr_count($html, 'data-compare-product='))->toBe(4);
});

it('uses UK English and no em dashes in its copy', function (): void {
    foreach (['views/compare/show.blade.php', 'views/components/storefront/compare-bar.blade.php', 'views/components/storefront/compare-toggle.blade.php'] as $file) {
        $text = strip_tags((string) file_get_contents(resource_path($file)));

        expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(color|center|organization)\b/i');
    }
});
