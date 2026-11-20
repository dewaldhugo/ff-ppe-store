<?php

declare(strict_types=1);

use App\Support\Store;

beforeEach(function (): void {
    config()->set('store.name', 'Frontline Safety Gear');
    config()->set('store.legal_name', 'Frontline Holdings Ltd');
    config()->set('store.company_number', '13184904');
    config()->set('store.registered_office', '1 Example Street, London, EC1A 1AA');
    config()->set('store.address', 'TBC');
    config()->set('store.vat_number', 'GB123456789');
    config()->set('store.phone', 'TBC');
    config()->set('store.email', 'TBC');
});

function footer(string $html): string
{
    preg_match('#<footer.*?</footer>#s', $html, $footer);

    return $footer[0];
}

it('has only the copyright line in the lower footer, with no TBC placeholders', function (): void {
    foreach (['legal_name', 'company_number', 'registered_office', 'vat_number'] as $key) {
        config()->set('store.'.$key, 'TBC');
    }

    $footer = footer($this->get(route('shop'))->getContent());

    preg_match('#<div class="border-t border-white/20">(.*?)</div>#s', $footer, $lower);

    expect(trim(html_entity_decode(strip_tags($lower[1]))))->toBe('© '.date('Y').' Frontline Safety Gear')
        ->and(substr_count($lower[1], '<p'))->toBe(1)
        ->and($lower[1])->toContain('data-copyright')
        ->and($lower[1])->not->toContain('TBC')
        ->and($footer)->not->toContain('data-disclosure')
        ->and($footer)->not->toContain('data-business-notice')
        ->and($footer)->not->toContain('registered in England and Wales');
});

it('reads every identity value from config, with TBC as the default', function (): void {
    // The shipped defaults, whatever this machine's .env says.
    $defaults = (function (): array {
        $file = require config_path('store.php');

        return array_keys($file);
    })();

    expect($defaults)->toBe(['name', 'legal_name', 'company_number', 'registered_office', 'address', 'vat_number', 'phone', 'email', 'enquiry_email', 'delivery', 'noindex', 'require_email_verification'])
        ->and(file_get_contents(config_path('store.php')))->not->toContain("'Frontline Safety Gear'");

    $example = (string) file_get_contents(base_path('.env.example'));

    foreach (['STORE_NAME', 'STORE_LEGAL_NAME', 'STORE_COMPANY_NUMBER', 'STORE_REGISTERED_OFFICE', 'STORE_VAT_NUMBER'] as $key) {
        expect($example)->toContain($key.'=TBC');
    }
});

it('has the footer columns with the right links', function (): void {
    $footer = footer($this->get(route('shop'))->getContent());

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
        ->and($products[1])->toContain('href="'.route('category', 'non-safety-footwear').'"')
        // Children belong in the menu, not the footer.
        ->and($products[1])->not->toContain('href="'.route('category', 'safety-footwear/safety-boots').'"')
        ->and($products[1])->not->toContain('href="'.route('category', 'non-safety-footwear/wellingtons').'"');
});

it('uses the white logo on the brand-blue footer', function (): void {
    $footer = footer($this->get(route('shop'))->getContent());

    preg_match('#<a href="'.preg_quote(route('home'), '#').'"[^>]*data-footer-logo.*?</a>#s', $footer, $logo);

    expect($footer)->toContain('bg-brand-blue')
        ->and($logo[0])->toContain(asset('images/frontline-logo-white.png'))
        ->and($logo[0])->not->toContain('frontline-logo.png');
});

it('shows the customer address in the footer', function (): void {
    config()->set('store.address', '7 Lynnwood Court, Priestlands Place, Lymington, Hampshire, SO41 9GA');

    $footer = footer($this->get(route('shop'))->getContent());

    preg_match('#<address[^>]*>(.*?)</address>#s', $footer, $address);

    expect($address[1])->toBe('7 Lynnwood Court, Priestlands Place, Lymington, Hampshire, SO41 9GA')
        ->and(Store::contactAddress())->toBe('7 Lynnwood Court, Priestlands Place, Lymington, Hampshire, SO41 9GA')
        ->and($footer)->not->toContain('Registered office');
});

it('fixes the address, phone and email in config/store.php, with no environment variable', function (): void {
    $file = require config_path('store.php');
    $source = (string) file_get_contents(config_path('store.php'));

    expect($file['address'])->toBe('7 Lynnwood Court, Priestlands Place, Lymington, Hampshire, SO41 9GA')
        ->and($file['phone'])->toBe('TBC')
        ->and($file['email'])->toBe('info@frontlinesafetygear.com')
        ->and($file['enquiry_email'])->toBe(env('STORE_ENQUIRY_EMAIL') ?: 'info@frontlinesafetygear.com')
        ->and($source)->not->toContain('STORE_ADDRESS')
        ->and($source)->not->toContain('STORE_PHONE')
        ->and($source)->not->toContain("env('STORE_EMAIL'");

    foreach (['.env.example', '.env.production.example'] as $example) {
        $text = (string) file_get_contents(base_path($example));

        expect($text)->not->toContain('STORE_ADDRESS=')->not->toContain('STORE_PHONE=')->not->toContain('STORE_EMAIL=');
    }
});
