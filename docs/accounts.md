# Customer accounts

Read this before touching registration, login, password reset, email verification, My Account, the account routes, or the customer and staff guards.

## Scope

Registration, login, logout ("keep me logged in"), password reset by email, email verification and My Account (view and edit contact and company details, change password). Customers are business buyers: prices stay ex VAT.

Not built: the items under "Known gaps". Orders and invoices are built ("Member area" below). The cart and checkout are built (docs/cart.md).

## Prices are for signed-in customers only

Guests see no prices anywhere on the storefront. A signed-in customer (the `web` guard) sees them, ex VAT, as before; only a customer whose login staff have activated can sign in (see "Approval"). Email verification is not required to see prices.

- One definition: `App\Domain\Pricing\PriceVisibility::visible()`, reached through `PriceDisplay::visible()`. A staff member signed in to Lunar is a guest here.
- Guests get no price and no prompt: the price is simply absent. `PriceDisplay::fromPrice()` returns null for guests, which covers product cards on listings, search, brand pages, the home page and related products; the product page checks `PriceDisplay::visible()`. Any new view that renders a price must do the same.
- The header cart link and its count badge are hidden from guests. `/cart` requires a signed-in customer (a guest is sent to log in). `/checkout` requires a signed-in customer too.
- The product page also hides the quantity field and the add to cart button from guests (`ProductPage::addToCart()` refuses a guest too). Options and stock still show, with one exception: a guest sees every size (`*_size` options) whether it is in stock or not, as plain non-clickable tags (`data-size-readonly`, no strike-through or out of stock label), and `ProductPage::choose()` refuses a size from a guest. Colours stay clickable, because they change the gallery.
- Guests cannot probe prices: the price filter and the two price sorts are hidden, and `price_min`, `price_max` and `sort=price-asc|price-desc` are ignored for a guest (in the URL and in Livewire updates; `CategoryListing::render()` resets them).
- Product structured data (JSON-LD) has no `offers` for guests, so search engines see no prices either. Rich price results will not appear in search until that changes.
- Listings and product pages are not cached per visitor; do not add a shared page or response cache without varying it on sign-in.

Tests: `tests/Feature/Storefront/PriceGatingTest.php` (guests) and the price tests, which sign in first.

## Approval, and what staff control (branch feat/admin)

A new registration cannot sign in until staff activate it in the admin panel.

- **Registering** creates the User and Customer as before, but does not sign the customer in. They are sent to the home page, where the storefront notice banner (shown once) says "Your registration was successful. We will contact you shortly.", and `CustomerRegistrationReceived` (default queue) emails that they will be told when the login has been activated. No verification email is sent at registration.
- **Activating.** `users.approved_at` (null until activated) and `users.account_terms` (boolean, default false) are added by `2026_11_19_100000_add_approval_and_terms_to_users_table`, which backfills `approved_at` for every existing user so nobody is locked out. They are not mass assignable. `User::isApproved()` and `User::hasAccountTerms()` read them.
- **Where staff do it.** Admin panel, Customers, Edit: a "Storefront access" section with two toggles, **Login active** and **30 day account terms** (`App\Admin\Extensions\CustomerAccessResourceExtension` and `CustomerAccessEditExtension`, registered with `LunarPanel::extensions()` in `AppServiceProvider`). The customers list has Login and 30 day terms columns and an "Awaiting activation" filter. The section shows on the edit page only, and only for a customer that has a login (a customer made in the admin has none). `App\Domain\Accounts\SetCustomerAccess` applies the toggles to every login attached to the customer, in one transaction.
- **The Approve button.** A green **Approve** button (with a confirmation) activates a waiting registration in one click: on each row of the customers list, as a bulk action ("Approve selected"), and in the header of the Edit and View Customer pages (`ApproveCustomerAction`). It shows only while a login on the customer is still waiting, leaves 30 day terms as they are, and sends the activation email. The toggle on the Edit page does the same thing.
- **The activation email.** Switching Login active on for a login that was off sets `approved_at`, marks the email address verified (staff vetted the business, and the email goes to that address; this replaces the verification link for new customers) and sends `CustomerAccountActivated` (default queue) with the login URL and the username (the email address). **The password is never stored in a recoverable form, so it is never emailed:** the message says to use the password chosen at registration and links the password reset page. A failed send is reported and never undoes the activation; switching the login off and on again resends it. Saving again with the toggle already on sends nothing.
- **Signing in.** `LoginRequest` gives a customer whose email and password are right but whose login is not active the message "Your login has not been activated yet. We will email you as soon as it is." (`LoginRequest::PENDING`). It is not counted as a failed attempt. A wrong password on a pending login gets the ordinary message, so nothing is revealed to a stranger.
- **Switching a login off** signs the customer out on their next request: `EnsureLoginActive` is appended to the `web` middleware group, logs out the `web` guard only (regenerates the session, never invalidates it) and redirects to log in.
- **Password reset** still works for a pending customer, but they still cannot sign in.
- Payment options per customer are in docs/cart.md (Payment).

## Company logo

Staff can give a member a company logo, which the member sees, with their company name, in a small heading at the top of every account page. Customers cannot change it.

- **Where staff set it.** Admin panel, Customers, Edit: a "Company logo" section with an upload (`CustomerLogoResourceExtension` and `CustomerLogoEditExtension`, registered beside the access extensions in `AppServiceProvider`). PNG, JPG or WebP, up to 2 MB; SVG is refused on purpose (an SVG opened on its own can run scripts). Like the access section it shows on the edit page only, and only for a customer that has a login. Removing the file in the form clears the logo.
- **Storage.** The image is on the `public` disk in `customer-logos/` (random file names), and its path is in the Lunar customer's `meta` under `logo`, beside `industry`. No Lunar table changes. It needs the `public/storage` link (`storage:link --force`, already a deploy step). `App\Domain\Accounts\CustomerLogo` is the one place that reads and writes it: it accepts only a path inside `customer-logos/`, and replacing or removing a logo deletes the old file.
- **Display.** The company heading is `<x-account.company-heading>` (`data-account-company`): the logo (`data-account-logo`, at most 2.5rem tall) beside the company name (`data-account-company-name`), above the page title. It is in `components/layouts/member` (My details, Orders, an order, Invoices, Addresses and the address form) and on the Favourites page. It reads the signed-in `web` customer itself, so a page cannot show another customer's company. The logo comes from `CustomerLogo::url()` (null when none is set or the file has gone); the picture has empty alt text when the name is beside it, and "Company logo" when there is no name. With neither a name nor a logo nothing is shown. It is not on login, register, the printable invoice, the cart or the checkout.
- **Not covered.** No resizing or cropping (the image is scaled to fit), and the logo is not on invoices or emails.

## Favourites

One "Add to favourites" button, on the product page only (`<x-storefront.favourite-button>`, beside Add to cart: the same size, as the outlined secondary button, with a heart icon). There is no bookmark on product cards in listings, search, brand pages, the home page, related products or the favourites page: a customer removes a favourite from its product page.

- **Who.** Signed-in customers (`web` guard) only. A guest sees no button at all and the toggle route needs a login. A staff member signed in to Lunar is a guest here.
- **Data.** `favourites` (`user_id`, `product_id` to `lunar_products`, unique together, cascade on delete), model `App\Models\Favourite`. Storefront-owned; the ingestion module never writes it. Only a published product can be added; a saved product can always be removed, and a withdrawn one is left out of the list.
- **Code.** `App\Domain\Favourites\Favourites` (scoped to the request: reads the customer's ids once, however many cards ask; `toggle()`, `has()`, `count()`, `products()`), `FavouriteController`, view `favourites/index`.
- **Routes.** `GET /favourites` (name `favourites`, noindex) and `POST /favourites/{product}` (name `favourites.toggle`), both `auth:web`. The toggle answers JSON (`favourited`, `count`) to fetch, and redirects back for a plain form post (no JavaScript).
- **Quick order.** `/favourites/quick-order` (route `favourites.quick-order`, `auth:web`, noindex; `App\Livewire\Favourites\QuickOrder`, view `livewire/favourites/quick-order`) lists every published favourite on a row: image, name, price ex VAT ("From" until a size is picked, then the exact price), a colour and a size drop-down, a quantity (default 1) and an **Add to order** button that puts that variant in the cart. A "Quick order" button on the favourites page links to it, and it carries the delivery note (docs/cart.md, Delivery). The choices use the product page's rules (`VariantSelection`): the first in-stock colour is chosen, a size never is, a size the chosen colour does not come in is dropped when the colour changes, and an out of stock size is listed but cannot be picked (the EU size is shown beside each UK size). Adding goes through `CustomerCart::add()`, so stock is checked again against what is already in the cart, a product withdrawn since is refused, and a row can only add one of the customer's own favourites. A row says what happened ("Added 3 to your order.", "Please select a size.", "Only 5 available in this size.") and the header cart count updates through the `cart-updated` event. Tests: `QuickOrderTest`.
- **Header.** A heart link with a count sits beside the cart for signed-in customers; the product page button announces the new count with a `favourites-updated` window event, as the cart does.

## Member area: menu, orders, invoices

A signed-in customer has a menu to **My details**, **Orders**, **Invoices**, **Addresses** and **Favourites**, with **Delivery information** and **Returns and refunds** set apart under them (those two link to the Delivery Information and Returns and Refunds pages), in two places: the "My account" link in the top bar opens a dropdown (without JavaScript it is a plain link to My Account, which has the same menu), and every member page shows the menu beside the content, above it on a phone (`components/layouts/member`, which My Account now uses; login and register keep `layouts/account`).

- **Routes** (all `auth:web` and `account.verified`, noindex, already disallowed in robots.txt): `account.orders` (`/account/orders`), `account.orders.show` (`/account/orders/{reference}`), `account.invoices` (`/account/invoices`), `account.invoices.show` (`/account/invoices/{reference}`).
- **Scope.** `Account\OrderController::placedOrders()` is the one query: the customer's own orders (`user_id` of the `web` user), placed only, newest first. An order that is another customer's, unplaced or missing is a 404, for the order page and the invoice page alike. Nothing takes a user id from the request. Lists show 10 per page.
- **Orders.** The list shows the reference (and PO number), date, status and total including VAT. The order page shows the placed time, the status (the label staff see in the admin, `App\Domain\Orders\OrderStatus`), payment, the lines with VAT, the totals and the delivery address, through `<x-orders.detail>`, which the checkout confirmation page shares so the two always agree. The confirmation page links to the invoice and the orders list.
- **Invoices.** One per placed order, as a printable invoice page (`account/invoices/show`, in the clean checkout layout; the browser's Print or Save as PDF, no PDF package), laid out from the client's template. There is no invoices table for the invoice itself: `App\Domain\Orders\Invoice` works the date, payment state and totals out from the order. The date is when the order was placed, and a 30 day account order is due 30 days later (`Invoice::TERMS_DAYS`).
  - **Number.** `DDMMYYYY/NNN`: the date the order was placed and that day's sequence from 001 (`10102026/001`, `10102026/002`, then `11102026/001`). `App\Domain\Orders\InvoiceNumbers` gives it once, when the order is placed (an `Order::saved` hook in `AppServiceProvider`, so the checkout, the Stripe webhook and staff all get it), and it never changes afterwards, even if the order's date is edited. It lives in `invoice_numbers` (`order_id` unique, `invoice_date` + `sequence` unique, `number` unique), which stops two orders sharing a number; a collision retries. The migration numbers orders placed before it existed, in placed order. An order that somehow has none gets its number the first time the invoice is looked at.
  - **Top left.** The Frontline logo, then the sender, all fixed in `config/store.php` under `invoice` (no environment variable, it never changes): **Frontline Holdings Ltd** (bold and bigger), Frontline Footwear Ltd, the address, VAT Number 377 3202 94, accounts@frontlinesafetygear.com and www.frontlinesafetygear.com (`Store::invoice()`).
  - **Top right.** TAX INVOICE (big and bold, capitals), then Invoice Date, Invoice Number, Purchase Order Number (when given), the customer's VAT Number (when given) and Supplier Number (when given).
  - **Boxes.** A rule, then two boxes with dark title bars: **Bill to** (the customer's company, contact, billing address and VAT number) and **Deliver to** (the delivery address). The dark bars carry `print-color-adjust: exact`, because browsers leave backgrounds out of a print otherwise, and the site header is hidden in the print (`print:hidden` on the checkout layout's header).
  - **Lines and totals.** Description (with the product code), Colour, UK Size, EU Size, Quantity, Unit Price, VAT (the rate) and Amount (ex VAT), then Subtotal (goods ex VAT before any discount), Discount (when there is one), Delivery, TOTAL VAT (with the rate when every line has the same one) and TOTAL. The totals are rows at the foot of the same table as the lines (a second `tbody`, so a printed invoice that runs to a second page does not repeat them), and each label spans every column but the last, with its amount in the Amount column. The bordering copies the client's sample: no box or column lines, a light grey header with a thick black rule under it (`print-color-adjust: exact`, so the grey prints), a thin grey rule under every line, the totals unruled, and a thick black rule above TOTAL. Delivery is a row in the totals, not a line. The rows are worked out from the order total, so they always add up. A zero-rated line shows 0%. The pound sign is on the TOTAL only: every other amount (unit prices, line amounts, subtotal, discount, delivery, VAT) is a plain number such as 27.90, worked out from whole pence by `Invoice::amount()`. Colour, size and EU size columns show only when some line has one, and the size heading is "Size" when a line has another kind of size than a UK footwear size.
  - **Payment.** A rule, then **Payment Method**: "Credit Card" or "30 Day Account" (`Invoice::paymentMethodLabel()`). A 30 day account invoice still to pay shows **Due Date** and the bank details as the client worded them (account name, bank, sort code, account number, IBAN and Wise's address; `Store::bankDetails()`, fixed in `config/store.php` under `bank`). A card invoice shows **Status: Paid**, and so does an account invoice once staff move the order to **Payment Received** in the admin. There is no payment advice slip.
  - **Printing.** Print or Save as PDF gives one A4 page with no browser header or footer (the web address, page title, date and page numbers): the page has `@page { size: A4; margin: 0; }`, which leaves the browser no margin to print them in, and the article carries its own 11mm padding. A smaller root font size in the print (`@media print { html { font-size: 12px; } }`) scales every rem-based size down together, the product code goes on the description's line, and rows, boxes and spacing are tighter. Checked in a headless Edge print: an invoice with 13 line items, delivery and the bank details fits on one page, and one with 19 runs to a second. The site header, the buttons and the page background are left out (`print:hidden`, `print:bg-white`). The style block is in `account/invoices/show.blade.php` only, so it never affects the checkout or confirmation pages.
  - **Footer.** "Company Registration No: 13184904. Registered Office: Attention: Frontline Footwear Ltd, 7 Lynnwood Court, Priestlands Place, Lymington, SO41 9GA" (`config/store.php`, `invoice`), worded as the client gave it.
  - **Colour and sizes.** `App\Domain\Orders\SnapshotLineOptions`, a step in the order creation pipeline (`config/lunar/orders.php`, after `CleanUpOrderLines`), copies the variant's colour, size, whether the size is a UK footwear size and the `eu_size` attribute onto each goods line's meta (`colour`, `size`, `size_is_uk`, `eu_size`), so an invoice never changes when the variant does. A line from an order placed before this shows the option text under its description instead.
  - **Purchase order and supplier number.** The checkout asks for both, each optional (`po_number`, `supplier_number`): the PO number goes on the order as `customer_reference`, the supplier number into the order's meta (`supplier_number`). The supplier number is the number the customer uses for the store in their own purchasing system, which is the same for every order, so it is also a customer field: staff set it in the admin (Customers, Edit, "Invoice details", `CustomerSupplierNumberResourceExtension` and `CustomerSupplierNumberEditExtension`, kept in the Lunar customer's meta as `supplier_number`), and the checkout fills it in from there. The customer can change it for one order, but only staff change the customer's own number. An invoice prints it only when the order has one.
- **Buy again.** A button on the orders list and the order page posts to `account.orders.reorder` (`POST /account/orders/{reference}/reorder`, `Account\OrderController::reorder`). `App\Domain\Orders\Reorder` puts the goods of that order into the customer's cart in the same quantities, on top of what is already there, through `CustomerCart::add()`, so a product withdrawn since or short of stock is refused with its reason and left out while the rest go in. The old delivery line is never copied; prices are today's and VAT and delivery are worked out again at checkout. It goes to the cart with a message ("Added 3 items from order ... Not added: ..."), or stays on the order with the reasons when nothing could be added (Lunar may leave an empty cart behind in that case). Only the customer's own placed orders can be bought again.
- **Address book.** Saved delivery addresses, at `/account/addresses` (`account.addresses`, `.create`, `.store`, `.edit`, `.update`, `.destroy`, `.default`; all `auth:web` and `account.verified`). They are Lunar addresses on the customer (`lunar_addresses`), managed by `App\Domain\Accounts\AddressBook`: the label is kept in `meta.label`, the country is always the UK, and one address is the default for delivery (`shipping_default`; the first one saved is the default, a later one only when ticked, deleting the default hands it to the oldest left). Every lookup goes through the signed-in customer's own address book, so an id for anyone else's address is a 404 (the route parameter is `{addressId}`, not `{address}`). Up to 25 are kept. A user with no Lunar customer gets one on their first address (`EnsureCustomer`). The company address given at registration is the billing address and is in the list too. **Checkout:** it starts from the default delivery address (else the billing one, else the first), a "Use a saved address" list fills the delivery fields, typing over them steps the list back to "A different address", and a "Save this address to my address book" box (shown for an address not already saved) saves it once the order, or the card payment, has started, never twice (same first line and postcode) and never failing the order.
- **Payment terms.** The My details page has a Payment terms section: card, and 30 day account as Enabled or Not enabled (with a link to contact us). With terms on it lists the invoices still to pay (`App\Domain\Orders\OpenInvoices`: placed 30 day account orders that are not Payment Received, soonest due first) with the due date, an **Overdue** flag once the due day has passed (`Invoice::isOverdue()`), the amount and the total to pay. The invoices list and an invoice show the same state ("Overdue, was due by ..."). Nothing is chased: there are no reminder emails or late fees.
- **Invoices in the admin, and the invoice PDF (staff only).** Under Sales, beside Orders, the **Invoices** list (`App\Admin\Resources\InvoiceResource`, read-only, for staff with `sales:manage-orders`) shows every placed order with its invoice number (a left join on `invoice_numbers`, so an order whose number has not been given yet still shows, blank), date, company, order number, PO number, payment state (Paid, Payment due by a date, or Overdue, from `Invoice::paymentStatus()`) and total including VAT. It searches by invoice number, company, order number and PO number, and filters by payment (paid, not yet paid, overdue) and by date. The **Invoice PDF** button (`App\Admin\Extensions\DownloadInvoicePdfAction`) is on each row of that list, in the header of the order page and on each row of the dashboard's Latest orders; it is hidden for an order that was never placed. The PDF is made by `App\Domain\Orders\InvoicePdf` (the `barryvdh/laravel-dompdf` package Lunar already requires) from `admin/pdf/invoice.blade.php`, which shows what the customer's invoice page shows, from the same `App\Domain\Orders\Invoice`, the same sender, bank and registration details from `config/store.php`, and the same Paid or due-date-and-bank-details ending. The file is named `Invoice-DDMMYYYY-NNN.pdf`. A PDF cannot read the CSS variables, so its few colours are constants in `InvoicePdf::COLOURS`, and a test keeps them equal to the design tokens in `resources/css/app.css`; the template keeps to tables and plain CSS for the same reason. Customers still print their own invoice from the browser.
- **Not built.** Credit notes and refunds on invoices, a PDF download for customers (the browser's Print or Save as PDF does that), invoice emails, and a billing address book (only delivery addresses are saved; checkout's billing address is the delivery one or typed).

## Approach

Hand-rolled on Laravel's own auth primitives: the `web` guard, the `users` password broker, `EmailVerificationRequest`, signed URLs and `RateLimiter`. No Fortify, Breeze, Jetstream or other package. Plain controllers, Form Requests and Blade views, because login, logout and verification write the session and redirect, and the storefront has a no-JavaScript variant. The Livewire `EnquiryForm` is the exception that proves the rule: it writes no session.

Code:

- `app/Http/Controllers/Auth/*` and `app/Http/Controllers/AccountController.php`
- `app/Http/Requests/Auth/*` and `app/Http/Requests/Account/*`
- `app/Domain/Accounts/`: `RegisterCustomer`, `UpdateCustomerProfile`, `CustomerSearch`, `RateLimits`, `VatNumber`
- `app/Http/Middleware/EnsureAccountVerified.php` (alias `account.verified`)
- `app/Notifications/CustomerVerifyEmail.php`, `CustomerResetPassword.php`
- Views: `resources/views/auth/*`, `resources/views/account/show.blade.php`, `components/layouts/account`, `components/ui/{field,alert}`, `components/storefront/honeypot`

## Routes

| Name | Method and URI | Middleware |
|---|---|---|
| `register` | GET, POST `/register` | `guest:web`; POST `throttle:register` |
| `login` | GET, POST `/login` | `guest:web`; POST `throttle:login` |
| `logout` | POST `/logout` | none (open to everyone, so a double click is harmless) |
| `password.request` | GET `/forgot-password` | `guest:web` |
| `password.email` | POST `/forgot-password` | `guest:web`, `throttle:password-email` |
| `password.reset` | GET `/reset-password/{token}` | `guest:web` |
| `password.store` | POST `/reset-password` | `guest:web`, `throttle:password-reset` |
| `verification.notice` | GET `/email/verify` | `auth:web` |
| `verification.verify` | GET `/email/verify/{id}/{hash}` | `auth:web`, `signed`, `throttle:6,1` |
| `verification.send` | POST `/email/verification-notification` | `auth:web`, `throttle:verification-send` |
| `account` | GET `/account` | `auth:web`, `account.verified` |
| `account.profile.update` | PUT `/account/profile` | `auth:web`, `account.verified` |
| `account.password.update` | PUT `/account/password` | `auth:web`, `account.verified` |

`password.reset` and `verification.verify` are the names Laravel's notifications and `EmailVerificationRequest` look for: do not rename them. Every auth page carries `<meta name="robots" content="noindex">`. `public/robots.txt` is unchanged (it already disallows `/account`); a crawler must be able to fetch the other pages to see their noindex. `bootstrap/app.php` sends guests to `login` and signed-in customers away from guest pages to `account`.

## Data

A customer is two records, created together in one database transaction by `RegisterCustomer`:

- `users`: `name` ("First Last"), `email` (lower case, unique), `phone` (nullable, added by `2026_11_18_100000_add_phone_to_users_table`), `password`, `email_verified_at`.
- Lunar `Customer` (`lunar_customers`), attached through the `lunar_customer_user` pivot (`$user->customers()`): `first_name`, `last_name`, `company_name`, `tax_identifier` (the VAT number; Lunar 1.5 renamed `vat_no` to `tax_identifier`). Lunar never creates customers for users, so only these two actions do.
- Registration also asks for the **company address** and the **industry**, both required (address line 2 and county are optional). The address is saved by `RegisterCustomer` as a Lunar `Address` on the customer (`$customer->addresses()`), as the billing default, with the company name, the contact email and phone, and the UK country (`GB`, from the seeded Lunar country; the store sells to UK customers only, so the form has no country field). Registration accepts any postcode (UK and EU customers), max 12 characters: a UK one is tidied to upper case with one space (`App\Domain\Accounts\Postcode`), any other is kept as typed. The address book and checkout still check the UK format. The industry is a slug from `App\Domain\Accounts\Industries`, stored in the customer's `meta` as `industry`. My Account does not show or edit either yet (see Known gaps).

The Customer is also attached to Lunar's default customer group (`CustomerGroup::getDefault()`, the seeded `retail` group), so pricing resolves exactly as it does for a guest. If no default group exists the customer is still created, the problem is reported with `report()`, and pricing falls back to the default group by itself.

`UpdateCustomerProfile` writes both records in one transaction. A user with no customer (for example one made by a seeder) gets one on their first profile save.

### Search indexing

`lunar_customers` is Scout-indexed (admin search). With `SCOUT_QUEUE=false` Scout would call Meilisearch inside the transaction, and an outage would roll the sign-up back. So both actions save the customer inside `CustomerSearch::withoutIndexing()` and index it after the commit with `CustomerSearch::index()`, which reports and swallows a failure. A customer missed during an outage is picked up by the next save or `php artisan scout:import "Lunar\Models\Customer"`. Scout config is untouched.

### VAT number

Optional. Spaces and hyphens are stripped and the value is upper-cased (`App\Domain\Accounts\VatNumber`). Accepted: an optional `GB` or `XI` prefix, then 9 digits, 12 digits, `GD` and 3 digits, or `HA` and 3 digits. Format only: it is not checked against HMRC.

## Customers and staff are separate

| | Customers | Staff |
|---|---|---|
| Table and model | `users`, `App\Models\User` | `lunar_staff`, `Lunar\Admin\Models\Staff` |
| Guard | `web` | `staff` (Lunar injects it at runtime in `LunarPanelProvider`; it is not in `config/auth.php`) |
| Where they sign in | `/login` | `/admin/login` |
| What they can open | storefront, My Account | `/admin` (admin panel) and `/horizon` |

The admin panel is at `/admin` (Lunar's default is `/lunar`; `AppServiceProvider` sets the path, and `/lunar` is a 404). The panel and the Horizon gate both check the `staff` guard, so a customer session is never enough, even when a customer and a staff member share an email address (different tables, never linked). `App\Models\User` is not a Filament user and has no panel access.

Rules that keep it that way:

- Every storefront auth route names its guard: `auth:web`, `guest:web`. Never rely on the default guard.
- The header reads `Auth::guard('web')`, so a staff member signed in to Lunar is a guest on the storefront.
- Both guards share one session store. Customer logout regenerates the session (`session()->regenerate()`) and never calls `invalidate()`, which would also end a staff member's admin session in the same browser. A test pins this.
- The password broker only knows `users`: a staff email cannot be reset from the storefront.

`tests/Feature/Accounts/GuardSeparationTest.php` proves customer to `/admin` and `/horizon`, and staff to My Account.

## Email verification and the env switch

`STORE_REQUIRE_EMAIL_VERIFICATION` (`config/store.php` `require_email_verification`, default `true`; read with `FILTER_VALIDATE_BOOL`, so `false`, `0` and `off` turn it off).

- **On.** The `account.verified` middleware sends a customer whose `email_verified_at` is null to `verification.notice`. A new customer is signed in and sent to `/shop` after registering, with a one-off banner (`data-flash`, in the storefront layout) saying a verification link has been emailed; the storefront and prices work unverified, and only My Account asks for the verified address. Logging in also goes to `/shop`, unless the customer was sent to log in from another page (Laravel's intended URL). The signed link (`verification.verify`, valid 60 minutes) needs the customer to be signed in; a guest who opens it is sent to log in and then back to the link. Resend is limited to 3 per 10 minutes.
- **Off.** No verification email is sent, nothing is gated, and the verify pages redirect to My Account. `email_verified_at` is left null at registration, so switching verification on later asks those customers to verify on their next visit to My Account.
- Changing the email address in My Account needs the current password, clears `email_verified_at` and sends a new link (when verification is on).

The switch is read at request time through `User::verificationRequired()`. `User::sendEmailVerificationNotification()` returns without sending when it is off; the framework's `Registered` event listener calls it, so the switch is honoured in one place.

### Deploy prerequisite (production has no mailer yet)

`app:preflight` has a `mail` check:

- **Fails** when verification is on and `MAIL_MAILER` is `log` or `array`.
- **Warns** (never changes the exit code) when verification is off and the mailer is `log` or `array`: password reset emails will not be delivered. It also warns when a real mailer has an empty or `@example.com` `MAIL_FROM_ADDRESS`.

`.env.production.example` ships `STORE_REQUIRE_EMAIL_VERIFICATION=true` with `MAIL_MAILER=log`, so a fresh production env fails preflight until the operator chooses. Before the first deploy of this release, in `shared/.env`, either set `STORE_REQUIRE_EMAIL_VERIFICATION=false` or configure a real mailer and `MAIL_FROM_ADDRESS`. Locally, `MAIL_MAILER=log` writes the links to `storage/logs/laravel.log`, with the queue worker running (`composer dev`).

## Mail and queues

Both notifications implement `ShouldQueue` and call `onQueue(Queues::DEFAULT)`: they run on the `default` queue (3 Horizon processes, timeout 60), never `images` or `conversions`. The existing test that every queueable class sets its queue from `App\Support\Queues` covers them. They are sent after the registration transaction commits. A mail or queue failure is reported and never fails a registration, a reset request or a resend: the customer can ask for another link.

## Rate limits and abuse controls

| Limiter | Limit | Key |
|---|---|---|
| `login` (route) | 20 a minute | IP |
| login attempts (`LoginRequest`) | 5 failed attempts, then a lockout that says how long to wait | lower-case email and IP |
| `register` | 10 an hour | IP (shared office networks register several people from one address) |
| `password-email` | 5 an hour, and 3 an hour | IP, and email address |
| `password-reset` | 10 an hour | IP |
| `verification-send` | 3 per 10 minutes | customer |

Over a route limit the response is a plain 429. Limits and constants are in `App\Domain\Accounts\RateLimits` and `LoginRequest`.

- **No enumeration on log in or forgot password.** Log in gives one message for an unknown email and a wrong password, and spends the time of a password hash on an unknown email (Laravel's provider skips the hash check when the user is missing). Forgot password gives the same answer for a known address, an unknown one, a throttled broker and a failed mailer. Reset gives one error for a bad token, an expired token and an unknown address. A small timing difference remains on forgot password (a real address writes a token row).
- **Register does reveal** that an email address is already registered ("An account with this email address already exists"). That is a decision: the form must say it, and the route is limited to 10 an hour per IP.
- The session id is regenerated on login, on register, and when the password is changed. Logout regenerates the session and the CSRF token.
- Register has a honeypot field (`website`, off screen, out of the tab order). A filled honeypot creates nothing and answers as if it worked. No captcha.
- Passwords: at least 10 characters with letters and numbers (`Password::defaults()` in `AppServiceProvider`). There is no breach check (`uncompromised()` calls an external API).
- Passwords are never written back into a page.

## Tests

`tests/Feature/Accounts/`: `ApprovalTest` (pending login, activation and its email, switching off, the admin Edit Customer page), `AccountFoundationTest`, `AccountMailQueueTest`, `RegistrationTest`, `LoginTest`, `PasswordResetTest`, `EmailVerificationTest`, `AccountPageTest`, `CustomerLogoTest` (company logo), `InvoiceLayoutTest` (invoice numbers and layout), `QuickOrderTest` (favourites quick order), `GuardSeparationTest`, `HeaderAuthTest`. Mail is faked; nothing is sent. Search tests that need a real index are not involved: the Customer index uses the null Scout driver in tests, and a test installs a failing engine to prove registration survives a Meilisearch outage.

## Known gaps

- **No account deletion and no data export.** UK GDPR gives customers the right to erasure and access. Until this is built, requests have to be handled by hand by staff in the Lunar admin.
- **No HMRC VAT verification.** The VAT number is format-checked only.
- **The company address and industry given at registration** cannot be changed in My Account: the address is in the address book and can be edited there as a delivery address, but the industry has no screen. A customer made any other way (a seeder, or a user with no customer) has neither.
- **Approval is one switch.** There is no rejection state, reason or audit trail, and no email when staff switch a login off. A registration staff do not want is deleted by hand in the admin.
- Other devices stay signed in after a password reset or change (their "keep me logged in" cookies are ended, but live sessions are not). Adding `AuthenticateSession` to the account routes would close it.
- No two-factor authentication for customers.
- Email changes do not notify the old address.
