<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Collections;

use App\Domain\Catalogue\Keys\CatalogueKeys;
use App\Domain\Catalogue\Keys\KeyType;
use App\Domain\Catalogue\Menu\CategoryMenu;
use Illuminate\Support\Facades\DB;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Collection;

/**
 * Moves a database that was seeded with an earlier category tree into the
 * tree of the data contract (CategoryTree): each contract collection gets its
 * contract parent and its place in the menu order, and a collection that still
 * carries a superseded contract name gets the new one.
 *
 * The foundation seeder never changes an existing record, so it cannot do
 * this. This is a deliberate, one-off step (docs/launch-checklist.md): it
 * overrides a parent or an order set in the admin panel, but never a name
 * edited there, a slug, or a collection the contract does not name. Run the
 * seeder first so the new parents exist. Running it again changes nothing.
 *
 * A moved category's old path is recorded as a 301 by the redirect observer,
 * because every move here goes through the Collection model.
 */
final class RestructureCategories
{
    /** @var list<string> */
    private array $changes = [];

    /** @var list<string> */
    private array $missing = [];

    public function __construct(private readonly CatalogueKeys $keys) {}

    /**
     * @return array{changes: list<string>, missing: list<string>}
     */
    public function run(bool $dryRun = false): array
    {
        $this->changes = [];
        $this->missing = [];

        $tree = CategoryTree::GROUPS[CategoryTree::CATEGORIES][1];

        Collection::withoutSyncingToSearch(function () use ($tree, $dryRun): void {
            DB::transaction(function () use ($tree, $dryRun): void {
                $this->level($tree, null, $dryRun);
            });
        });

        if (! $dryRun && $this->changes !== []) {
            CategoryMenu::forget();
            CatalogueKeys::flush();
        }

        return ['changes' => $this->changes, 'missing' => $this->missing];
    }

    /**
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $tree
     */
    private function level(array $tree, ?string $parentHandle, bool $dryRun): void
    {
        $previousHandle = null;

        foreach ($tree as $handle => [$name, $children]) {
            $collection = $this->collection($handle);

            if ($collection === null) {
                $this->missing[] = $handle;

                continue;
            }

            $this->rename($collection, $handle, $name, $dryRun);
            $this->place($collection, $handle, $parentHandle, $previousHandle, $dryRun);

            $previousHandle = $handle;

            $this->level($children, $handle, $dryRun);
        }
    }

    private function rename(Collection $collection, string $handle, string $name, bool $dryRun): void
    {
        $old = CategoryTree::RENAMED_FROM[$handle] ?? null;

        if ($old === null || (string) $collection->translateAttribute('name') !== $old) {
            return;
        }

        $this->changes[] = "Renamed {$handle}: '{$old}' to '{$name}'";

        if ($dryRun) {
            return;
        }

        $attributes = $collection->attribute_data?->all() ?? [];
        $attributes['name'] = new TranslatedText(['en' => new Text($name)]);
        $collection->update(['attribute_data' => $attributes]);
    }

    /**
     * Under the contract parent, straight after the contract sibling before
     * it (or first when it has none).
     */
    private function place(Collection $collection, string $handle, ?string $parentHandle, ?string $previousHandle, bool $dryRun): void
    {
        // Positions shift with every move, so the neighbours are read fresh.
        $parent = $parentHandle === null ? null : $this->collection($parentHandle);
        $previous = $previousHandle === null ? null : $this->collection($previousHandle);

        $parentRight = $collection->parent_id === $parent?->id;
        $before = $collection->getPrevSibling(['id']);
        $orderRight = $parentRight && $before?->id === $previous?->id;

        if ($parentRight && $orderRight) {
            return;
        }

        $this->changes[] = $parentRight
            ? "Reordered {$handle}"
            : "Moved {$handle} under ".($parentHandle ?? 'the top level');

        if ($dryRun) {
            return;
        }

        if ($previous !== null) {
            $collection->insertAfterNode($previous);

            return;
        }

        if ($parent !== null) {
            $collection->prependToNode($parent)->save();

            return;
        }

        $first = Collection::query()
            ->where('collection_group_id', $collection->collection_group_id)
            ->whereNull('parent_id')
            ->defaultOrder()
            ->first();

        if ($first !== null && $first->id !== $collection->id) {
            $collection->insertBeforeNode($first);
        }
    }

    private function collection(string $handle): ?Collection
    {
        $collection = $this->keys->find(KeyType::Collection, $handle);

        return $collection instanceof Collection ? $collection : null;
    }
}
