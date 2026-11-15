<?php

declare(strict_types=1);

use App\Support\Store;

beforeEach(function (): void {
    config()->set('store.name', 'The Bridge Group');
    config()->set('store.legal_name', 'The Bridge Group Ltd');
    config()->set('store.company_number', '01234567');
    config()->set('store.registered_office', '1 Example Street, London, EC1A 1AA');
    config()->set('store.vat_number', 'GB123456789');
    config()->set('store.phone', 'TBC');
    config()->set('store.email', 'TBC');
});

function footer(string $html): string
{
    preg_match('#<footer.*?</footer>#s', $html, $footer);

    return $footer[0];
}

it('shows the UK trading disclosure from config', function (): void {
    $footer = footer($this->get('/products')->getContent());

    $expected = 'The Bridge Group Ltd, registered in England and Wales, company number 01234567. Registered office: 1 Example Street, London, EC1A 1AA. VAT number GB123456789.';

    expect(Store::disclosure())->toBe($expected)
        ->and($footer)->toContain('data-disclosure>'.$expected.'<')
        ->and($footer)->toContain('&copy; '.date('Y').' The Bridge Group');
});

it('shows TBC placeholders until the details are known', function (): void {
    foreach (['legal_name', 'company_number', 'registered_office', 'vat_number'] as $key) {
        config()->set('store.'.$key, 'TBC');
    }

    expect(footer($this->get('/products')->getContent()))
        ->toContain('TBC, registered in England and Wales, company number TBC. Registered office: TBC. VAT number TBC.');
});

it('reads every identity value from config, with TBC as the default', function (): void {
    // The shipped defaults, whatever this machine's .env says.
    $defaults = (function (): array {
        $file = require config_path('store.php');

        return array_keys($file);
    })();

    expect($defaults)->toBe(['name', 'legal_name', 'company_number', 'registered_office', 'vat_number', 'phone', 'email'])
        ->and(file_get_contents(config_path('store.php')))->not->toContain("'The Bridge Group'");

    $example = (string) file_get_contents(base_path('.env.example'));

    foreach (['STORE_NAME', 'STORE_LEGAL_NAME', 'STORE_COMPANY_NUMBER', 'STORE_REGISTERED_OFFICE', 'STORE_VAT_NUMBER', 'STORE_PHONE', 'STORE_EMAIL'] as $key) {
        expect($example)->toContain($key.'=TBC');
    }
});

it('tells visitors it supplies business customers and that prices exclude VAT', function (): void {
    expect(footer($this->get('/products')->getContent()))
        ->toContain('data-business-notice>We supply business customers. All prices exclude VAT.<');
});

it('has the footer columns with the right links', function (): void {
    $footer = footer($this->get('/products')->getContent());

    $columns = [
        'Customer service' => ['Contact' => 'contact', 'Delivery Information' => 'delivery', 'Returns and Refunds' => 'returns'],
        'Company' => ['About' => 'about'],
        'Legal' => ['Terms and Conditions' => 'terms', 'Privacy Policy' => 'privacy', 'Cookie Policy' => 'cookies', 'Accessibility Statement' => 'accessibility'],
    ];

    foreach ($columns as $heading => $links) {
        preg_match('#<h2[^>]*>'.preg_quote($heading, '#').'</h2>(.*?)</nav>#s', $footer, $column);

        expect($column)->not->toBeEmpty($heading);

        foreach ($links as $label => $route) {
            expect($column[1])->toContain('href="'.route($route).'"')->toContain('>'.$label.'</a>');
        }
    }

    // Products: the top-level categories, the same source as the menu.
    preg_match('#<h2[^>]*>Products</h2>(.*?)</nav>#s', $footer, $products);

    expect($products[1])->toContain('href="'.route('category', 'safety-footwear').'"')
        ->and($products[1])->toContain('href="'.route('category', 'wellingtons').'"')
        // Children belong in the menu, not the footer.
        ->and($products[1])->not->toContain('href="'.route('category', 'safety-boots').'"');
});

it('uses the white logo on the brand-blue footer', function (): void {
    $footer = footer($this->get('/products')->getContent());

    preg_match('#<a href="'.preg_quote(route('home'), '#').'"[^>]*data-footer-logo.*?</a>#s', $footer, $logo);

    expect($footer)->toContain('bg-brand-blue')
        ->and($logo[0])->toContain(asset('images/the-bridge-group-logo-white.svg'))
        ->and($logo[0])->not->toContain('the-bridge-group-logo.svg');
});
