<?php

declare(strict_types=1);

use App\Admin\Resources\HeroContentResource;
use App\Admin\Resources\HeroContentResource\Pages\CreateHeroContent;
use App\Admin\Resources\HeroContentResource\Pages\EditHeroContent;
use App\Admin\Resources\HeroContentResource\Pages\ListHeroContents;
use App\Domain\Catalogue\Home\HeroContents;
use App\Models\HeroContent;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Lunar\Admin\Models\Staff;

// The home hero's words (heading, sub-heading, first button) are sets managed in the admin; one active set is picked at random on every
// page load. The second button is fixed.

beforeEach(function (): void {
    config()->set('store.noindex', true);
});

function heroSet(array $overrides = []): HeroContent
{
    return HeroContent::query()->create([
        'heading' => 'Boots for every site',
        'sub_heading' => 'Certified and ready to ship.',
        'cta_text' => 'See the boots',
        'cta_link' => '/shop/safety-footwear',
        'active' => true,
        'position' => 1,
        ...$overrides,
    ]);
}

function heroHtml(object $test): string
{
    preg_match('#<section[^>]*data-home-hero.*?</section>#s', $test->get(route('home'))->assertOk()->getContent(), $hero);

    return $hero[0] ?? '';
}

function heroAdmin(array $permissions = [], bool $admin = true): Staff
{
    $staff = Staff::factory()->create(['admin' => $admin]);

    foreach ($permissions as $permission) {
        $staff->givePermissionTo($permission);
    }

    test()->actingAs($staff, 'staff');
    Filament::setCurrentPanel(Filament::getPanel('lunar'));

    return $staff;
}

// --- What the home page shows ----------------------------------------------------------------------

it('uses the standard headings, sub-heading and View all products button while no set has been added', function (): void {
    $pick = app(HeroContents::class)->pick();

    expect(config('homepage.hero_headings'))->toContain($pick['heading'])
        ->and($pick['sub_heading'])->toBe(HeroContents::DEFAULT_SUB_HEADING)
        ->and($pick['cta_text'])->toBe('View all products')
        ->and($pick['cta_url'])->toBe(route('shop'));
});

it('shows the heading, sub-heading and first button of an active set', function (): void {
    heroSet();

    $hero = heroHtml($this);

    expect($hero)->toMatch('#<h1[^>]*data-hero-heading[^>]*>Boots for every site</h1>#')
        ->and($hero)->toMatch('#<p[^>]*data-hero-subheading[^>]*>Certified and ready to ship\.</p>#')
        ->and($hero)->toMatch('#<a href="'.preg_quote(url('/shop/safety-footwear'), '#').'"[^>]*data-hero-cta[^>]*>See the boots</a>#');
});

it('picks one set at random on each page load, all from the active ones', function (): void {
    heroSet(['heading' => 'One', 'cta_text' => 'Go one', 'position' => 1]);
    heroSet(['heading' => 'Two', 'cta_text' => 'Go two', 'position' => 2]);
    heroSet(['heading' => 'Three', 'cta_text' => 'Go three', 'position' => 3]);
    heroSet(['heading' => 'Off', 'cta_text' => 'Go off', 'position' => 4, 'active' => false]);

    $seen = [];

    foreach (range(1, 60) as $ignored) {
        $pick = app(HeroContents::class)->pick();

        $seen[$pick['heading']] = $pick['cta_text'];
    }

    // Each heading goes with its own button, never mixed between sets, and a set that is off is never shown.
    expect($seen)->toBe(['One' => 'Go one', 'Two' => 'Go two', 'Three' => 'Go three']);
})->repeat(1);

it('shows nothing under the heading when the set has no sub-heading', function (): void {
    heroSet(['sub_heading' => null]);

    expect(heroHtml($this))->not->toContain('data-hero-subheading')->toContain('Boots for every site');
});

it('falls back to the standard words when every set is switched off', function (): void {
    heroSet(['active' => false]);

    expect(heroHtml($this))->not->toContain('Boots for every site')->toContain('data-hero-heading');
});

it('keeps the second button fixed, whichever set is shown', function (): void {
    heroSet();

    expect(heroHtml($this))->toMatch('#<a href="'.preg_quote(route('register'), '#').'"[^>]*>Open a trade account</a>#');
});

it('escapes the words, so a set can never add markup to the home page', function (): void {
    heroSet(['heading' => 'Tough <b>boots</b>', 'sub_heading' => '<script>alert(1)</script>']);

    $hero = heroHtml($this);

    expect($hero)->toContain('Tough &lt;b&gt;boots&lt;/b&gt;')->not->toContain('<script>alert')->not->toContain('<b>boots');
});

it('turns a path of the site into its full address and keeps an https address', function (): void {
    expect(HeroContents::url('/brands'))->toBe(url('/brands'))->and(HeroContents::url('https://example.test/x'))->toBe('https://example.test/x');
});

it('copies the standard headings into the list once, and not again', function (): void {
    $added = app(HeroContents::class)->importStandard();

    expect($added)->toBe(count(config('homepage.hero_headings')))
        ->and(HeroContent::query()->count())->toBe($added)
        ->and(app(HeroContents::class)->importStandard())->toBe(0);
});

// --- The admin -------------------------------------------------------------------------------------

it('lists the existing sets in the admin', function (): void {
    heroAdmin();

    $one = heroSet(['heading' => 'First heading']);
    $two = heroSet(['heading' => 'Second heading', 'position' => 2]);

    Livewire::test(ListHeroContents::class)->assertCanSeeTableRecords([$one, $two])->assertSee('First heading')->assertSee('See the boots');
});

it('adds a set', function (): void {
    heroAdmin();

    Livewire::test(CreateHeroContent::class)
        ->fillForm(['heading' => 'New heading', 'sub_heading' => 'New sub', 'cta_text' => 'Shop now', 'cta_link' => '/shop', 'active' => true])
        ->call('create')->assertHasNoFormErrors();

    expect(HeroContent::query()->firstOrFail())->heading->toBe('New heading')->cta_link->toBe('/shop');
});

it('needs a heading, button text and a link', function (): void {
    heroAdmin();

    Livewire::test(CreateHeroContent::class)->fillForm(['heading' => '', 'cta_text' => '', 'cta_link' => ''])->call('create')->assertHasFormErrors(['heading', 'cta_text', 'cta_link']);

    expect(HeroContent::query()->count())->toBe(0);
});

it('only takes a path of the shop or an https address as the link', function (string $link): void {
    heroAdmin();

    Livewire::test(CreateHeroContent::class)->fillForm(['heading' => 'H', 'cta_text' => 'Go', 'cta_link' => $link])->call('create')->assertHasFormErrors(['cta_link']);
})->with(['shop', 'http://example.test/x', '//evil.test/x', 'javascript:alert(1)', '/has space']);

it('edits and deletes a set', function (): void {
    heroAdmin();

    $set = heroSet();

    Livewire::test(EditHeroContent::class, ['record' => $set->getKey()])->fillForm(['heading' => 'Changed', 'active' => false])->call('save')->assertHasNoFormErrors();

    expect($set->fresh()->heading)->toBe('Changed')->and($set->fresh()->active)->toBeFalse();

    $set->delete();

    expect(HeroContent::query()->count())->toBe(0);
});

it('adds the standard headings from a button on the list', function (): void {
    heroAdmin();

    Livewire::test(ListHeroContents::class)->callAction('importStandard')->assertNotified();

    expect(HeroContent::query()->count())->toBe(count(config('homepage.hero_headings')));
});

it('is not for a member of staff with no permissions', function (): void {
    heroAdmin(admin: false);

    expect(HeroContentResource::canViewAny())->toBeFalse();

    $this->get(HeroContentResource::getUrl('index'))->assertForbidden();
});

it('is for staff who manage products', function (): void {
    heroAdmin(['catalog:manage-products'], admin: false);

    expect(HeroContentResource::canViewAny())->toBeTrue();

    $this->get(HeroContentResource::getUrl('index'))->assertOk();
});
