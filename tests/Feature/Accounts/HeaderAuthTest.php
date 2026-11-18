<?php

declare(strict_types=1);

use App\Models\User;
use Lunar\Models\CustomerGroup;

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

/**
 * The top bar of the header: the navy strip above the logo.
 */
function topBar(string $html): string
{
    preg_match('#<div class="surface-dark bg-brand-navy.*?</ul>\s*<ul.*?</ul>#s', $html, $bar);

    return $bar[0];
}

/**
 * The price elements of a page, as text.
 *
 * @return list<string>
 */
function pagePrices(string $html): array
{
    preg_match('#<main.*?</main>#s', $html, $main);
    preg_match_all('#<p[^>]*data-price[^>]*>(.*?)</p>#s', $main[0], $prices);

    return array_map(fn (string $price): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($price))), $prices[1]);
}

it('shows guests the Log in and Register links', function (): void {
    $bar = topBar($this->get(route('home'))->getContent());

    expect($bar)->toContain('href="'.route('login').'"')
        ->and($bar)->toContain('href="'.route('register').'"')
        ->and($bar)->not->toContain('data-logout-button')
        ->and($bar)->not->toContain('data-account-link');
});

it('shows a signed-in customer My account and a Log out button, not Log in and Register', function (): void {
    $bar = topBar($this->actingAs(User::factory()->create(), 'web')->get(route('home'))->getContent());

    expect($bar)->toContain('href="'.route('account').'"')
        ->and($bar)->toContain('data-account-link>My account</a>')
        ->and($bar)->toMatch('#<form method="POST" action="'.preg_quote(route('logout'), '#').'">.*?name="_token".*?data-logout-button>Log out</button>#s')
        ->and($bar)->not->toContain('data-login-link')
        ->and($bar)->not->toContain('data-register-link');
});

it('shows a guest no price and a registered customer the list price', function (): void {
    expect(pagePrices($this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent()))->toBeEmpty();

    // Registered the way the site registers people: with the default customer group.
    $this->post(route('register'), [
        'first_name' => 'Jo',
        'last_name' => 'Builder',
        'company_name' => 'Builder Ltd',
        'email' => 'jo@builder.example',
        'password' => 'a-good-password-1',
        'password_confirmation' => 'a-good-password-1',
    ]);
    $this->assertAuthenticated('web');
    expect(User::firstOrFail()->customers->first()->customerGroups->pluck('id')->all())->toBe([CustomerGroup::getDefault()->id]);

    expect(implode(' ', pagePrices($this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent())))
        ->toContain('£68.90 ex VAT');
});

it('shows a customer with no customer group the same list price', function (): void {
    $this->actingAs(User::factory()->create(), 'web');

    expect(implode(' ', pagePrices($this->get(route('product', 'safety-jogger-ligero'))->assertOk()->getContent())))
        ->toContain('£68.90 ex VAT');
});
