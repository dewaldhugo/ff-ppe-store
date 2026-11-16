<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls\Redirects;

use App\Domain\Catalogue\Urls\CollectionPath;
use App\Models\CollectionPathSnapshot;
use App\Models\UrlRedirect;
use Lunar\Models\Collection;

/**
 * Compares every category's live path with its stored snapshot and records a
 * redirect for each path that changed. The model events and the
 * `catalogue:sync-redirects` command both run this, so they behave the same.
 *
 * A collection with no snapshot gets one and no redirect: that is a new
 * collection, or the first run on an existing database.
 */
final class CollectionRedirectSync
{
    public function __construct(
        private readonly CollectionPath $paths,
        private readonly RedirectRecorder $recorder,
    ) {}

    public function sync(bool $dryRun = false): SyncReport
    {
        $this->paths->flush();

        $live = $this->paths->all();
        $snapshots = CollectionPathSnapshot::query()->pluck('path', 'collection_id')->all();

        $redirects = [];
        $refused = [];
        $created = 0;

        foreach ($live as $id => $path) {
            $known = $snapshots[$id] ?? null;

            if ($known === null) {
                $created++;

                if (! $dryRun) {
                    CollectionPathSnapshot::query()->create(['collection_id' => $id, 'path' => $path]);
                }

                continue;
            }

            if ($known === $path) {
                continue;
            }

            if ($dryRun) {
                $redirects[$known] = $path;

                continue;
            }

            if ($this->recorder->record($known, $path) === RedirectOutcome::Refused) {
                $refused[] = [$known, $path];

                continue;
            }

            $redirects[$known] = $path;
            CollectionPathSnapshot::query()->where('collection_id', $id)->update(['path' => $path]);
        }

        $removed = $this->forgetMissing(array_keys($live), $snapshots, $dryRun);

        return new SyncReport($redirects, $refused, $created, $removed);
    }

    /**
     * Drop the snapshot of a collection that no longer exists in the category
     * group, and any redirect that led to it, so no 301 ends in a 404.
     * A collection that still exists but has no URL yet is left alone.
     *
     * @param  list<int>  $liveIds
     * @param  array<int, string>  $snapshots
     */
    private function forgetMissing(array $liveIds, array $snapshots, bool $dryRun): int
    {
        $candidates = array_diff(array_keys($snapshots), $liveIds);

        if ($candidates === []) {
            return 0;
        }

        $existing = Collection::query()
            ->whereKey($candidates)
            ->where('collection_group_id', $this->paths->groupId())
            ->pluck('id')
            ->all();

        $gone = array_diff($candidates, $existing);

        if (! $dryRun) {
            foreach ($gone as $id) {
                UrlRedirect::query()->where('new_path', $snapshots[$id])->delete();
                CollectionPathSnapshot::query()->where('collection_id', $id)->delete();
            }
        }

        return count($gone);
    }
}
