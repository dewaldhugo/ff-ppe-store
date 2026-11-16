<?php

declare(strict_types=1);

use App\Domain\Catalogue\Urls\CollectionPath;
use App\Domain\Catalogue\Urls\Redirects\RedirectOutcome;
use App\Domain\Catalogue\Urls\Redirects\RedirectRecorder;
use App\Domain\Catalogue\Urls\Redirects\RedirectResolver;
use App\Models\CollectionPathSnapshot;
use App\Models\UrlRedirect;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Collection;
use Lunar\Models\CollectionGroup;
use Lunar\Models\Language;
use Lunar\Models\Product;
use Lunar\Models\Url;

function redirectCategory(string $slug, ?Collection $parent = null, string $group = 'categories'): Collection
{
    $collection = Collection::create([
        'collection_group_id' => CollectionGroup::query()->where('handle', $group)->sole()->id,
        'attribute_data' => ['name' => new TranslatedText(['en' => new Text(ucfirst($slug))])],
    ], $parent);

    $collection->urls()->create(['slug' => $slug, 'default' => true, 'language_id' => Language::getDefault()->id]);

    return $collection;
}

function redirectMap(): array
{
    return UrlRedirect::query()->pluck('new_path', 'old_path')->all();
}

function livePath(Collection $collection): ?string
{
    $paths = app(CollectionPath::class);
    $paths->flush();

    return $paths->for($collection);
}

it('builds a category path from its ancestors, under the one shop prefix', function (): void {
    $parent = redirectCategory('rt-parent');
    $child = redirectCategory('rt-child', $parent);

    expect(livePath($parent))->toBe(CollectionPath::index().'/rt-parent')
        ->and(livePath($child))->toBe(CollectionPath::index().'/rt-parent/rt-child')
        ->and(CollectionPath::index())->toBe('/shop');
});

it('gives a collection outside the categories group no path', function (): void {
    expect(livePath(redirectCategory('rt-range', group: 'ranges')))->toBeNull();
});

it('keeps a snapshot of a new category and records no redirect for it', function (): void {
    $category = redirectCategory('rt-new');

    expect(CollectionPathSnapshot::query()->where('collection_id', $category->id)->value('path'))->toBe('/shop/rt-new')
        ->and(redirectMap())->toBe([]);
});

it('redirects the old path when a category is renamed', function (): void {
    $category = redirectCategory('rt-before');

    $category->defaultUrl->update(['slug' => 'rt-after']);

    expect(redirectMap())->toBe(['/shop/rt-before' => '/shop/rt-after'])
        ->and(CollectionPathSnapshot::query()->where('collection_id', $category->id)->value('path'))->toBe('/shop/rt-after');
});

it('redirects the old path when a category moves to another parent', function (): void {
    $from = redirectCategory('rt-from');
    $to = redirectCategory('rt-to');
    $child = redirectCategory('rt-moving', $from);

    $child->appendToNode($to)->save();

    expect(redirectMap())->toBe(['/shop/rt-from/rt-moving' => '/shop/rt-to/rt-moving']);
});

it('redirects every child when a parent moves, though the children are not saved', function (): void {
    $parent = redirectCategory('rt-parent');
    $first = redirectCategory('rt-first', $parent);
    $second = redirectCategory('rt-second', $parent);
    $grandchild = redirectCategory('rt-grandchild', $first);
    $newHome = redirectCategory('rt-home');

    $parent->appendToNode($newHome)->save();

    expect(redirectMap())->toBe([
        '/shop/rt-parent' => '/shop/rt-home/rt-parent',
        '/shop/rt-parent/rt-first' => '/shop/rt-home/rt-parent/rt-first',
        '/shop/rt-parent/rt-second' => '/shop/rt-home/rt-parent/rt-second',
        '/shop/rt-parent/rt-first/rt-grandchild' => '/shop/rt-home/rt-parent/rt-first/rt-grandchild',
    ])->and(livePath($second))->toBe('/shop/rt-home/rt-parent/rt-second')
        ->and(livePath($grandchild))->toBe('/shop/rt-home/rt-parent/rt-first/rt-grandchild');
});

it('redirects the children of a renamed parent', function (): void {
    $parent = redirectCategory('rt-old-parent');
    redirectCategory('rt-kid', $parent);

    $parent->defaultUrl->update(['slug' => 'rt-new-parent']);

    expect(redirectMap())->toBe([
        '/shop/rt-old-parent' => '/shop/rt-new-parent',
        '/shop/rt-old-parent/rt-kid' => '/shop/rt-new-parent/rt-kid',
    ]);
});

it('collapses a chain, so A to B to C resolves A straight to C', function (): void {
    $category = redirectCategory('rt-a');

    $category->defaultUrl->update(['slug' => 'rt-b']);
    $category->defaultUrl->update(['slug' => 'rt-c']);

    expect(redirectMap())->toBe([
        '/shop/rt-a' => '/shop/rt-c',
        '/shop/rt-b' => '/shop/rt-c',
    ])->and(app(RedirectResolver::class)->resolve('/shop/rt-a'))->toBe('/shop/rt-c');
});

it('drops a redirect when its path becomes live again', function (): void {
    $category = redirectCategory('rt-x');

    $category->defaultUrl->update(['slug' => 'rt-y']);
    $category->defaultUrl->update(['slug' => 'rt-x']);

    expect(redirectMap())->toBe(['/shop/rt-y' => '/shop/rt-x']);
});

it('removes redirects that lead to a deleted category', function (): void {
    $category = redirectCategory('rt-gone-before');
    $category->defaultUrl->update(['slug' => 'rt-gone']);

    expect(redirectMap())->not->toBe([]);

    $category->delete();

    expect(redirectMap())->toBe([])
        ->and(CollectionPathSnapshot::query()->where('collection_id', $category->id)->exists())->toBeFalse();
});

it('stores each old path once', function (): void {
    UrlRedirect::query()->create(['old_path' => '/shop/dupe', 'new_path' => '/shop/one']);

    expect(fn () => UrlRedirect::query()->create(['old_path' => '/shop/dupe', 'new_path' => '/shop/two']))
        ->toThrow(QueryException::class);
});

it('compares redirect paths exactly, not through the collation', function (): void {
    UrlRedirect::query()->create(['old_path' => '/shop/lower', 'new_path' => '/shop/target']);

    expect(app(RedirectResolver::class)->resolve('/shop/lower'))->toBe('/shop/target')
        ->and(app(RedirectResolver::class)->resolve('/shop/LOWER'))->toBeNull();
});

it('does not follow a damaged table round a loop', function (): void {
    UrlRedirect::query()->create(['old_path' => '/shop/a', 'new_path' => '/shop/b']);
    UrlRedirect::query()->create(['old_path' => '/shop/b', 'new_path' => '/shop/a']);

    expect(app(RedirectResolver::class)->resolve('/shop/a'))->toBeNull();
});

it('treats a redirect from a path to itself as nothing to do', function (): void {
    expect(app(RedirectRecorder::class)->record('/shop/same', '/shop/same'))->toBe(RedirectOutcome::Unchanged)
        ->and(redirectMap())->toBe([]);
});

it('runs no category query when a product url is saved', function (): void {
    $url = Url::query()->where('element_type', Product::morphName())->firstOrFail();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $url->update(['slug' => $url->slug.'-edited']);

    $related = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'collection')
        || str_contains($sql, 'url_redirects'));

    expect($related)->toBe([])
        ->and($queries)->toHaveCount(2); // Lunar's own update and default-url check only
});

it('runs no category query when a collection outside the categories group is saved', function (): void {
    $range = redirectCategory('rt-spring', group: 'ranges');

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $range->defaultUrl->update(['slug' => 'rt-summer']);

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'url_redirects') || str_contains($sql, 'collection_path_snapshots')))->toBe([])
        ->and(redirectMap())->toBe([]);
});

describe('catalogue:sync-redirects', function (): void {
    it('catches a change made without model events', function (): void {
        $category = redirectCategory('rt-raw-before');

        DB::table((new Url)->getTable())->where('id', $category->defaultUrl->id)->update(['slug' => 'rt-raw-after']);

        expect(redirectMap())->toBe([]);

        $this->artisan('catalogue:sync-redirects')->assertSuccessful();

        expect(redirectMap())->toBe(['/shop/rt-raw-before' => '/shop/rt-raw-after']);
    });

    it('is safe to run again', function (): void {
        $category = redirectCategory('rt-idem-before');
        DB::table((new Url)->getTable())->where('id', $category->defaultUrl->id)->update(['slug' => 'rt-idem-after']);

        $this->artisan('catalogue:sync-redirects')->assertSuccessful();
        $first = redirectMap();
        $this->artisan('catalogue:sync-redirects')->assertSuccessful()->expectsOutputToContain('0 redirect(s)');

        expect(redirectMap())->toBe($first);
    });

    it('lists what it would do on a dry run and writes nothing', function (): void {
        $category = redirectCategory('rt-dry-before');
        DB::table((new Url)->getTable())->where('id', $category->defaultUrl->id)->update(['slug' => 'rt-dry-after']);

        $this->artisan('catalogue:sync-redirects --dry-run')
            ->expectsOutputToContain('Would redirect /shop/rt-dry-before -> /shop/rt-dry-after')
            ->assertSuccessful();

        expect(redirectMap())->toBe([])
            ->and(CollectionPathSnapshot::query()->where('collection_id', $category->id)->value('path'))->toBe('/shop/rt-dry-before');
    });

    it('gives a collection with no snapshot a snapshot and no redirect', function (): void {
        $category = redirectCategory('rt-first-run');
        CollectionPathSnapshot::query()->where('collection_id', $category->id)->delete();

        $this->artisan('catalogue:sync-redirects')->assertSuccessful();

        expect(redirectMap())->toBe([])
            ->and(CollectionPathSnapshot::query()->where('collection_id', $category->id)->value('path'))->toBe('/shop/rt-first-run');
    });

    it('catches a parent moved by a raw tree update', function (): void {
        $parent = redirectCategory('rt-raw-parent');
        redirectCategory('rt-raw-child', $parent);
        $home = redirectCategory('rt-raw-home');

        DB::table($parent->getTable())->where('id', $parent->id)->update(['parent_id' => $home->id]);

        $this->artisan('catalogue:sync-redirects')->assertSuccessful();

        expect(redirectMap())->toBe([
            '/shop/rt-raw-parent' => '/shop/rt-raw-home/rt-raw-parent',
            '/shop/rt-raw-parent/rt-raw-child' => '/shop/rt-raw-home/rt-raw-parent/rt-raw-child',
        ]);
    });
});

it('schedules the redirect sync hourly without overlap, whatever VENDORSYNC_SCHEDULE_ENABLED says', function (): void {
    config()->set('vendorsync.schedule_enabled', false);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'catalogue:sync-redirects'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
