<?php

declare(strict_types=1);

use App\Livewire\Favourites\QuickOrder;
use App\Models\Favourite;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Lunar\FieldTypes\Text;
use Lunar\Models\Cart;
use Lunar\Models\Product;
use Tests\Support\CatalogueFactory;

// The quick order form (docs/accounts.md, Favourites): every favourite on a row with its colour and size to
// pick, a quantity and an "Add to order" button that puts that variant in the cart.

beforeEach(function (): void {
    config()->set('store.noindex', true);
    $this->member = User::factory()->create();
    $this->actingAs($this->member, 'web');
});

/**
 * A published product with a colour and size matrix, saved as a favourite of the member.
 *
 * Black 8 (5 in stock), Black 9 (none), Black 10 (4), Brown 8 (3), Brown 11 (2).
 */
function quickProduct(User $user, string $name = 'Quick Boot', bool $favourite = true, string $status = 'published'): Product
{
    $product = CatalogueFactory::product($name, [
        ['colour' => 'Black', 'size' => '8', 'stock' => 5],
        ['colour' => 'Black', 'size' => '9', 'stock' => 0],
        ['colour' => 'Black', 'size' => '10', 'stock' => 4],
        ['colour' => 'Brown', 'size' => '8', 'stock' => 3],
        ['colour' => 'Brown', 'size' => '11', 'stock' => 2],
    ], $status, 4000);

    // EU sizes for the sizes of the first colour.
    foreach ($product->variants as $variant) {
        $size = $variant->values->firstWhere('option.handle', 'shoe_size')?->translate('name');
        $variant->update(['attribute_data' => ['eu_size' => new Text((string) (match ($size) {
            '8' => 42, '9' => 43, '10' => 44, '11' => 45, default => 0
        }))]]);
    }

    // Black is the first colour and Brown the second, whatever sort positions the colour values already have in
    // the database (the page starts on the first colour in stock, in position order).
    $product->load('variants.values.option');
    $product->variants->flatMap(fn ($variant) => $variant->values)
        ->filter(fn ($value): bool => $value->option->handle === 'colour')
        ->unique('id')
        ->each(fn ($value) => $value->update(['position' => $value->translate('name') === 'Black' ? 1 : 2]));

    if ($favourite) {
        Favourite::query()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    }

    return $product->fresh();
}

function quickPage(object $test): Testable
{
    return Livewire::actingAs($test->member, 'web')->test(QuickOrder::class);
}

/** The quantity of a variant in the member's cart. */
function quickInCart(User $user, Product $product, string $colour, string $size): int
{
    $cart = Cart::query()->where('user_id', $user->id)->latest('id')->first();

    if ($cart === null) {
        return 0;
    }

    $variant = $product->variants()->with('values')->get()->first(function ($candidate) use ($colour, $size): bool {
        $names = $candidate->values->map(fn ($value): string => (string) $value->translate('name'));

        return $names->contains($colour) && $names->contains($size);
    });

    return (int) $cart->lines()->where('purchasable_id', $variant->id)->sum('quantity');
}

// --- Access and what is listed ---------------------------------------------------------------

it('is for signed-in customers: a guest is sent to log in', function (): void {
    auth('web')->logout();

    $this->get(route('favourites.quick-order'))->assertRedirect(route('login'));
});

it('serves the page at /favourites/quick-order, noindex, with the favourites page linking to it', function (): void {
    quickProduct($this->member);

    expect(route('favourites.quick-order', absolute: false))->toBe('/favourites/quick-order');

    $this->get(route('favourites.quick-order'))->assertOk()
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertSee('data-quick-order-page', false)
        ->assertSee('<h1', false);

    $this->get(route('favourites'))->assertOk()
        ->assertSee('data-quick-order-link', false)
        ->assertSee('href="'.route('favourites.quick-order').'"', false);
});

it('lists the member\'s published favourites only, newest first', function (): void {
    $older = quickProduct($this->member, 'Older Boot');
    $newer = quickProduct($this->member, 'Newer Boot');
    quickProduct($this->member, 'Not Saved Boot', favourite: false);
    quickProduct($this->member, 'Withdrawn Boot', status: 'draft');

    $someoneElse = User::factory()->create();
    quickProduct($someoneElse, 'Someone Elses Boot');

    $html = quickPage($this)->html();

    expect(substr_count($html, 'data-quick-order-row'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('Older Boot')->toContain('Newer Boot')
        ->not->toContain('Not Saved Boot')
        ->not->toContain('Withdrawn Boot')
        ->not->toContain('Someone Elses Boot')
        ->and(strpos($html, 'Newer Boot'))->toBeLessThan(strpos($html, 'Older Boot'))
        ->and($html)->toContain('data-product-id="'.$older->id.'"')->toContain('data-product-id="'.$newer->id.'"');
});

it('says so, with a way to the shop, when there are no favourites', function (): void {
    quickPage($this)->assertSeeHtml('data-quick-order-empty')->assertDontSeeHtml('data-quick-order-rows');
});

it('shows the delivery note and a link to the cart', function (): void {
    quickProduct($this->member);

    quickPage($this)
        ->assertSeeHtml('data-delivery-note>Orders over £500.00 ex VAT get free delivery. Otherwise delivery is £30.00 ex VAT.')
        ->assertSeeHtml('href="'.route('cart').'"');
});

it('shows each product\'s price ex VAT, from the lowest until a size is chosen, then the exact price', function (): void {
    $product = quickProduct($this->member);

    $page = quickPage($this);

    expect($page->html())->toContain('data-row-price')->toContain('£40.00')->toContain('ex VAT');

    $page->set("choices.{$product->id}.shoe_size", '8');

    expect($page->html())->toContain('£40.00');
});

// --- Colour and size ---------------------------------------------------------------------------

it('starts on the first colour that is in stock, with no size chosen', function (): void {
    $product = quickProduct($this->member);

    quickPage($this)
        ->assertSet("choices.{$product->id}.colour", 'black')
        ->assertSet("choices.{$product->id}", ['colour' => 'black'])
        ->assertSet("quantities.{$product->id}", '1');
});

it('offers every colour and size, with the EU size, and disables a size that is out of stock', function (): void {
    $product = quickProduct($this->member);

    $html = quickPage($this)->html();

    preg_match('#<select[^>]*data-option="colour".*?</select>#s', $html, $colour);
    preg_match('#<select[^>]*data-option="shoe_size".*?</select>#s', $html, $size);

    // Livewire puts comment markers between the parts of an option: read the words, not the markup around them.
    $words = fn (string $markup): string => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<!--.*?-->/s', '', $markup)));
    $sizes = $words($size[0]);

    expect($words($colour[0]))->toContain('Select colour')->toContain('Black')->toContain('Brown')
        ->and($sizes)->toContain('Select size')
        ->toContain('8 (EU 42)')
        ->toContain('10 (EU 44)')
        // Black 9 has none in stock: shown, but it cannot be picked.
        ->and($sizes)->toMatch('#<option value="9"[^>]*disabled[^>]*> 9 \(EU 43\) - out of stock#');
});

it('drops a size that the chosen colour does not come in', function (): void {
    $product = quickProduct($this->member);

    $page = quickPage($this)->set("choices.{$product->id}.shoe_size", '10');

    $page->assertSet("choices.{$product->id}.shoe_size", '10');

    // Brown has no 10: choosing it clears the size, and keeps the colour.
    $page->set("choices.{$product->id}.colour", 'brown')
        ->assertSet("choices.{$product->id}.colour", 'brown')
        ->assertSet("choices.{$product->id}", ['colour' => 'brown']);
});

it('keeps a size that the new colour also comes in', function (): void {
    $product = quickProduct($this->member);

    quickPage($this)
        ->set("choices.{$product->id}.shoe_size", '8')
        ->set("choices.{$product->id}.colour", 'brown')
        ->assertSet("choices.{$product->id}", ['colour' => 'brown', 'shoe_size' => '8']);
});

// --- Add to order ------------------------------------------------------------------------------

it('asks for a size before adding anything', function (): void {
    $product = quickProduct($this->member);

    quickPage($this)
        ->call('add', $product->id)
        ->assertSet("messages.{$product->id}", ['type' => 'error', 'text' => 'Please select a size.'])
        ->assertNotDispatched('cart-updated');

    expect(quickInCart($this->member, $product, 'Black', '8'))->toBe(0);
});

it('adds the chosen colour and size to the cart, and tells the header', function (): void {
    $product = quickProduct($this->member);

    quickPage($this)
        ->set("choices.{$product->id}.shoe_size", '10')
        ->set("quantities.{$product->id}", '3')
        ->call('add', $product->id)
        ->assertSet("messages.{$product->id}", ['type' => 'success', 'text' => 'Added 3 to your order.'])
        ->assertDispatched('cart-updated')
        ->assertSeeHtml('data-row-message="success"');

    expect(quickInCart($this->member, $product, 'Black', '10'))->toBe(3)
        ->and(quickInCart($this->member, $product, 'Black', '8'))->toBe(0);
});

it('adds on top of what is already in the cart', function (): void {
    $product = quickProduct($this->member);

    $page = quickPage($this)->set("choices.{$product->id}.shoe_size", '8')->set("quantities.{$product->id}", '2');

    $page->call('add', $product->id)->call('add', $product->id);

    expect(quickInCart($this->member, $product, 'Black', '8'))->toBe(4);
});

it('adds the other colour when the colour is changed', function (): void {
    $product = quickProduct($this->member);

    quickPage($this)
        ->set("choices.{$product->id}.colour", 'brown')
        ->set("choices.{$product->id}.shoe_size", '11')
        ->call('add', $product->id)
        ->assertSet("messages.{$product->id}.type", 'success');

    expect(quickInCart($this->member, $product, 'Brown', '11'))->toBe(1);
});

it('refuses a quantity that is not a whole number of 1 or more', function (string $typed): void {
    $product = quickProduct($this->member);

    quickPage($this)
        ->set("choices.{$product->id}.shoe_size", '8')
        ->set("quantities.{$product->id}", $typed)
        ->call('add', $product->id)
        ->assertSet("messages.{$product->id}", ['type' => 'error', 'text' => 'Enter a whole number, 1 or more.']);

    expect(quickInCart($this->member, $product, 'Black', '8'))->toBe(0);
})->with(['zero' => '0', 'letters' => 'abc', 'decimal' => '1.5', 'negative' => '-2', 'empty' => '', 'huge' => '1234567']);

it('refuses more than is in stock', function (): void {
    $product = quickProduct($this->member);

    quickPage($this)
        ->set("choices.{$product->id}.shoe_size", '8')
        ->set("quantities.{$product->id}", '6')
        ->call('add', $product->id)
        ->assertSet("messages.{$product->id}", ['type' => 'error', 'text' => 'Only 5 available in this size.']);

    expect(quickInCart($this->member, $product, 'Black', '8'))->toBe(0);
});

it('checks stock again against what is already in the cart', function (): void {
    $product = quickProduct($this->member);

    $page = quickPage($this)->set("choices.{$product->id}.shoe_size", '8')->set("quantities.{$product->id}", '4');

    $page->call('add', $product->id)->assertSet("messages.{$product->id}.type", 'success');
    $page->call('add', $product->id)->assertSet("messages.{$product->id}.type", 'error');

    expect(quickInCart($this->member, $product, 'Black', '8'))->toBe(4);
});

it('refuses a size that is out of stock, even when it is sent by hand', function (): void {
    $product = quickProduct($this->member);

    quickPage($this)
        ->set("choices.{$product->id}.shoe_size", '9')
        ->call('add', $product->id)
        ->assertSet("messages.{$product->id}", ['type' => 'error', 'text' => 'That choice is out of stock.']);

    expect(quickInCart($this->member, $product, 'Black', '9'))->toBe(0);
});

it('clears a row\'s message when something on that row changes', function (): void {
    $product = quickProduct($this->member);

    quickPage($this)
        ->call('add', $product->id)
        ->assertSet("messages.{$product->id}.type", 'error')
        ->set("choices.{$product->id}.shoe_size", '8')
        ->assertSet('messages', []);
});

it('only adds the member\'s own favourites', function (): void {
    $mine = quickProduct($this->member, 'Mine Boot');
    $theirs = quickProduct(User::factory()->create(), 'Theirs Boot');
    $unsaved = quickProduct($this->member, 'Unsaved Boot', favourite: false);

    foreach ([$theirs, $unsaved] as $other) {
        quickPage($this)
            ->call('add', $other->id)
            ->assertSet("messages.{$other->id}", ['type' => 'error', 'text' => 'That product is no longer in your favourites.'])
            ->assertNotDispatched('cart-updated');
    }

    expect(Cart::query()->where('user_id', $this->member->id)->exists())->toBeFalse()
        ->and($mine->id)->not->toBe($theirs->id);
});

it('does not add a product withdrawn since it was saved', function (): void {
    $product = quickProduct($this->member);
    $page = quickPage($this)->set("choices.{$product->id}.shoe_size", '8');

    $product->update(['status' => 'draft']);

    $page->call('add', $product->id)->assertSet("messages.{$product->id}.type", 'error');

    expect(quickInCart($this->member, $product, 'Black', '8'))->toBe(0);
});

it('refuses a guest who somehow reaches the form', function (): void {
    $product = quickProduct($this->member);
    $page = quickPage($this)->set("choices.{$product->id}.shoe_size", '8');

    auth('web')->logout();

    $page->call('add', $product->id);

    expect(Cart::query()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('handles a product with no options: just a quantity and the button', function (): void {
    $product = CatalogueFactory::product('Plain Insoles', [['stock' => 9]], 'published', 1500);
    Favourite::query()->create(['user_id' => $this->member->id, 'product_id' => $product->id]);

    $page = quickPage($this);

    expect($page->html())->not->toContain('data-option=')->toContain('data-add-to-order');

    $page->set("quantities.{$product->id}", '2')->call('add', $product->id)->assertSet("messages.{$product->id}.type", 'success');

    expect((int) Cart::query()->where('user_id', $this->member->id)->first()->lines()->sum('quantity'))->toBe(2);
});

it('uses UK English and no em dashes in its copy', function (): void {
    foreach (['app/Livewire/Favourites/QuickOrder.php', 'resources/views/livewire/favourites/quick-order.blade.php'] as $file) {
        $text = (string) file_get_contents(base_path($file));

        expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(organization|license|favorite|analyze)\b/i');
    }
});
