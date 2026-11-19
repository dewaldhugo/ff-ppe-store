# Cart

Read this before touching the cart, add to cart, the header cart badge, VAT on lines, delivery, the checkout, orders, or `config/lunar/{cart*,orders,payments,taxes}.php`.

## Scope

A signed-in customer adds a variant on the product page, sees the cart at `/cart` (quantities, remove, VAT on every line, delivery, totals), and checks out at `/checkout`, which places a Lunar order paid by bank transfer. Not built: order emails (confirmation, bank details, VAT invoice), card payments (Stripe), pay on account (30 day terms, to be managed in the admin), address autocomplete (needs an external service), delivery by weight or address, transit times, an order history in My Account, and the per-vendor fulfilment view in the admin.

## Lunar owns the cart

Carts, lines, pricing, stock validation and tax come from Lunar (`Lunar\Models\Cart`, the `CartSession` facade, the pipelines and validators in `config/lunar/cart.php`). Nothing in the storefront calculates a price or checks stock itself. `App\Domain\Cart\CustomerCart` is the one entry point for changes and closes three gaps in Lunar's own actions:

- **Adding a variant that is already in the cart** goes through `updateLine()` with the new total. Lunar's add validators only check the quantity being added, so two adds of 3 could pass a stock of 4.
- **Line ids are checked against the customer's own cart.** `Cart::updateLine()` updates any line by id, whatever cart it belongs to. `CustomerCart::setQuantity()` and `remove()` refuse an id that is not in the current cart. Never call `updateLine()` with an id that came from the browser without this.
- A product that is no longer published is refused by `CustomerCart::add()` (`ProductVariant::isPurchasable()`); Lunar's availability validator checks channel and customer group, not status.

A refusal throws `App\Domain\Cart\CartRejected`, whose message is written for the customer.

## Who has a cart

Signed-in customers only (`auth:web` on `/cart` and `/checkout`; `ProductPage::addToCart()` refuses a guest). A guest who opens `/cart` is sent to log in and back. Email verification is not needed, as it is not needed to see prices. A staff member signed in to Lunar is a guest here.

## Cart session

- Lunar keeps the cart id in the session (`lunar_cart`). `CartSession::manager()` creates the cart on the first add (`auto_create` stays false, so browsing creates no rows), with the customer's `user_id` and Lunar customer.
- `delete_on_forget` is **false**: logging out clears the cart id from the session (Lunar's logout listener), so the next visitor on that browser does not see it, but the cart stays in the database. Lunar's login listener puts the customer's active cart back in the session at the next login, on any device. Carts are never pruned (`prune_tables.enabled` is false).
- Customer logout regenerates the session and never invalidates it (docs/accounts.md); the cart id is removed by Lunar's listener, not by the session.

## Header badge

`App\Support\CartCount::get()` is the sum of line quantities, read with one query and no pricing (the header renders on every page). It reads the session's cart id and counts nothing for a guest or a deleted cart. The badge is server-rendered, then kept current by Alpine: `ProductPage` and `CartPage` dispatch a `cart-updated` event with `count`, and the header's cart link listens on `window`.

## Mini cart

Hovering (or keyboard-focusing) the header cart icon drops down `App\Livewire\Cart\MiniCart`: up to four lines (image, name, options, quantity x unit price, line total), "and N more items", the ex VAT subtotal (no VAT or delivery, as on the cart page), View cart and Checkout. It is large screens only (`max-lg:hidden`; on a touch screen the icon is a plain link to `/cart`) and is left out on the cart page itself.

The header renders on every page and pricing a cart is not free, so the panel loads nothing until the first hover: the header's Alpine handler dispatches `mini-cart-open`, and the component then also refreshes on `cart-updated`. Before that it shows "Loading your cart...", and no cart lines are in the page HTML. The icon and the panel share a wrapper with no gap between them, so the pointer can cross without closing it. Checkout is disabled when any line has a problem, including lines past the first four. Line mapping is shared with the cart page in `CartLineViews`. There is no remove button in the mini cart; removing is on `/cart`.

## The cart page

`App\Livewire\Cart\CartPage`, view `livewire/cart/cart-page`. Lines show brand, name (linked), colour and size, price, a quantity field (saved on blur), line total ex VAT and Remove. The "Cart summary" shows Items and the Subtotal ex VAT, a note that VAT and delivery are worked out at checkout, and a Proceed to checkout button. **The cart page and the mini cart show no VAT and no delivery, and do not price delivery** (decision 2026-11-19): both are worked out and shown at the checkout only. Lunar's own cart calculation still runs a tax step internally, but nothing from it is displayed. Unit prices are Lunar's, recalculated on every load, so a price change shows at once.

A line whose product was withdrawn, or whose stock fell below its quantity, since it was added is flagged on the page and "Proceed to checkout" is disabled until it is fixed. The subtotal still includes such a line.

## VAT

Every customer is a vetted business buying for its employees (decision 2026-11-19, docs/data-contract.md, VAT), so there is no "buying for employees" question and `buying_for_employees = true` goes on every order.

- Lunar prices tax through the tax driver. `lunar.taxes.driver` is `store`: `App\Domain\Pricing\Vat\StoreTaxDriver` is Lunar's system driver plus one rule, a `zero-protective` variant (protective boots and helmets) is charged at the `standard` class's rate, 20%. The class itself keeps its 0% individual rate in Lunar, so what the ingestion module writes is unchanged.
- Only `zero-childrens` lines are zero-rated. They show a "Zero-rated: 0% VAT" label (`data-zero-rated`) on the checkout summary and the confirmation; every other line shows "VAT 20%: £x" there. **VAT is shown at the checkout and on the confirmation only, never on the cart page or the mini cart.**
- `CartLineViews` gives each line `vatRate`, `vat`, `incVat` and `zeroRated`, read from Lunar's line tax breakdown. Nothing recalculates VAT.
- The order's VAT is the sum of each line's VAT plus the VAT on delivery (Lunar's way), so it can differ by a penny from VAT worked out once on the combined ex VAT total. Both are valid on an invoice if used consistently.

## Delivery

One option, `standard-delivery`, from `App\Domain\Cart\Delivery\DeliveryPolicy`: £50.00 ex VAT, free when the goods come to £1,000.00 or more ex VAT (exactly £1,000.00 is free). The goods value is the sum of the line subtotals before discounts and before delivery. Both amounts are pence in `config/store.php` (`delivery`). Delivery is standard rated, an unconfirmed assumption. More rules are expected: they go in `DeliveryPolicy`, and the pages read the option from it, never the numbers. Delivery is priced and shown at the checkout only, once a delivery address is entered.

`StoreShippingModifier` offers the option to Lunar's shipping manifest. It clears the manifest first, because Lunar keeps options for the whole process and ignores one whose identifier it already holds. On the checkout, before an address is saved, `CartSummary::withDelivery()` sets Lunar's `shippingOptionOverride` so the totals include delivery; at order time the option is set on the shipping address and Lunar reads it itself. `CartSummary::totals()` lays out the stack (and the VAT breakdown by rate); only the checkout uses it.

## Checkout

`/checkout`, `App\Livewire\Checkout\CheckoutPage` with its own layout (`components/layouts/checkout`): a clean header with the logo and a support phone and email, no store navigation, no footer, always noindex. An empty cart goes back to `/cart`.

- **Left column:** contact (full name, business email, phone, all required), company (registered company name, optional VAT number, optional PO number, optional order notes), delivery address (UK only, so there is no country field), a commercial or residential choice, a billing address that is the same as delivery until unticked, the delivery method (shown once the address has a line, a town and a valid postcode) and the payment method. Fields are prefilled from the customer and the company address given at registration. The postcode and VAT number are tidied as they are typed (`Postcode`, `VatNumber`).
- **Right column (sticky on large screens):** every line with its code (the variant SKU), quantity, ex VAT total and VAT or zero-rated label, a collapsible discount code box, and the stack in order: Subtotal, Discount (when there is one), Delivery, Net Total (ex VAT), VAT, Total (inc VAT), then Place order.
- **Discount code:** applied through Lunar's cart coupon (`carts.coupon_code`). A code that changes nothing is refused. Create discounts in the Lunar admin.
- **Payment:** bank transfer only. Card is shown as "Coming soon". Pay on account is not shown: no account terms or credit limit exists yet.

`App\Domain\Checkout\PlaceOrder` does the work with Lunar: it refuses an empty cart or a line that cannot be bought as it stands, sets the shipping and billing addresses (the billing one carries the VAT number as `tax_identifier`) and the delivery option on the cart, stores `buying_for_employees`, the site type, the contact details and the payment method in the cart's meta (which becomes the order's meta), calls `createOrder()`, sets the order's `customer_reference` (the PO number) and `notes`, and places it with the `bank-transfer` payment type (an offline type in `config/lunar/payments.php`: status `awaiting-payment`, `placed_at` set). The cart id is then dropped from the session, so the next visit starts a new cart.

Customers land on `/checkout/complete/{reference}`, which shows the order, its VAT on every line and the totals. It is shown only to the customer who placed it and only once it is placed; anything else is a 404. It says staff will contact the customer with bank details: no email is sent yet.

## Payment logos

`<x-checkout.payment-logos>` shows the card brand logos (Visa and Mastercard) below Place order and the Stripe logo inside the card option. It reads `public/images/payments/{visa,mastercard,stripe}.svg` (or `.png` when there is no SVG) and draws only the files that exist, so nothing breaks while they are missing. The three PNGs there today were cropped from supplied images (Visa and Mastercard from one picture, Stripe rebuilt on a transparent background because the supplied file had a grey checkerboard baked in). The Mastercard file is the older wordmark-in-circles version, not the current symbol, so replace these with the official SVGs from each brand centre when convenient ([Stripe](https://stripe.com/newsroom/brand-assets), [Mastercard](https://www.mastercard.com/brandcenter/us/en/download-artwork.html), [Visa](https://globalclient.visa.com/brand-mark)); each asks you to accept its terms first, and Stripe's mark terms apply to the Stripe logo. Card payment is not live: the card option says "Coming soon" and bank transfer is the only method, so check the logos are still what you want shown until Stripe is connected.

## Orders and the vendor snapshot

`App\Domain\Orders\SnapshotVendorOffers` is a step in Lunar's order creation pipeline (`config/lunar/orders.php`). It copies `vendor_code`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`, `ean` and `cost_price` from `vendor_offers` onto each physical order line's meta, as docs/data-contract.md requires; it is the one place the storefront reads the vendor tables. **It must stay after `CleanUpOrderLines`**: that step deletes any order line whose meta differs from its cart line's, so a snapshot taken earlier makes every product line disappear. A variant with no offer gets no snapshot. Stock is not decremented when an order is placed: the vendors' feeds are the stock authority.

## Known gaps

- A variant with no GBP price breaks Lunar's calculation (`MissingCurrencyPriceException`, as on the product page, see CLAUDE.md). Only draft products can have one, and a draft product cannot be added; a cart line that goes this way after a product is withdrawn is not handled.
- No guest cart and no "save for later".
- A failed or abandoned checkout leaves a draft order (status `awaiting-payment`, no `placed_at`); the next attempt reuses it. Nothing cleans them up.
- No order emails, so nothing confirms an order to the customer except the confirmation page.

## Tests

`tests/Feature/Cart/`: `CartAccessTest` (guests, staff, noindex, empty state), `CartTest` (add, stock across repeat adds, quantity changes, removal, another customer's line, unavailable lines, header badge, mini cart, logout and login), `VatAndDeliveryTest` (the 20% on protective boots, zero-rated lines, delivery at and around £1,000, the stack) and `CheckoutTest` (layout, prefill, summary, validation, placing the order, the vendor snapshot, the confirmation page). `PagesTest` and `LayoutTest` cover the routes and the badge markup.
