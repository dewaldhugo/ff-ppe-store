<?php

declare(strict_types=1);

use App\Domain\Cart\Delivery\DeliveryPolicy;
use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Pricing\Vat\StoreTaxDriver;
use App\Livewire\Checkout\CheckoutPage;
use App\Models\User;
use Livewire\Livewire;
use Lunar\Facades\CartSession;
use Lunar\Facades\Taxes;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\TaxRateAmount;
use Tests\Support\CatalogueFactory;

// VAT and delivery (docs/cart.md): worked out and shown at the checkout only, never on the cart page or
// the mini cart. Every customer is a vetted business buying for its employees, so protective boots and
// helmets carry 20%; only children's footwear is zero-rated. Delivery: free when the goods come to
// more than GBP 500 ex VAT, otherwise GBP 30.

beforeEach(function (): void {
    $this->customer = User::factory()->create();
    $this->actingAs($this->customer, 'web');
});

/**
 * A cart of new products, opened at the checkout with a delivery address so delivery is priced.
 *
 * @param  list<array{0: int, 1?: int, 2?: string, 3?: string}>  $items  price in pence, quantity, tax class, name
 * @return string the checkout HTML
 */
function checkoutFor(User $owner, array $items): string
{
    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $owner->id,
    ]);

    foreach ($items as $index => $item) {
        $variant = CatalogueFactory::product($item[3] ?? "Vat Boot {$index}", [['stock' => 5000]], 'published', $item[0], $item[2] ?? 'standard')->variants->sole();
        $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => $item[1] ?? 1, 'meta' => []]);
    }

    CartSession::use($cart);

    return Livewire::test(CheckoutPage::class)
        ->set('ship_line_one', '1 Test Street')
        ->set('ship_city', 'Orpington')
        ->set('ship_postcode', 'BR5 3QX')
        ->html();
}

/** The text of the element carrying the data attribute, whitespace squeezed. */
function dataText(string $html, string $attribute): string
{
    preg_match('#'.preg_quote($attribute, '#').'[^>]*>(.*?)</(?:dd|p|span)>#s', $html, $match);

    return trim((string) preg_replace('/\s+/', ' ', strip_tags($match[1] ?? '')));
}

// --- VAT -------------------------------------------------------------------

it('uses the store tax driver, and leaves the zero-protective class at 0% in Lunar', function (): void {
    expect(Taxes::driver())->toBeInstanceOf(StoreTaxDriver::class);

    $rate = fn (string $handle): float => (float) TaxRateAmount::query()->where('tax_class_id', CatalogueKeys::taxClass($handle)->id)->value('percentage');

    expect($rate('standard'))->toBe(20.0)
        ->and($rate('zero-protective'))->toBe(0.0)
        ->and($rate('zero-childrens'))->toBe(0.0);
});

it('charges 20% VAT on a standard line and shows it on the line', function (): void {
    $html = checkoutFor($this->customer, [[5000]]);

    expect(dataText($html, 'data-line-vat'))->toBe('VAT 20%: £10.00')
        ->and($html)->not->toContain('data-zero-rated');
});

it('charges 20% on protective boots and helmets, because the buyer is a business buying for employees', function (): void {
    $html = checkoutFor($this->customer, [[5000, 2, 'zero-protective']]);

    expect(dataText($html, 'data-line-vat'))->toBe('VAT 20%: £20.00')
        ->and($html)->not->toContain('data-zero-rated');
});

it('labels children\'s footwear as zero-rated and charges it no VAT', function (): void {
    $html = checkoutFor($this->customer, [[5000, 1, 'zero-childrens']]);

    expect($html)->toContain('data-zero-rated')
        ->and(dataText($html, 'data-zero-rated'))->toBe('Zero-rated: 0% VAT')
        ->and(dataText($html, 'data-total-vat'))->toBe('£6.00'); // the delivery charge's VAT only
});

// --- Delivery and the money stack ------------------------------------------------

it('adds GBP 30 delivery to an order of GBP 500 or less and shows the whole stack', function (): void {
    $html = checkoutFor($this->customer, [[5000]]);

    expect(dataText($html, 'data-total-subtotal'))->toBe('£50.00')
        ->and(dataText($html, 'data-total-delivery'))->toBe('£30.00')
        ->and(dataText($html, 'data-total-ex-vat'))->toBe('£80.00')
        // 20% of the goods (£10.00) and of the delivery (£6.00).
        ->and(dataText($html, 'data-total-vat'))->toBe('£16.00')
        ->and(dataText($html, 'data-total-grand'))->toBe('£96.00');
});

it('makes delivery free when the goods come to more than GBP 500 ex VAT', function (): void {
    $html = checkoutFor($this->customer, [[60_000]]);

    expect(dataText($html, 'data-total-delivery'))->toBe('Free')
        ->and(dataText($html, 'data-total-ex-vat'))->toBe('£600.00')
        ->and(dataText($html, 'data-total-vat'))->toBe('£120.00')
        ->and(dataText($html, 'data-total-grand'))->toBe('£720.00');
});

it('still charges delivery on exactly GBP 500 ex VAT, and makes it free one penny over', function (): void {
    expect(dataText(checkoutFor($this->customer, [[50_000]]), 'data-total-delivery'))->toBe('£30.00')
        ->and(dataText(checkoutFor($this->customer, [[50_001]]), 'data-total-delivery'))->toBe('Free');
});

it('judges the threshold on the goods across all lines', function (): void {
    expect(dataText(checkoutFor($this->customer, [[20_000, 3]]), 'data-total-delivery'))->toBe('Free') // 3 x £200.00
        ->and(dataText(checkoutFor($this->customer, [[20_000, 2]]), 'data-total-delivery'))->toBe('£30.00'); // 2 x £200.00
});

it('reads the amounts from config, in one place', function (): void {
    config()->set('store.delivery.free_over', 2_000);
    config()->set('store.delivery.charge', 700);

    expect(dataText(checkoutFor($this->customer, [[1_500]]), 'data-total-delivery'))->toBe('£7.00')
        ->and(app(DeliveryPolicy::class)->freeOver())->toBe(2_000)
        ->and(app(DeliveryPolicy::class)->charge())->toBe(700);
});

it('has the delivery amounts the client gave: GBP 30, free over GBP 500, in config/store.php', function (): void {
    $file = require config_path('store.php');

    expect($file['delivery'])->toBe(['free_over' => 50_000, 'charge' => 3_000]);
});

it('words the delivery note from the same amounts, so it cannot disagree with the charge', function (): void {
    $policy = app(DeliveryPolicy::class);

    expect($policy->notice())->toBe('Orders over £500.00 ex VAT get free delivery. Otherwise delivery is £30.00 ex VAT.');

    config()->set('store.delivery.free_over', 75_000);
    config()->set('store.delivery.charge', 4_500);

    expect($policy->notice())->toBe('Orders over £750.00 ex VAT get free delivery. Otherwise delivery is £45.00 ex VAT.');
});

it('shows the delivery note on the checkout, with and without a delivery address', function (): void {
    $note = 'Orders over £500.00 ex VAT get free delivery. Otherwise delivery is £30.00 ex VAT.';

    $withAddress = checkoutFor($this->customer, [[5000]]);

    expect($withAddress)->toContain('data-delivery-note>'.$note);

    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $this->customer->id,
    ]);
    $variant = CatalogueFactory::product('Note Boot', [['stock' => 50]], 'published', 5000, 'standard')->variants->sole();
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 1, 'meta' => []]);
    CartSession::use($cart);

    expect(Livewire::test(CheckoutPage::class)->html())->toContain('data-delivery-pending')->toContain('data-delivery-note>'.$note);
});

it('prices delivery standard rated', function (): void {
    // Free delivery has no VAT either, so a children's line leaves a VAT total of nothing.
    expect(dataText(checkoutFor($this->customer, [[100_000, 1, 'zero-childrens']]), 'data-total-vat'))->toBe('£0.00');
});

it('prices paid delivery at 20% even when every goods line is zero-rated', function (): void {
    // £30.00 of delivery at 20%, on a goods line with no VAT at all.
    expect(dataText(checkoutFor($this->customer, [[5000, 1, 'zero-childrens', 'Kids Boot']]), 'data-total-vat'))->toBe('£6.00');
});

// --- The VAT breakdown in the summary ---------------------------------------------------

it('breaks the VAT down by rate in the checkout summary, naming the zero-rated goods', function (): void {
    $html = checkoutFor($this->customer, [
        [5000, 1, 'standard', 'Plain Boot'],
        [10_000, 1, 'zero-protective', 'Protective Boot'],
        [3000, 1, 'zero-childrens', 'Kids Shoe'],
    ]);

    preg_match_all('#<tr data-vat-row[^>]*>(.*?)</tr>#s', $html, $rows);
    $text = array_map(fn (string $row): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($row))), $rows[1]);

    // 20%: £50.00 + £100.00 of boots + £30.00 delivery = £180.00 net, £36.00 VAT. Zero-rated: £30.00.
    expect($text)->toBe([
        'VAT 20% £180.00 £36.00',
        'Zero-rated (0%) £30.00 £0.00',
    ])
        ->and($html)->toContain('data-zero-rated-row')
        ->and(dataText($html, 'data-total-vat'))->toBe('£36.00');
});

// --- Not on the cart page or the mini cart ------------------------------------------------

it('shows no VAT and no delivery on the cart page', function (): void {
    $variant = CatalogueFactory::product('Cart Only Boot', [['stock' => 50]], 'published', 5000)->variants->sole();
    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $this->customer->id,
    ]);
    $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 2, 'meta' => []]);

    $html = $this->withSession(['lunar_cart' => $cart->id])->get(route('cart'))->assertOk()->getContent();

    // Price, quantity, line total and the subtotal only.
    expect($html)->toContain('Price')->toContain('Qty')->toContain('Line total')
        ->toMatch('#data-cart-subtotal>\s*£100\.00#')
        ->toContain('VAT and delivery are worked out at checkout.')
        // The delivery rule is a note, not a price: nothing is worked out for this cart.
        ->toContain('data-delivery-note>Orders over £500.00 ex VAT get free delivery. Otherwise delivery is £30.00 ex VAT.')
        ->not->toContain('data-line-vat')
        ->not->toContain('data-zero-rated')
        ->not->toContain('data-vat-breakdown')
        ->not->toContain('data-cart-delivery')
        ->not->toContain('Standard delivery')
        ->not->toContain('Net Total')
        ->not->toContain('incl. VAT')
        ->not->toContain('£96.00');
});
