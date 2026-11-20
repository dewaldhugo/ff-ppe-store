<?php

declare(strict_types=1);

use App\Livewire\Checkout\CheckoutPage;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Lunar\Facades\CartSession;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\Customer;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;
use Tests\Support\CatalogueFactory;

// The checkout (docs/cart.md): two columns, the live summary with VAT on every line, a 30 day
// account order placed through Lunar, the vendor snapshot, and the confirmation page. The
// default customer here has 30 day terms, because card is not built yet; the payment rules
// themselves are in PaymentTermsTest.

beforeEach(function (): void {
    $this->customer = User::factory()->withAccountTerms()->create([
        'name' => 'Jo Builder',
        'email' => 'jo@builder.example',
        'phone' => '020 8289 5858',
    ]);
    $this->actingAs($this->customer, 'web');
});

function checkoutVariant(string $name = 'Checkout Boot', int $price = 5000, string $taxClass = 'standard', int $stock = 50): ProductVariant
{
    return CatalogueFactory::product($name, [['stock' => $stock]], 'published', $price, $taxClass)->variants->sole();
}

/** A cart for the customer, made current for the Livewire tests (they share one session). */
function checkoutCart(User $owner, ProductVariant ...$variants): Cart
{
    $cart = Cart::query()->create([
        'currency_id' => Currency::getDefault()->id,
        'channel_id' => Channel::getDefault()->id,
        'user_id' => $owner->id,
    ]);

    foreach ($variants as $variant) {
        $cart->lines()->create(['purchasable_type' => $variant->getMorphClass(), 'purchasable_id' => $variant->id, 'quantity' => 1, 'meta' => []]);
    }

    CartSession::use($cart);

    return $cart;
}

/** Fills every required field with valid values. */
function fillCheckout(Testable $page): Testable
{
    return $page
        ->set('full_name', 'Jo Builder')
        ->set('email', 'jo@builder.example')
        ->set('phone', '020 8289 5858')
        ->set('company_name', 'Builder Ltd')
        ->set('ship_line_one', '1 Test Street')
        ->set('ship_city', 'Orpington')
        ->set('ship_postcode', 'br5 3qx');
}

// --- Access and layout -------------------------------------------------------

it('sends a guest to log in', function (): void {
    auth('web')->logout();

    $this->get(route('checkout'))->assertRedirect(route('login'));
});

it('sends a customer with an empty cart back to the cart', function (): void {
    $this->get(route('checkout'))->assertRedirect(route('cart'));

    Livewire::test(CheckoutPage::class)->assertRedirect(route('cart'));
});

it('strips the site navigation: a clean header with the logo and a way to get help, and no footer', function (): void {
    $cart = checkoutCart($this->customer, checkoutVariant());

    // The layout is only part of a real request, not of Livewire::test().
    $this->withSession(['lunar_cart' => $cart->id])->get(route('checkout'))
        ->assertOk()
        ->assertSee('data-checkout-header', false)
        ->assertSee('data-site-logo', false)
        ->assertSee('data-checkout-support', false)
        ->assertSee('data-checkout-form', false)
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertDontSee('<nav aria-label="Main"', false)
        ->assertDontSee('<footer', false)
        ->assertDontSee('data-cart-link', false);
});

it('has the two columns: contact, company, delivery, billing, method and payment on the left, the summary on the right', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    $html = Livewire::test(CheckoutPage::class)->html();

    foreach (['contact', 'company', 'delivery-address', 'billing-address', 'delivery-method', 'payment'] as $section) {
        expect($html)->toContain('data-section="'.$section.'"');
    }

    expect($html)->toContain('data-checkout-summary')
        ->toContain('lg:sticky')
        // The PO number and the order notes are asked for, both optional.
        ->toContain('Purchase order (PO) number')
        ->toContain('Order notes')
        ->toContain('VAT registration number')
        // Delivery is to the UK only, and the site type is asked.
        ->toContain('data-country')
        ->toContain('Commercial or business premises')
        ->toContain('Residential address');
});

it('starts with the billing address the same as the delivery address, and opens separate fields when unticked', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    Livewire::test(CheckoutPage::class)
        ->assertSet('billing_same', true)
        ->assertDontSeeHtml('data-billing-fields')
        ->set('billing_same', false)
        ->assertSeeHtml('data-billing-fields');
});

it('offers 30 day account to a customer who has it, and shows card as not available', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    Livewire::test(CheckoutPage::class)
        ->assertSeeHtml('data-payment-option="account-30-day"')
        ->assertSeeHtml('data-payment-option="card"')
        ->assertSee('Not available at the moment.')
        ->assertSet('payment_method', 'account-30-day');
});

// --- Prefill -------------------------------------------------------------------

it('fills the form from the customer, their company and their registered address', function (): void {
    $customer = Customer::query()->create(['first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd', 'tax_identifier' => 'GB123456789']);
    $this->customer->customers()->attach($customer);
    $customer->addresses()->create([
        'country_id' => Country::query()->where('iso2', 'GB')->value('id'),
        'first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => 'Builder Ltd',
        'line_one' => '5 High Road', 'line_two' => 'Unit 2', 'city' => 'Bromley', 'state' => 'Kent', 'postcode' => 'BR1 1AA',
        'billing_default' => true,
    ]);

    checkoutCart($this->customer, checkoutVariant());

    Livewire::test(CheckoutPage::class)
        ->assertSet('full_name', 'Jo Builder')
        ->assertSet('email', 'jo@builder.example')
        ->assertSet('phone', '020 8289 5858')
        ->assertSet('company_name', 'Builder Ltd')
        ->assertSet('vat_number', 'GB123456789')
        ->assertSet('ship_line_one', '5 High Road')
        ->assertSet('ship_city', 'Bromley')
        ->assertSet('ship_postcode', 'BR1 1AA');
});

// --- The summary -------------------------------------------------------------

it('shows every line with its code, quantity, ex VAT total and VAT, and labels zero-rated lines', function (): void {
    checkoutCart(
        $this->customer,
        checkoutVariant('Plain Boot', 5000),
        checkoutVariant('Protective Boot', 10_000, 'zero-protective'),
        checkoutVariant('Kids Shoe', 3000, 'zero-childrens'),
    );

    $html = Livewire::test(CheckoutPage::class)->html();

    preg_match_all('#<li[^>]*data-summary-line.*?</li>#s', $html, $lines);

    expect($lines[0])->toHaveCount(3)
        ->and($lines[0][0])->toContain('Plain Boot')->toContain('data-line-sku')->toContain('data-line-unit')->toContain('each')->toContain('Qty 1')->toContain('Line total')->toContain('£50.00')->toContain('VAT 20%')->toContain('£10.00')
        // Protective boots are charged 20%, not shown as zero-rated.
        ->and($lines[0][1])->toContain('VAT 20%')->toContain('£20.00')->not->toContain('data-zero-rated')
        ->and($lines[0][2])->toContain('data-zero-rated')->toContain('Zero-rated: 0% VAT');
});

it('lays the money stack out in order: subtotal, delivery, net total, VAT, total', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    $html = fillCheckout(Livewire::test(CheckoutPage::class))->html();

    $positions = array_map(fn (string $key): int|false => strpos($html, $key), ['data-total-subtotal', 'data-total-delivery', 'data-total-ex-vat', 'data-total-vat', 'data-total-grand']);

    expect($positions)->each->toBeInt()
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());

    expect($html)->toMatch('#data-total-subtotal>\s*£50\.00#')
        ->toMatch('#data-total-ex-vat>\s*£80\.00#')
        ->toMatch('#data-total-vat>\s*£16\.00#')
        ->toMatch('#data-total-grand>\s*£96\.00#');
});

it('asks for an address before it prices delivery, then shows the option with its ex VAT price', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    Livewire::test(CheckoutPage::class)
        ->assertSeeHtml('data-delivery-pending')
        ->assertSee('Enter address')
        ->assertDontSeeHtml('data-delivery-option')
        ->set('ship_line_one', '1 Test Street')
        ->set('ship_city', 'Orpington')
        ->set('ship_postcode', 'BR5 3QX')
        ->assertSeeHtml('data-delivery-option')
        ->assertSee('Standard delivery')
        ->assertSee('£30.00')
        ->assertDontSeeHtml('data-delivery-pending');
});

it('shows free delivery over GBP 500 ex VAT, and says why', function (): void {
    checkoutCart($this->customer, checkoutVariant('Pricey Boot', 100_000));

    $html = fillCheckout(Livewire::test(CheckoutPage::class))->html();

    expect($html)->toMatch('#data-delivery-price>\s*Free#')
        ->toContain('Free: your goods come to over £500.00 ex VAT.')
        ->toMatch('#data-total-grand>\s*£1,200\.00#');
});

it('refuses a discount code that does nothing, and keeps the cart without one', function (): void {
    $cart = checkoutCart($this->customer, checkoutVariant());

    Livewire::test(CheckoutPage::class)
        ->set('promo_code', 'nonsense')
        ->call('applyPromo')
        ->assertSet('promo_applied', null)
        ->assertSee('That code is not valid for this order.');

    expect($cart->fresh()->coupon_code)->toBeNull();
});

// --- Validation -------------------------------------------------------------------

it('needs the contact, company and delivery details', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    Livewire::test(CheckoutPage::class)
        ->set('full_name', '')
        ->set('email', '')
        ->set('phone', '')
        ->set('company_name', '')
        ->call('placeOrder')
        ->assertHasErrors(['full_name', 'email', 'phone', 'company_name', 'ship_line_one', 'ship_city', 'ship_postcode'])
        ->assertSee('Please check the details marked above.');

    expect(Order::query()->count())->toBe(0);
});

it('checks the postcode, the phone number, the VAT number and the email address', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    fillCheckout(Livewire::test(CheckoutPage::class))
        ->set('ship_postcode', 'NOT A POSTCODE')
        ->set('phone', 'call me')
        ->set('vat_number', 'GB12')
        ->set('email', 'not-an-email')
        ->call('placeOrder')
        ->assertHasErrors(['ship_postcode', 'phone', 'vat_number', 'email'])
        ->assertSee('Enter a valid UK postcode.')
        ->assertSee('Enter a phone number the courier can call.');
});

it('tidies the postcode and the VAT number as they are typed', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    Livewire::test(CheckoutPage::class)
        ->set('ship_postcode', 'br53qx')
        ->assertSet('ship_postcode', 'BR5 3QX')
        ->set('vat_number', 'gb 123 456 789')
        ->assertSet('vat_number', 'GB123456789');
});

it('asks for the billing address only when it differs from the delivery address', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    fillCheckout(Livewire::test(CheckoutPage::class))
        ->call('placeOrder')
        ->assertHasNoErrors();

    expect(Order::query()->count())->toBe(1);

    checkoutCart($this->customer, checkoutVariant('Second Boot'));

    fillCheckout(Livewire::test(CheckoutPage::class))
        ->set('billing_same', false)
        ->call('placeOrder')
        ->assertHasErrors(['bill_line_one', 'bill_city', 'bill_postcode']);
});

// --- Placing the order -------------------------------------------------------------

it('places a 30 day account order through Lunar and goes to the confirmation page', function (): void {
    $cart = checkoutCart($this->customer, checkoutVariant());

    $page = fillCheckout(Livewire::test(CheckoutPage::class))
        ->set('po_number', 'PO-1234')
        ->set('notes', 'Deliver to Receiving Bay 2')
        ->set('vat_number', 'GB123456789')
        ->set('site_type', 'residential')
        ->call('placeOrder')
        ->assertHasNoErrors();

    $order = Order::query()->sole();

    $page->assertRedirect(route('checkout.complete', $order->reference));

    expect($order->user_id)->toBe($this->customer->id)
        ->and($order->cart_id)->toBe($cart->id)
        ->and($order->status)->toBe('awaiting-payment')
        ->and($order->placed_at)->not->toBeNull()
        ->and($order->customer_reference)->toBe('PO-1234')
        ->and($order->notes)->toBe('Deliver to Receiving Bay 2')
        // VAT is charged as for a business buying for employees (docs/data-contract.md).
        ->and($order->meta['buying_for_employees'])->toBeTrue()
        ->and($order->meta['site_type'])->toBe('residential')
        ->and($order->meta['payment_method'])->toBe('account-30-day')
        ->and($order->meta['contact_email'])->toBe('jo@builder.example')
        // £50.00 goods + £30.00 delivery, and £16.00 VAT on both.
        ->and($order->sub_total->value)->toBe(5000)
        ->and($order->shipping_total->value)->toBe(3600)
        ->and($order->tax_total->value)->toBe(1600)
        ->and($order->total->value)->toBe(9600)
        ->and($order->shippingAddress->postcode)->toBe('BR5 3QX')
        ->and($order->shippingAddress->delivery_instructions)->toBe('Deliver to Receiving Bay 2')
        ->and($order->billingAddress->tax_identifier)->toBe('GB123456789')
        ->and($order->billingAddress->company_name)->toBe('Builder Ltd')
        ->and($order->lines->where('type', 'shipping')->sole()->sub_total->value)->toBe(3000);

    // The cart now belongs to the order: the next visit starts a new one.
    expect(session()->get('lunar_cart'))->toBeNull();
});

it('charges protective boots 20% on the order and records children\'s footwear as zero-rated', function (): void {
    checkoutCart(
        $this->customer,
        checkoutVariant('Protective Boot', 10_000, 'zero-protective'),
        checkoutVariant('Kids Shoe', 3000, 'zero-childrens'),
    );

    fillCheckout(Livewire::test(CheckoutPage::class))->call('placeOrder')->assertHasNoErrors();

    $lines = Order::query()->sole()->lines->where('type', 'physical');

    // £100.00 of boots at 20%, and £30.00 of children's footwear at nothing.
    expect($lines->map(fn ($line): int => $line->tax_total->value)->sort()->values()->all())->toBe([0, 2000]);
});

it('copies the vendor\'s wording and cost onto the order line, and keeps it when the vendor data changes', function (): void {
    $variant = checkoutVariant();
    $vendorId = DB::table('vendors')->where('code', 'SJ')->value('id')
        ?? DB::table('vendors')->insertGetId(['code' => 'SJ', 'name' => 'Safety Jogger', 'created_at' => now(), 'updated_at' => now()]);

    DB::table('vendor_offers')->insert([
        'product_variant_id' => $variant->id,
        'vendor_id' => $vendorId,
        'vendor_sku' => 'CHECKOUT-1',
        'vendor_product_name' => 'LIGERO',
        'vendor_colour' => 'black',
        'vendor_size' => '42',
        'ean' => '5415132608102',
        'cost_price' => 3333,
        'stock' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    checkoutCart($this->customer, $variant);

    fillCheckout(Livewire::test(CheckoutPage::class))->call('placeOrder')->assertHasNoErrors();

    $line = Order::query()->sole()->lines->where('type', 'physical')->sole();

    expect($line->meta['vendor_code'])->toBe('SJ')
        ->and($line->meta['vendor_sku'])->toBe('CHECKOUT-1')
        ->and($line->meta['vendor_product_name'])->toBe('LIGERO')
        ->and($line->meta['vendor_colour'])->toBe('black')
        ->and($line->meta['vendor_size'])->toBe('42')
        ->and($line->meta['ean'])->toBe('5415132608102')
        ->and($line->meta['cost_price'])->toBe(3333);

    DB::table('vendor_offers')->where('vendor_sku', 'CHECKOUT-1')->update(['cost_price' => 9999, 'vendor_product_name' => 'RENAMED']);

    expect($line->fresh()->meta['cost_price'])->toBe(3333)
        ->and($line->fresh()->meta['vendor_product_name'])->toBe('LIGERO');
});

it('places an order for a variant that has no vendor offer, without a snapshot', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    fillCheckout(Livewire::test(CheckoutPage::class))->call('placeOrder')->assertHasNoErrors();

    expect(Order::query()->sole()->lines->where('type', 'physical')->sole()->meta['vendor_code'] ?? null)->toBeNull();
});

it('will not place an order when an item is no longer available in that quantity', function (): void {
    $variant = checkoutVariant(stock: 5);
    $cart = checkoutCart($this->customer, $variant);
    $cart->lines()->update(['quantity' => 4]);

    $variant->update(['stock' => 1]);

    fillCheckout(Livewire::test(CheckoutPage::class))
        ->assertSee('Some items need attention.')
        ->call('placeOrder')
        ->assertSee('Some items in your cart are no longer available in the quantity you chose.');

    expect(Order::query()->count())->toBe(0);
});

// --- Confirmation ------------------------------------------------------------------

it('shows the customer their placed order, with VAT on each line and the totals', function (): void {
    checkoutCart($this->customer, checkoutVariant('Plain Boot', 5000), checkoutVariant('Kids Shoe', 3000, 'zero-childrens'));

    fillCheckout(Livewire::test(CheckoutPage::class))->set('po_number', 'PO-77')->call('placeOrder');

    $order = Order::query()->sole();

    $html = $this->get(route('checkout.complete', $order->reference))->assertOk()->getContent();

    expect($html)->toContain('data-order-complete')
        ->toContain($order->reference)
        ->toContain('PO-77')
        ->toContain('Plain Boot')
        ->toContain('VAT 20%')
        ->toContain('data-zero-rated')
        ->toContain('Total (incl. VAT)')
        ->toContain('<meta name="robots" content="noindex">')
        ->toContain('jo@builder.example')
        ->not->toContain('<nav aria-label="Main"');
});

it('shows an order only to the customer who placed it, and only once it is placed', function (): void {
    checkoutCart($this->customer, checkoutVariant());
    fillCheckout(Livewire::test(CheckoutPage::class))->call('placeOrder');

    $order = Order::query()->sole();

    $this->actingAs(User::factory()->create(), 'web');
    $this->get(route('checkout.complete', $order->reference))->assertNotFound();

    $this->actingAs($this->customer, 'web');
    $order->update(['placed_at' => null]);
    $this->get(route('checkout.complete', $order->reference))->assertNotFound();

    auth('web')->logout();
    $this->get(route('checkout.complete', $order->reference))->assertRedirect(route('login'));
});

// --- Payment logos -----------------------------------------------------------------

it('shows only the payment logos whose files exist, so a missing file never leaves a broken image', function (): void {
    $file = public_path('images/payments/test-logo-xyz.svg');
    file_put_contents($file, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');

    try {
        $html = Blade::render('<x-checkout.payment-logos :names="[\'test-logo-xyz\', \'no-such-logo\']" />');
    } finally {
        unlink($file);
    }

    expect($html)->toContain('data-payment-logos')
        ->toContain('data-payment-logo="test-logo-xyz"')
        ->toContain('alt="Test Logo Xyz"')
        ->not->toContain('no-such-logo');

    expect(Blade::render('<x-checkout.payment-logos :names="[\'no-such-logo\']" />'))->not->toContain('data-payment-logos');
});

it('puts the card brand logos below Place order and the Stripe logo with the card option', function (): void {
    checkoutCart($this->customer, checkoutVariant());

    $html = Livewire::test(CheckoutPage::class)->html();

    // Without the official files in public/images/payments nothing is drawn, and nothing breaks.
    foreach (['visa', 'mastercard', 'stripe'] as $brand) {
        if (is_file(public_path("images/payments/{$brand}.svg"))) {
            expect($html)->toContain('data-payment-logo="'.$brand.'"');
        }
    }

    expect($html)->toContain('data-place-order');
});
