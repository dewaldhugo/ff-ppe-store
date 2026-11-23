<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Lunar\Admin\Models\Staff;

// The admin has a top bar: the logo top left, the user menu top right (AppServiceProvider, the panel's topbar()).
// Lunar switches the bar off and puts both in the sidebar.

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

it('turns the admin top bar on', function (): void {
    expect(Filament::getPanel('lunar')->hasTopbar())->toBeTrue();
});

it('puts the logo first and the user menu last in the top bar, with the navigation left in the sidebar', function (): void {
    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    $html = $this->get('/admin')->assertOk()->getContent();

    $bar = strpos($html, 'class="fi-topbar"');
    expect($bar)->not->toBeFalse();

    // Inside the bar, in this order: the logo, then the user menu.
    $logo = strpos($html, 'fi-logo', $bar);
    $menu = strpos($html, 'fi-user-menu', $bar);

    expect($logo)->not->toBeFalse()
        ->and($menu)->not->toBeFalse()
        ->and($logo)->toBeLessThan($menu);

    // The navigation is still in the sidebar: the links to Orders and Invoices are on the page.
    expect($html)->toContain('fi-sidebar')->toContain('Orders')->toContain('Invoices');
});
