<?php

declare(strict_types=1);

use App\Admin\Extensions\CustomerLogoResourceExtension;
use App\Domain\Accounts\CustomerLogo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Lunar\Admin\Filament\Resources\CustomerResource\Pages\EditCustomer;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Customer;

// A member's company logo: set by staff in the admin panel (Customers, Edit), shown on My Account
// (docs/accounts.md, Company logo). Stored on the public disk, path in the customer's meta.

beforeEach(function (): void {
    config()->set('store.noindex', true);
    Notification::fake();
    Storage::fake(CustomerLogo::DISK);
});

/** A verified, approved customer with a login and a Lunar customer, as a member has. */
function logoMember(string $company = 'Builder & Sons Ltd', string $email = 'jo@builder.example'): array
{
    $user = User::factory()->create(['email' => $email, 'name' => 'Jo Builder']);
    $customer = Customer::query()->create(['title' => 'Mr', 'first_name' => 'Jo', 'last_name' => 'Builder', 'company_name' => $company]);
    $user->customers()->attach($customer);

    return [$user, $customer];
}

function logoFile(string $name = 'a.png'): string
{
    $path = CustomerLogo::DIRECTORY.'/'.$name;
    Storage::disk(CustomerLogo::DISK)->put($path, 'image-bytes');

    return $path;
}

it('keeps the logo path in the customer meta, next to the industry, and gives its public URL', function (): void {
    [, $customer] = logoMember();
    $customer->meta = ['industry' => 'construction'];
    $customer->save();

    $logo = app(CustomerLogo::class);

    expect($logo->path($customer))->toBeNull()
        ->and($logo->url($customer))->toBeNull();

    $path = logoFile('one.png');
    $logo->set($customer, $path);

    $customer->refresh();

    expect($customer->meta['logo'])->toBe($path)
        ->and($customer->meta['industry'])->toBe('construction')
        ->and($logo->path($customer))->toBe($path)
        ->and($logo->url($customer))->toBe(Storage::disk(CustomerLogo::DISK)->url($path))
        ->and($logo->url(null))->toBeNull();
});

it('deletes the old file when the logo is replaced or removed', function (): void {
    [, $customer] = logoMember();
    $logo = app(CustomerLogo::class);

    $first = logoFile('first.png');
    $second = logoFile('second.png');

    $logo->set($customer, $first);
    $logo->set($customer, $second);

    Storage::disk(CustomerLogo::DISK)->assertMissing($first);
    Storage::disk(CustomerLogo::DISK)->assertExists($second);

    $logo->set($customer, null);

    Storage::disk(CustomerLogo::DISK)->assertMissing($second);
    expect($customer->refresh()->meta)->not->toHaveKey('logo')
        ->and($logo->path($customer))->toBeNull();
});

it('does not delete a file that is still the logo when it is set again', function (): void {
    [, $customer] = logoMember();
    $logo = app(CustomerLogo::class);
    $path = logoFile();

    $logo->set($customer, $path);
    $logo->set($customer, $path);

    Storage::disk(CustomerLogo::DISK)->assertExists($path);
});

it('only accepts a path inside the logo folder, and never deletes a file outside it', function (string $bad): void {
    [, $customer] = logoMember();
    $logo = app(CustomerLogo::class);

    Storage::disk(CustomerLogo::DISK)->put('other/secret.txt', 'keep me');
    Storage::disk(CustomerLogo::DISK)->put('secret.txt', 'keep me');

    $good = logoFile();
    $logo->set($customer, $good);
    $logo->set($customer, $bad);

    // The bad value cleared the logo (it counts as none), and only the logo's own file went.
    expect($customer->refresh()->meta)->not->toHaveKey('logo');
    Storage::disk(CustomerLogo::DISK)->assertExists('other/secret.txt');
    Storage::disk(CustomerLogo::DISK)->assertExists('secret.txt');

    // A bad value already in the meta is ignored on read, and never deleted.
    $customer->meta = ['logo' => $bad];
    $customer->save();

    expect($logo->path($customer->refresh()))->toBeNull();

    $logo->set($customer, null);

    Storage::disk(CustomerLogo::DISK)->assertExists('other/secret.txt');
    Storage::disk(CustomerLogo::DISK)->assertExists('secret.txt');
})->with([
    'outside the folder' => ['other/secret.txt'],
    'at the disk root' => ['secret.txt'],
    'dot dot' => [CustomerLogo::DIRECTORY.'/../secret.txt'],
    'absolute' => ['/etc/passwd'],
    'backslash' => [CustomerLogo::DIRECTORY.'\\..\\secret.txt'],
]);

it('shows no logo when the file has gone missing', function (): void {
    [, $customer] = logoMember();
    $path = logoFile();

    app(CustomerLogo::class)->set($customer, $path);
    Storage::disk(CustomerLogo::DISK)->delete($path);

    expect(app(CustomerLogo::class)->url($customer->refresh()))->toBeNull();
});

/** Every signed-in account page that carries the company heading. */
dataset('accountPages', [
    'my details' => ['account'],
    'orders' => ['account.orders'],
    'invoices' => ['account.invoices'],
    'addresses' => ['account.addresses'],
    'favourites' => ['favourites'],
]);

it('puts the company name and logo in a small heading above the title of every account page', function (string $routeName): void {
    [$user, $customer] = logoMember('Builder & Sons Ltd');
    $path = logoFile();
    app(CustomerLogo::class)->set($customer, $path);

    $html = $this->actingAs($user, 'web')->get(route($routeName))->assertOk()->getContent();

    preg_match('#<div[^>]*data-account-company[^>]*>.*?</div>#s', $html, $block);
    preg_match('#<img[^>]*data-account-logo[^>]*>#', $block[0] ?? '', $img);
    preg_match('#<p[^>]*data-account-company-name[^>]*>(.*?)</p>#s', $block[0] ?? '', $name);

    expect(substr_count($html, 'data-account-company>'))->toBe(1)
        ->and($img[0] ?? '')->toContain('src="'.Storage::disk(CustomerLogo::DISK)->url($path).'"')
        // The name is beside it, so the picture is decorative.
        ->and($img[0] ?? '')->toContain('alt=""')
        ->and($name[1] ?? '')->toBe('Builder &amp; Sons Ltd')
        // Above the page's own h1.
        ->and(strpos($html, 'data-account-company>'))->toBeLessThan(strpos($html, '<h1'));
})->with('accountPages');

it('shows just the company name when there is no logo', function (string $routeName): void {
    [$user] = logoMember('Builder Ltd');

    $html = $this->actingAs($user, 'web')->get(route($routeName))->assertOk()->getContent();

    expect($html)->toContain('data-account-company-name')
        ->and($html)->toContain('Builder Ltd')
        ->and($html)->not->toContain('data-account-logo');
})->with('accountPages');

it('shows just the logo, with alt text, when the company has no name', function (): void {
    [$user, $customer] = logoMember('Builder Ltd');
    $customer->forceFill(['company_name' => null])->save();
    app(CustomerLogo::class)->set($customer, logoFile());

    $html = $this->actingAs($user, 'web')->get(route('account'))->assertOk()->getContent();

    preg_match('#<img[^>]*data-account-logo[^>]*>#', $html, $img);

    expect($img[0] ?? '')->toContain('alt="Company logo"')
        ->and($html)->not->toContain('data-account-company-name');
});

it('shows no company heading when there is neither a name nor a logo', function (): void {
    [$user, $customer] = logoMember('Builder Ltd');
    $customer->forceFill(['company_name' => null])->save();

    $this->actingAs($user, 'web')->get(route('account'))->assertOk()->assertDontSee('data-account-company', false);
});

it('shows a customer only their own company and logo', function (): void {
    [$jo] = logoMember('Builder Ltd', 'jo@builder.example');
    [, $otherCustomer] = logoMember('Other Ltd', 'sam@other.example');
    $otherPath = logoFile('other.png');
    app(CustomerLogo::class)->set($otherCustomer, $otherPath);

    $html = $this->actingAs($jo, 'web')->get(route('account'))->assertOk()->getContent();

    expect($html)->not->toContain('data-account-logo')
        ->and($html)->not->toContain($otherPath)
        ->and($html)->not->toContain('Other Ltd');
});

it('leaves the company heading off the login and register pages', function (string $routeName): void {
    $this->get(route($routeName))->assertOk()->assertDontSee('data-account-company', false);
})->with(['login', 'register']);

it('has no way for a customer to set the logo: the profile form takes no logo', function (): void {
    [$user, $customer] = logoMember();

    $this->actingAs($user, 'web')->put(route('account.profile.update'), [
        'first_name' => 'Jo',
        'last_name' => 'Builder',
        'company_name' => 'Builder Ltd',
        'phone' => '07700 900123',
        'email' => $user->email,
        'logo' => UploadedFile::fake()->image('mine.png'),
        'meta' => ['logo' => logoFile('mine.png')],
    ]);

    expect(app(CustomerLogo::class)->path($customer->refresh()))->toBeNull();
});

// --- The admin panel ---------------------------------------------------------------------

it('lets staff upload a logo on the Edit Customer page, and replace and remove it', function (): void {
    [, $customer] = logoMember();
    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    $logo = app(CustomerLogo::class);

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertFormSet([CustomerLogoResourceExtension::FIELD => null])
        ->fillForm([CustomerLogoResourceExtension::FIELD => UploadedFile::fake()->image('logo.png', 300, 100)])
        ->call('save')
        ->assertHasNoFormErrors();

    $first = $logo->path($customer->refresh());

    expect($first)->not->toBeNull()->and($first)->toStartWith(CustomerLogo::DIRECTORY.'/');
    Storage::disk(CustomerLogo::DISK)->assertExists($first);

    // The page loads it back into the form, and removing it clears the logo and deletes the file.
    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertFormSet([CustomerLogoResourceExtension::FIELD => $first])
        ->fillForm([CustomerLogoResourceExtension::FIELD => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($logo->path($customer->refresh()))->toBeNull();
    Storage::disk(CustomerLogo::DISK)->assertMissing($first);

    // A new one can then be uploaded.
    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm([CustomerLogoResourceExtension::FIELD => UploadedFile::fake()->image('new.jpg', 300, 100)])
        ->call('save')
        ->assertHasNoFormErrors();

    $second = $logo->path($customer->refresh());

    expect($second)->not->toBeNull()->and($second)->not->toBe($first)->and($second)->toEndWith('.jpg');
    Storage::disk(CustomerLogo::DISK)->assertExists($second);
});

it('deletes the old file when a logo is replaced in one step', function (): void {
    // The upload widget replaces a file in one step: the form hands over the new path. (The Livewire test helper
    // cannot show that, because it adds a file beside the old one.)
    [, $customer] = logoMember();
    $logo = app(CustomerLogo::class);

    $old = logoFile('old.png');
    $new = logoFile('new.png');

    $logo->set($customer, $old);
    $logo->set($customer->refresh(), $new);

    expect($logo->path($customer->refresh()))->toBe($new);
    Storage::disk(CustomerLogo::DISK)->assertMissing($old);
    Storage::disk(CustomerLogo::DISK)->assertExists($new);
});

it('refuses a logo that is not a PNG, JPG or WebP', function (string $name, string $mime): void {
    [, $customer] = logoMember();
    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm([CustomerLogoResourceExtension::FIELD => UploadedFile::fake()->create($name, 10, $mime)])
        ->call('save')
        ->assertHasFormErrors([CustomerLogoResourceExtension::FIELD]);

    expect(app(CustomerLogo::class)->path($customer->refresh()))->toBeNull();
})->with([
    'svg' => ['logo.svg', 'image/svg+xml'],
    'pdf' => ['logo.pdf', 'application/pdf'],
    'html' => ['logo.html', 'text/html'],
]);

it('refuses a logo over 2 MB', function (): void {
    [, $customer] = logoMember();
    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm([CustomerLogoResourceExtension::FIELD => UploadedFile::fake()->create('big.png', 2049, 'image/png')])
        ->call('save')
        ->assertHasFormErrors([CustomerLogoResourceExtension::FIELD]);
});

it('leaves the logo alone when staff save a customer that has no login', function (): void {
    // A customer made in the admin has no login, so the logo section is not shown and not saved.
    $customer = Customer::query()->create(['title' => 'Mr', 'first_name' => 'Pat', 'last_name' => 'Walker', 'company_name' => 'Walker Ltd']);
    $path = logoFile();
    app(CustomerLogo::class)->set($customer, $path);

    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm(['company_name' => 'Walker & Co'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->refresh()->company_name)->toBe('Walker & Co')
        ->and(app(CustomerLogo::class)->path($customer))->toBe($path);
    Storage::disk(CustomerLogo::DISK)->assertExists($path);
});

it('keeps the storefront access toggles working beside the logo field', function (): void {
    [$user, $customer] = logoMember();
    $user->forceFill(['approved_at' => null])->save();

    $this->actingAs(Staff::factory()->create(['admin' => true]), 'staff');

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm([
            'storefront_login_active' => true,
            'storefront_account_terms' => true,
            CustomerLogoResourceExtension::FIELD => UploadedFile::fake()->image('logo.png'),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->isApproved())->toBeTrue()
        ->and($user->hasAccountTerms())->toBeTrue()
        ->and(app(CustomerLogo::class)->path($customer->refresh()))->not->toBeNull();
});

it('uses UK English and no em dashes in its copy', function (): void {
    foreach (['app/Admin/Extensions/CustomerLogoResourceExtension.php', 'app/Admin/Extensions/CustomerLogoEditExtension.php', 'app/Domain/Accounts/CustomerLogo.php'] as $file) {
        $text = (string) file_get_contents(base_path($file));

        expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(color|organization|center)\b/i');
    }
});
