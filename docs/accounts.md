# Customer accounts

Read this before touching registration, login, password reset, email verification, My Account, the account routes, or the customer and staff guards.

## Scope

Registration, login, logout ("keep me logged in"), password reset by email, email verification and My Account (view and edit contact and company details, change password). Customers are business buyers: prices stay ex VAT.

Not built: address book, order history, and the items under "Known gaps". The cart and checkout are built (docs/cart.md).

## Prices are for signed-in customers only

Guests see no prices anywhere on the storefront. A signed-in customer (the `web` guard) sees them, ex VAT, as before; any registered customer qualifies, there is no approval workflow. Email verification is not required to see prices.

- One definition: `App\Domain\Pricing\PriceVisibility::visible()`, reached through `PriceDisplay::visible()`. A staff member signed in to Lunar is a guest here.
- Guests get no price and no prompt: the price is simply absent. `PriceDisplay::fromPrice()` returns null for guests, which covers product cards on listings, search, brand pages, the home page and related products; the product page checks `PriceDisplay::visible()`. Any new view that renders a price must do the same.
- The header cart link and its count badge are hidden from guests. `/cart` requires a signed-in customer (a guest is sent to log in). `/checkout` requires a signed-in customer too.
- The product page also hides the quantity field and the add to cart button from guests (`ProductPage::addToCart()` refuses a guest too). Options and stock still show, with one exception: a guest sees every size (`*_size` options) whether it is in stock or not, as plain non-clickable tags (`data-size-readonly`, no strike-through or out of stock label), and `ProductPage::choose()` refuses a size from a guest. Colours stay clickable, because they change the gallery.
- Guests cannot probe prices: the price filter and the two price sorts are hidden, and `price_min`, `price_max` and `sort=price-asc|price-desc` are ignored for a guest (in the URL and in Livewire updates; `CategoryListing::render()` resets them).
- Product structured data (JSON-LD) has no `offers` for guests, so search engines see no prices either. Rich price results will not appear in search until that changes.
- Listings and product pages are not cached per visitor; do not add a shared page or response cache without varying it on sign-in.

Tests: `tests/Feature/Storefront/PriceGatingTest.php` (guests) and the price tests, which sign in first.

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
- Registration also asks for the **company address** and the **industry**, both required (address line 2 and county are optional). The address is saved by `RegisterCustomer` as a Lunar `Address` on the customer (`$customer->addresses()`), as the billing default, with the company name, the contact email and phone, and the UK country (`GB`, from the seeded Lunar country; the store sells to UK customers only, so the form has no country field). The postcode is format-checked and stored upper case with one space (`App\Domain\Accounts\Postcode`). The industry is a slug from `App\Domain\Accounts\Industries`, stored in the customer's `meta` as `industry`. My Account does not show or edit either yet (see Known gaps).

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
| Where they sign in | `/login` | `/lunar/login` |
| What they can open | storefront, My Account | `/lunar` (admin panel) and `/horizon` |

The admin panel is at `/lunar`, not `/admin`. The panel and the Horizon gate both check the `staff` guard, so a customer session is never enough, even when a customer and a staff member share an email address (different tables, never linked). `App\Models\User` is not a Filament user and has no panel access.

Rules that keep it that way:

- Every storefront auth route names its guard: `auth:web`, `guest:web`. Never rely on the default guard.
- The header reads `Auth::guard('web')`, so a staff member signed in to Lunar is a guest on the storefront.
- Both guards share one session store. Customer logout regenerates the session (`session()->regenerate()`) and never calls `invalidate()`, which would also end a staff member's admin session in the same browser. A test pins this.
- The password broker only knows `users`: a staff email cannot be reset from the storefront.

`tests/Feature/Accounts/GuardSeparationTest.php` proves customer to `/lunar` and `/horizon`, and staff to My Account.

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

`tests/Feature/Accounts/`: `AccountFoundationTest`, `AccountMailQueueTest`, `RegistrationTest`, `LoginTest`, `PasswordResetTest`, `EmailVerificationTest`, `AccountPageTest`, `GuardSeparationTest`, `HeaderAuthTest`. Mail is faked; nothing is sent. Search tests that need a real index are not involved: the Customer index uses the null Scout driver in tests, and a test installs a failing engine to prove registration survives a Meilisearch outage.

## Known gaps

- **No account deletion and no data export.** UK GDPR gives customers the right to erasure and access. Until this is built, requests have to be handled by hand by staff in the Lunar admin.
- **No HMRC VAT verification.** The VAT number is format-checked only.
- **No address book.** My Account holds contact and company details only. The company address and industry given at registration are saved but cannot be seen or changed in My Account, and a customer made any other way (a seeder, or a user with no customer) has neither.
- **No approval workflow.** Anyone can register and sign in.
- **No order history.** Customers can place an order (docs/cart.md) and see its confirmation page once, but My Account lists no orders.
- Other devices stay signed in after a password reset or change (their "keep me logged in" cookies are ended, but live sessions are not). Adding `AuthenticateSession` to the account routes would close it.
- No two-factor authentication for customers.
- Email changes do not notify the old address.
