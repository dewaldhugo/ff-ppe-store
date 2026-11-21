<?php

declare(strict_types=1);

use App\Domain\Cart\Delivery\DeliveryPolicy;
use App\Support\Store;

// The six policy pages (terms, privacy, cookies, accessibility, delivery, returns) have real content, written from how the
// store works. These tests keep the copy tied to the shop: the company details and the delivery rule come
// from the same place the shop reads them, the cookie list is checked against the session config, and the
// accessibility claims against the layout and the stylesheet. The wording itself has not had legal review.

const NOINDEX_TAG = '<meta name="robots" content="noindex">';

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

dataset('policy pages', [
    'terms' => ['terms', '/terms', 'Terms and Conditions of Sale'],
    'privacy' => ['privacy', '/privacy', 'Privacy Policy'],
    'cookies' => ['cookies', '/cookies', 'Cookie Policy'],
    'accessibility' => ['accessibility', '/accessibility', 'Accessibility Statement'],
    'delivery' => ['delivery', '/delivery', 'Delivery Information'],
    'returns' => ['returns', '/returns', 'Returns and Refunds'],
]);

/** The words of a policy page: its own body only, not the header, footer or scripts around it. */
function legalText(mixed $test, string $name): string
{
    $html = (string) $test->get(route($name))->assertOk()->getContent();
    $start = strpos($html, 'data-legal-body');
    // The body ends where the sidebar starts.
    $end = strpos($html, 'data-legal-nav') ?: strpos($html, '</article>');

    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return html_entity_decode(strip_tags(substr($html, (int) $start, (int) $end - (int) $start)), ENT_QUOTES);
}

// --- Every page ------------------------------------------------------------------------------

it('renders the page with real content, one h1, the date and the standard layout', function (string $name, string $uri, string $heading): void {
    $response = $this->get($uri)->assertOk();
    $html = $response->getContent();

    $response->assertSee('<title>'.$heading.' | ', false)
        ->assertSee($heading)
        ->assertSee('Last updated: 21 November 2026')
        ->assertSee('data-legal-body', false)
        ->assertSee('<footer', false)
        ->assertSee('<nav aria-label="Main"', false)
        ->assertDontSee('Content coming soon.');

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(substr_count($html, '<h2'))->toBeGreaterThanOrEqual(5);
})->with('policy pages');

it('is open to guests and is linked from the footer', function (string $name, string $uri, string $heading): void {
    $footer = $this->get(route('home'))->assertOk()->getContent();

    expect($footer)->toContain('href="'.route($name).'"');
    $this->get(route($name))->assertOk();
})->with('policy pages');

it('is kept out of search engines only by STORE_NOINDEX, with no noindex of its own', function (string $name): void {
    $this->get(route($name))->assertOk()->assertSee(NOINDEX_TAG, false);

    config()->set('store.noindex', false);
    $this->get(route($name))->assertOk()->assertDontSee(NOINDEX_TAG, false);
})->with(['terms', 'privacy', 'cookies', 'accessibility', 'delivery', 'returns']);

it('has a description for search results', function (string $name): void {
    $this->get(route($name))->assertOk()->assertSee('<meta name="description" content="', false);
})->with(['terms', 'privacy', 'cookies', 'accessibility', 'delivery', 'returns']);

// --- Terms ------------------------------------------------------------------------------------

it('names the company from the store config, never from the copy', function (): void {
    $html = $this->get(route('terms'))->assertOk()->getContent();

    foreach (['name', 'company_number', 'vat_number'] as $key) {
        expect(Store::invoice($key))->not->toBe('')
            ->and($html)->toContain(e(Store::invoice($key)));
    }

    expect($html)->toContain(e(Store::invoice('address')))
        ->and($html)->toContain(e(Store::email()))
        ->and($html)->toContain(e(Store::phone()));

    // Change the config and the page follows.
    config()->set('store.invoice.company_number', '99999999');
    $this->get(route('terms'))->assertOk()->assertSee('99999999');
});

it('states the delivery rule the checkout charges, in the shop\'s own words', function (): void {
    $notice = app(DeliveryPolicy::class)->notice();

    $this->get(route('terms'))->assertOk()->assertSee($notice);

    config()->set('store.delivery', ['free_over' => 75_000, 'charge' => 4_500]);
    $this->get(route('terms'))->assertOk()->assertSee('Orders over £750.00 ex VAT get free delivery. Otherwise delivery is £45.00 ex VAT.');
});

it('covers prices ex VAT, 30 day accounts, card payment, returns and the governing law', function (): void {
    $text = legalText($this, 'terms');

    expect($text)->toContain('excluding VAT')
        ->toContain('Stripe')
        ->toContain('30 day account')
        ->toContain('within 30 days of the invoice date')
        ->toContain('business')
        ->toContain('We deliver to addresses in the United Kingdom')
        ->toContain('law of England and Wales');
});

it('links to the other policy pages with route(), so a moved page cannot break them', function (): void {
    $html = (string) $this->get(route('terms'))->assertOk()->getContent();

    foreach (['returns', 'delivery', 'privacy', 'cookies', 'contact', 'size-guide', 'technical-features'] as $name) {
        expect($html)->toContain('href="'.route($name).'"');
    }
});

// --- Privacy ----------------------------------------------------------------------------------

it('lists what registration, checkout and the contact form really collect', function (): void {
    $text = legalText($this, 'privacy');

    foreach (['company name', 'industry', 'VAT number', 'phone number', 'email address', 'password', 'purchase order', 'delivery instructions', 'Stripe', 'suppliers'] as $item) {
        expect($text)->toContain($item);
    }

    // The fields the sign-up form asks for are the ones the policy says it collects.
    $form = (string) file_get_contents(resource_path('views/auth/register.blade.php'));
    foreach (['company_name', 'industry', 'vat_number', 'phone', 'email', 'password'] as $field) {
        expect($form)->toContain('name="'.$field.'"');
    }
});

it('names the company as controller and gives a way to ask about personal information', function (): void {
    $html = (string) $this->get(route('privacy'))->assertOk()->getContent();

    expect($html)->toContain(e(Store::invoice('name')))
        ->toContain(e(Store::invoice('company_number')))
        ->toContain('controller')
        ->toContain('mailto:'.e(Store::email()));
});

it('sets out the rights under UK data protection law and the right to complain to the ICO', function (): void {
    $text = legalText($this, 'privacy');

    foreach (['a copy of the information', 'correct', 'delete', 'limit', 'move', 'object', 'Information Commissioner', 'ico.org.uk', 'within one month'] as $item) {
        expect($text)->toContain($item);
    }
});

it('does not promise marketing emails or name an ICO registration number it was never given', function (): void {
    $text = legalText($this, 'privacy');

    expect($text)->toContain('We do not currently send marketing emails')
        ->and($text)->not->toMatch('/\bZ\d{7}\b/')
        ->and($text)->not->toContain('TBC');
});

// --- Cookies ----------------------------------------------------------------------------------

it('lists the cookies the site sets and says it uses no analytics or advertising cookies', function (): void {
    $text = legalText($this, 'cookies');

    expect($text)->toContain('Session cookie')
        ->toContain('XSRF-TOKEN')
        ->toContain('Remember me')
        ->toContain('Stripe')
        ->toContain('We do not use cookies for analytics, advertising or social media')
        ->toContain('strictly necessary');
});

it('is true to the site: the session lasts the two hours it says, and no analytics or advertising script is loaded', function (): void {
    // The copy says the session ends after two hours of inactivity. If the lifetime changes, change the copy.
    expect(config('session.lifetime'))->toBe(120);

    foreach (['home', 'shop', 'about', 'contact', 'login', 'register'] as $name) {
        $html = (string) $this->get(route($name))->assertOk()->getContent();

        foreach (['googletagmanager', 'google-analytics', 'gtag(', 'fbevents', 'hotjar', 'clarity.ms', 'doubleclick'] as $tracker) {
            expect($html)->not->toContain($tracker);
        }
    }
});

it('has no cookie banner, because it sets only strictly necessary cookies', function (): void {
    $this->get(route('home'))->assertOk()->assertDontSee('Accept all cookies')->assertDontSee('data-cookie-banner', false);
});

// --- Accessibility ----------------------------------------------------------------------------

it('claims only what the site does: the skip link is on every page and keyboard focus is styled', function (): void {
    $text = legalText($this, 'accessibility');

    expect($text)->toContain('Skip to main content')
        ->toContain('WCAG')
        ->toContain('2.2')
        ->toContain('We have not yet carried out a full independent accessibility audit')
        ->not->toContain('fully compliant');

    $this->get(route('home'))->assertOk()->assertSee('Skip to main content');
    expect((string) file_get_contents(resource_path('css/app.css')))->toContain(':focus-visible');
});

it('tells people how to get help and where to take a complaint', function (): void {
    $html = (string) $this->get(route('accessibility'))->assertOk()->getContent();

    expect($html)->toContain('mailto:'.e(Store::email()))
        ->toContain('href="'.route('contact').'"')
        ->toContain('Equality Advisory and Support Service')
        ->toContain('equalityadvisoryservice.com');
});

// --- The sidebar ------------------------------------------------------------------------------

it('has a sidebar with a Legal group and a help group, in order, linking to every policy page by route', function (string $name): void {
    $html = (string) $this->get(route($name))->assertOk()->getContent();

    preg_match_all('#data-legal-nav-heading>([^<]+)#', $html, $headings);
    preg_match_all('#href="([^"]+)"[^>]*data-legal-nav-link="([a-z]+)"[^>]*>([^<]+)</a>#s', $html, $links, PREG_SET_ORDER);

    expect(array_map('trim', $headings[1]))->toBe(['Legal', 'Help with your order'])
        ->and(array_map(fn (array $link): string => $link[2], $links))->toBe(['terms', 'privacy', 'cookies', 'accessibility', 'delivery', 'returns'])
        ->and(array_map(fn (array $link): string => trim($link[3]), $links))->toBe(['Terms and Conditions', 'Privacy Policy', 'Cookie Policy', 'Accessibility Statement', 'Delivery Information', 'Returns and Refunds'])
        ->and(array_map(fn (array $link): string => $link[1], $links))->toBe(array_map(fn (string $page): string => route($page), ['terms', 'privacy', 'cookies', 'accessibility', 'delivery', 'returns']));
})->with(['terms', 'privacy', 'cookies', 'accessibility', 'delivery', 'returns']);

it('marks the page you are on, and only that page', function (string $name): void {
    $html = (string) $this->get(route($name))->assertOk()->getContent();

    expect(substr_count($html, 'aria-current="page"'))->toBe(1)
        ->and($html)->toMatch('#aria-current="page"\s*data-legal-nav-link="'.$name.'"#');
})->with(['terms', 'privacy', 'cookies', 'accessibility', 'delivery', 'returns']);

it('hides the sidebar below the large breakpoint, so a phone gets the text alone', function (): void {
    $html = (string) $this->get(route('privacy'))->assertOk()->getContent();

    preg_match('#<aside[^>]*data-legal-nav[^>]*>#', $html, $aside);

    expect($aside[0])->toContain('hidden')->toContain('lg:block')->not->toMatch('#(?<![:\w-])(block|flex|grid)\b#')
        // Two columns only from the large breakpoint, the text taking all the width the sidebar leaves.
        ->and($html)->toContain('lg:flex lg:items-start')->toContain('legal-prose min-w-0 lg:flex-1');
});

it('keeps the sidebar out of the words of the page, and off every other page', function (): void {
    expect(legalText($this, 'terms'))->not->toContain('Help with your order');

    foreach (['home', 'about', 'contact', 'size-guide'] as $name) {
        $this->get(route($name))->assertOk()->assertDontSee('data-legal-nav', false);
    }
});

it('has footer links to the same six pages, which is what a phone uses', function (): void {
    $home = (string) $this->get(route('home'))->assertOk()->getContent();

    foreach (['terms', 'privacy', 'cookies', 'accessibility', 'delivery', 'returns'] as $name) {
        expect($home)->toContain('href="'.route($name).'"');
    }
});

// --- Delivery ---------------------------------------------------------------------------------

it('states the charge and the free delivery level from the delivery rule, and follows a change', function (): void {
    $policy = app(DeliveryPolicy::class);
    $text = legalText($this, 'delivery');

    expect($text)->toContain('Orders over '.$policy->freeOverFormatted().' ex VAT get free delivery.')
        ->toContain('delivery is '.$policy->chargeFormatted().' ex VAT')
        ->toContain('An order of exactly '.$policy->freeOverFormatted().' does not qualify');

    config()->set('store.delivery', ['free_over' => 75_000, 'charge' => 4_500]);
    $changed = legalText($this, 'delivery');

    expect($changed)->toContain('Orders over £750.00 ex VAT get free delivery.')
        ->toContain('delivery is £45.00 ex VAT')
        ->not->toContain('£500.00');
});

it('says the same as the terms about where it delivers, and explains split deliveries', function (): void {
    $text = legalText($this, 'delivery');

    expect($text)->toContain('We deliver to addresses in the United Kingdom')
        ->toContain('may arrive in more than one delivery')
        ->toContain('once for each order')
        ->and(legalText($this, 'terms'))->toContain('We deliver to addresses in the United Kingdom');
});

it('promises no dispatch or delivery time, because none has been confirmed', function (): void {
    $text = legalText($this, 'delivery');

    expect($text)->not->toMatch('/\b(next[- ]day|same[- ]day|24 hours|48 hours|within \d+ (?:working )?days of (?:ordering|your order))\b/i')
        ->and($text)->toContain('contact us before you order');
});

it('takes the report window from the returns config', function (): void {
    expect(legalText($this, 'delivery'))->toContain('within 3 working days of delivery');

    config()->set('store.returns.report_days', 5);
    expect(legalText($this, 'delivery'))->toContain('within 5 working days of delivery');
});

it('links to the orders list in the account, by route', function (string $page): void {
    $html = (string) $this->get(route($page))->assertOk()->getContent();

    expect($html)->toContain('href="'.route('account.orders').'"');
})->with(['delivery', 'returns']);

// --- Returns ----------------------------------------------------------------------------------

it('takes the three return periods from the returns config, and follows a change', function (): void {
    $text = legalText($this, 'returns');

    expect($text)->toContain('within 3 working days of delivery')
        ->toContain('within 30 days of delivery')
        ->toContain('within 14 days of accepting it');

    config()->set('store.returns', ['window_days' => 45, 'report_days' => 7, 'refund_days' => 10]);
    $changed = legalText($this, 'returns');

    expect($changed)->toContain('within 7 working days of delivery')
        ->toContain('within 45 days of delivery')
        ->toContain('within 10 days of accepting it');
});

it('keeps the returns periods in the config, with the other policy numbers', function (): void {
    expect(config('store.returns'))->toBe(['window_days' => 30, 'report_days' => 3, 'refund_days' => 14]);

    foreach (['views/pages/legal/returns.blade.php', 'views/pages/legal/delivery.blade.php'] as $view) {
        // No period is typed into the copy: 30, 3 and 14 only ever come from the config.
        expect((string) file_get_contents(resource_path($view)))->not->toMatch('/\b(30|14) days\b/');
    }
});

it('does not promise what the store cannot do: no free returns for change of mind, no collection, no delivery refund', function (): void {
    $text = legalText($this, 'returns');

    expect($text)->toContain('You pay to send these goods back')
        ->toContain('We do not refund the delivery charge')
        ->not->toMatch('/free returns|we will collect|collection service/i');
});

it('explains the business to business position without taking away legal rights', function (): void {
    $text = legalText($this, 'returns');

    expect($text)->toContain('We sell to businesses')
        ->toContain('14 day right to cancel')
        ->toContain('does not affect any legal right you have if goods are faulty');
});

it('covers faulty goods, hygiene items, exchanges, how to return, refunds for card and account orders, and cancelling', function (): void {
    $text = legalText($this, 'returns');

    foreach ([
        'Faulty.', 'earplugs, respirators and face masks', 'Exchanges', 'Please do not send goods back without our instructions',
        'Orders paid by card', 'Orders on a 30 day account', 'Cancelling an order',
    ] as $item) {
        expect($text)->toContain($item);
    }
});

it('is linked to and from the terms and the delivery page', function (): void {
    expect((string) $this->get(route('terms'))->assertOk()->getContent())
        ->toContain('href="'.route('returns').'"')
        ->toContain('href="'.route('delivery').'"');

    expect((string) $this->get(route('returns'))->assertOk()->getContent())
        ->toContain('href="'.route('delivery').'"')
        ->toContain('href="'.route('terms').'"');
});

// --- Copy rules -------------------------------------------------------------------------------

it('uses UK English, no em dashes and no TBC in any policy page', function (): void {
    foreach (['terms', 'privacy', 'cookies', 'accessibility', 'delivery', 'returns'] as $page) {
        $source = (string) file_get_contents(resource_path('views/pages/legal/'.$page.'.blade.php'));
        $text = legalText($this, $page);

        foreach ([$source, $text] as $copy) {
            expect($copy)->not->toContain('—')
                ->and($copy)->not->toMatch('/\b(color|colors|organization|organize|center|license|favorite|analyze)\b/i');
        }

        expect($text)->not->toContain('TBC');
    }

    expect((string) file_get_contents(resource_path('views/components/storefront/legal-page.blade.php')))->not->toContain('—');
});

it('never writes a colour or a size into the pages: they use the design tokens', function (): void {
    $component = (string) file_get_contents(resource_path('views/components/storefront/legal-page.blade.php'));

    expect($component)->not->toMatch('/#[0-9a-fA-F]{3,8}\b/')
        ->and($component)->not->toMatch('/\b(?:rgb|hsl)a?\(/')
        ->and($component)->toContain('var(--color-ink)');
});
