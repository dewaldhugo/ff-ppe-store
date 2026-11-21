# Cart

Read this before touching the cart, add to cart, the header cart badge, VAT on lines, delivery, the checkout, orders, or `config/lunar/{cart*,orders,payments,taxes}.php`.

## Scope

A signed-in customer adds a variant on the product page, sees the cart at `/cart` (quantities, remove, VAT on every line, delivery, totals), and checks out at `/checkout`, which takes a card payment through Stripe or places a Lunar order on 30 day account terms for customers who have them. Not built: order emails (confirmation, invoice, VAT invoice), credit limits on account terms, address autocomplete (needs an external service), delivery by weight or address, transit times, and the per-vendor fulfilment view in the admin.

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

One option, `standard-delivery`, from `App\Domain\Cart\Delivery\DeliveryPolicy`: £30.00 ex VAT, free when the goods come to MORE than £500.00 ex VAT (decision 2026-11-21; exactly £500.00 still pays £30.00, one penny over is free). The goods value is the sum of the line subtotals before discounts and before delivery. Both amounts are pence in `config/store.php` (`delivery`: `free_over` and `charge`). Delivery is standard rated, an unconfirmed assumption. More rules are expected: they go in `DeliveryPolicy`, and the pages read the option from it, never the numbers. Delivery is priced and shown at the checkout only, once a delivery address is entered. **The delivery note** ("Orders over £500.00 ex VAT get free delivery. Otherwise delivery is £30.00 ex VAT.") is `DeliveryPolicy::notice()`, built from the same two amounts, and shown by `<x-storefront.delivery-note>` on the cart page (as a note, not a price: the cart still works out no delivery), in the checkout's Delivery method section, and on the favourites quick order page. Change the note by changing the config, never the wording in a view.

`StoreShippingModifier` offers the option to Lunar's shipping manifest. It clears the manifest first, because Lunar keeps options for the whole process and ignores one whose identifier it already holds. On the checkout, before an address is saved, `CartSummary::withDelivery()` sets Lunar's `shippingOptionOverride` so the totals include delivery; at order time the option is set on the shipping address and Lunar reads it itself. `CartSummary::totals()` lays out the stack (and the VAT breakdown by rate); only the checkout uses it.

## Checkout

`/checkout`, `App\Livewire\Checkout\CheckoutPage` with its own layout (`components/layouts/checkout`): a clean header with the logo and a support phone and email, no store navigation, no footer, always noindex. An empty cart goes back to `/cart`.

- **Left column:** contact (full name, business email, phone, all required), company (registered company name, optional VAT number, optional PO number, optional order notes), delivery address (UK only, so there is no country field), a commercial or residential choice, a billing address that is the same as delivery until unticked, the delivery method (shown once the address has a line, a town and a valid postcode) and the payment method. Fields are prefilled from the customer and their default delivery address (docs/accounts.md, Member area: the address book), with a list of saved addresses to pick from and a box to save a new one. The postcode and VAT number are tidied as they are typed (`Postcode`, `VatNumber`).
- **Right column (sticky on large screens):** every line with its code (the variant SKU), quantity, ex VAT total and VAT or zero-rated label, a collapsible discount code box, and the stack in order: Subtotal, Discount (when there is one), Delivery, Net Total (ex VAT), VAT, Total (inc VAT), then Place order.
- **Discount code:** applied through Lunar's cart coupon (`carts.coupon_code`). A code that changes nothing is refused. Create discounts in the Lunar admin.
- **Payment** (`App\Domain\Checkout\PaymentMethods`, the one definition): every customer sees **Debit or credit card**. It can be chosen once Stripe is configured (both keys in `.env`, "Card payments" below); until then it shows "Not available at the moment" and Place order is disabled for a customer without terms, with a note to contact us. **30 day account** is shown, and can be chosen, only for a customer staff have switched "30 day account terms" on for in the admin (`users.account_terms`, docs/accounts.md); new customers start with it off. With card chosen the button reads **Pay by card** and takes the payment through Stripe; with 30 day account it places the order straight away. `PlaceOrder` checks the customer's methods again, so a tampered form or terms switched off mid-checkout cannot place the order. Bank transfer is gone.

`App\Domain\Checkout\PlaceOrder` does the work with Lunar: it refuses an empty cart or a line that cannot be bought as it stands, sets the shipping and billing addresses (the billing one carries the VAT number as `tax_identifier`) and the delivery option on the cart, stores `buying_for_employees`, the site type, the contact details and the payment method in the cart's meta (which becomes the order's meta), calls `createOrder()`, sets the order's `customer_reference` (the PO number) and `notes`, and places it with the `account-30-day` payment type (an offline type in `config/lunar/payments.php`: status `awaiting-payment`, `placed_at` set). The cart id is then dropped from the session, so the next visit starts a new cart.

Customers land on `/checkout/complete/{reference}`, which shows the order, its VAT on every line and the totals. It is shown only to the customer who placed it and only once it is placed; anything else is a 404. For a 30 day account order it reads "Thank you! Your order has been placed.", then Order Reference, PO Number (only when one was given), and "This order has been assigned to your 30-day credit account." on one line and "An invoice will be sent to {contact email}, with payment due within 30 days of issue." on the next (without an email on the order: "An invoice will be sent to you, ..."). A card order keeps its own wording ("Thank you, your order is placed", the reference, "Your card payment was successful and your order is being prepared."). **No invoice email is sent yet** (order emails are not built): the invoice is on the customer's Invoices page.

## Card payments (Stripe)

Card payment uses the Lunar Stripe addon (`lunarphp/stripe` 1.5.0, which brings `stripe/stripe-php` ^16; it must match the Lunar version) and Stripe's **Payment Element** embedded in the checkout, so the customer never leaves the site and card numbers never reach this server. Only cards are offered (the element is limited to `card`).

**Switching it on.** Put the keys in `.env` (test keys `sk_test_` and `pk_test_` locally, live keys in production) and clear the config cache: `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY` (both needed: `PaymentMethods::cardLive()` is true only when both are set) and `STRIPE_WEBHOOK_SECRET`. They are read in `config/services.php` under `stripe`, which is where the addon looks. With them empty (the default, and always in tests: `phpunit.xml` blanks them) card shows "Not available at the moment". **Sandbox (test mode)** needs nothing more than the test keys: Stripe's test mode is chosen by the key, and while the publishable key starts `pk_test_` the card form shows a "Test mode: no real card is charged" note with the test card number.

**The flow.**

1. The checkout form is filled in. With card chosen, **Pay by card** runs `cardCheckout()` (`components/checkout/stripe-scripts.blade.php`, loaded in the checkout layout only while card is on): it refreshes the amount (`CheckoutPage::cardAmount()`), asks Stripe to check the card details typed (`elements.submit()`), then calls `CheckoutPage::startCardPayment()`.
2. `PlaceOrder::startCard()` validates the checkout like any other, sets the addresses, delivery option and contact details on the cart, builds the **draft order** (Lunar `createOrder()`, not placed) and calls `CardPayments::intentFor()`, which creates or reuses the cart's Stripe PaymentIntent and sets its amount to exactly the order total, with the order reference as its description and metadata. The cart stays in the session.
3. The browser confirms the payment with Stripe (`stripe.confirmPayment`), with the billing details taken from the form. Stripe handles 3D Secure, then returns the customer to `/checkout/card-return?payment_intent=pi_...` (`CardReturnController`).
4. The return page uses only the intent id from the address (never `redirect_status`), finds the cart it was created for (404 for an unknown intent or another customer's cart), and asks Stripe what became of it (`CardPayments::settle()`, which runs the addon's `authorize()`). **The order is placed only when Stripe reports the payment succeeded** (status `payment-received`, `placed_at` set, charges stored as transactions). Paid: the cart leaves the session and the customer sees the confirmation. Declined or abandoned: back to the checkout with the reason, cart intact, nothing charged, and the draft order is reused on the next attempt. Still clearing (`processing`): back to the cart with a note not to pay again.
5. **Webhook backup.** `POST /stripe/webhook` (registered by the addon, outside CSRF, signature checked with `STRIPE_WEBHOOK_SECRET`) handles `payment_intent.succeeded` and `payment_intent.payment_failed` on the `default` queue after a 5 second delay, so an order is still placed when the customer paid and then closed the tab. Register the endpoint in the Stripe dashboard (Developers, Webhooks) with those two events. Locally use the Stripe CLI (`stripe listen --forward-to https://ff-ppe-store.test/stripe/webhook`), which prints the signing secret.

**Code.** `App\Domain\Checkout\CardPayments` (interface: `isConfigured`, `amount`, `intentFor`, `cartForIntent`, `settle`), `StripeCardPayments` (the one implementation, wrapping the addon), `CardResult`. Tests bind `Tests\Support\FakeCardPayments`, so no test talks to Stripe. The Lunar payment type is `card` (`config/lunar/payments.php`, driver `stripe`); `config/lunar/orders.php` has the extra order statuses the addon can set (`auth-pending`, `processing`, `requires-capture`, `failed`, `cancelled`). The addon's own migration adds the `stripe_payment_intents` table.

**Checks that cannot be automated here.** Everything above is tested against a fake. The real Stripe round trip (the card form drawing, 3D Secure, the webhook signature) must be tried by hand in test mode before go-live: card `4242 4242 4242 4242` succeeds, `4000 0027 6000 3184` asks for 3D Secure, `4000 0000 0000 9995` is declined (any future expiry, any CVC and postcode). Refunds and manual capture are not wired into the admin yet (the addon supports them in code; the capture policy is `automatic` in `config/lunar/stripe.php`).

## Payment logos

`<x-checkout.payment-logos>` shows the card brand logos (Visa and Mastercard) below Place order and the Stripe logo inside the card option. It reads `public/images/payments/{visa,mastercard,stripe}.svg` (or `.png` when there is no SVG) and draws only the files that exist, so nothing breaks while they are missing. The three PNGs there today were cropped from supplied images (Visa and Mastercard from one picture, Stripe rebuilt on a transparent background because the supplied file had a grey checkerboard baked in). The Mastercard file is the older wordmark-in-circles version, not the current symbol, so replace these with the official SVGs from each brand centre when convenient ([Stripe](https://stripe.com/newsroom/brand-assets), [Mastercard](https://www.mastercard.com/brandcenter/us/en/download-artwork.html), [Visa](https://globalclient.visa.com/brand-mark)); each asks you to accept its terms first, and Stripe's mark terms apply to the Stripe logo. Check the logos are still what you want shown now that card payment is live.

## Orders and the vendor snapshot

`App\Domain\Orders\SnapshotVendorOffers` is a step in Lunar's order creation pipeline (`config/lunar/orders.php`). It copies `vendor_code`, `vendor_sku`, `vendor_product_name`, `vendor_colour`, `vendor_size`, `ean` and `cost_price` from `vendor_offers` onto each physical order line's meta, as docs/data-contract.md requires; it is the one place the storefront reads the vendor tables. **It must stay after `CleanUpOrderLines`**: that step deletes any order line whose meta differs from its cart line's, so a snapshot taken earlier makes every product line disappear. A variant with no offer gets no snapshot. Stock is not decremented when an order is placed: the vendors' feeds are the stock authority.

## Order notification

Staff are emailed when a customer places an order. `App\Domain\Orders\NotifyStaffOfNewOrder` sends `App\Mail\OrderPlaced` (HTML with a plain text part, views `mail/order-placed` and `mail/order-placed-text`) to the addresses in `STORE_ORDER_NOTIFICATION_EMAILS` in `.env` (`config/store.php`, `order_notification_emails`; `Store::orderNotificationEmails()` takes the valid, distinct addresses, separated by commas, semicolons or spaces). The list is empty by default, so nothing is sent until it is set, and the addresses live in `.env`, not in the repository.

- **When.** Once per order, by an `Order::saved` hook in `AppServiceProvider`: the save that gives an order its placed date, from none to a date. An account order is placed at the checkout, a card order when Stripe confirms the payment (never at the draft stage), and a later change to a placed order, even its date, sends nothing more. The hook runs after the invoice number is given, so the email carries it.
- **What.** Subject "New order {reference} from {company} ({total})", then the order, invoice number, when it was placed, how it is paid (30 Day Account with the due date, or Credit Card, paid), PO and supplier number when given, the customer (company, contact, email, phone, VAT number), Deliver to and Bill to addresses, delivery instructions, each item (quantity, description, colour and sizes, code, unit price and amount ex VAT), subtotal, discount, delivery, VAT, the total and a link to the order in the admin.
- **Queue.** `ShouldQueue` on the `default` queue, so a slow or failing mail server never holds up the customer. It needs a queue worker (`composer dev` locally, Horizon in production) and a working mailer (`MAIL_*`). **Each recipient gets an email of their own** (three recipients, three queued emails), not one email with everyone in the To field, so nobody sees the others' addresses. A failure for one recipient is reported and never stops the others or the order.
- **The HTML.** A table layout with inline styles, which is what email clients need, in the brand colours: no template may hold a literal colour (`DesignTokensTest`), so `App\Support\EmailColours` reads the design tokens from `resources/css/app.css` and the mailable hands them to the view. The header uses `public/images/frontline-logo-white.png` by absolute URL, so `APP_URL` must be right on the server. Every value is escaped. The plain text part has the same content, for clients that do not show HTML.
- **Contact page enquiries go to the same people.** `Store::enquiryRecipients()` returns the same list, so one set of addresses gets both orders and enquiries, each person in an email of their own. The customer is told the enquiry was sent if it reached at least one person; each failure is reported, and only a failure for everyone shows the error. Only while the list is empty does an enquiry go to the single address in `config/store.php` (`enquiry_email`: `STORE_ENQUIRY_EMAIL`, or the store email), so an enquiry is never lost. That email stays plain text, sent at once, with the customer as its reply-to.
- **Tests.** `tests/Feature/Cart/OrderNotificationTest.php` and `tests/Feature/Storefront/ContactPageTest.php`, with made-up addresses.

## Known gaps

- A variant with no GBP price breaks Lunar's calculation (`MissingCurrencyPriceException`, as on the product page, see CLAUDE.md). Only draft products can have one, and a draft product cannot be added; a cart line that goes this way after a product is withdrawn is not handled.
- No guest cart and no "save for later".
- A failed or abandoned checkout leaves a draft order (status `awaiting-payment`, no `placed_at`); the next attempt reuses it. Nothing cleans them up.
- No order emails, so nothing confirms an order to the customer except the confirmation page.

## Tests

`tests/Feature/Cart/`: `CartAccessTest` (guests, staff, noindex, empty state), `CartTest` (add, stock across repeat adds, quantity changes, removal, another customer's line, unavailable lines, header badge, mini cart, logout and login), `VatAndDeliveryTest` (the 20% on protective boots, zero-rated lines, delivery at and around £1,000, the stack) and `PaymentTermsTest` (30 day terms, tampering), `CardPaymentTest` (the card flow against a fake Stripe: draft order and intent, the return page, the webhook route) and `CheckoutTest` (layout, prefill, summary, validation, placing the order, the vendor snapshot, the confirmation page). `PagesTest` and `LayoutTest` cover the routes and the badge markup.
