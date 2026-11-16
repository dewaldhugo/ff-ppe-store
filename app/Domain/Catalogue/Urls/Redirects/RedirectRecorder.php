<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Urls\Redirects;

use App\Models\UrlRedirect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes a redirect from a retired path to its replacement, keeping the table
 * free of chains and loops:
 *
 * - A path that is live again loses its own redirect (a move back).
 * - Redirects that pointed at the retired path are repointed at the new one,
 *   so A to B followed by B to C leaves A to C and B to C.
 * - A write that would leave a loop is rolled back and refused.
 */
final class RedirectRecorder
{
    private const int MAX_HOPS = 20;

    public function record(string $oldPath, string $newPath): RedirectOutcome
    {
        if ($oldPath === $newPath) {
            return RedirectOutcome::Unchanged;
        }

        $existing = UrlRedirect::query()->where('old_path', $oldPath)->first();

        if ($existing !== null && $existing->new_path === $newPath
            && ! UrlRedirect::query()->where('old_path', $newPath)->exists()) {
            return RedirectOutcome::Unchanged;
        }

        DB::beginTransaction();

        try {
            UrlRedirect::query()->where('old_path', $newPath)->delete();
            UrlRedirect::query()->where('new_path', $oldPath)->update(['new_path' => $newPath]);
            UrlRedirect::query()->updateOrCreate(['old_path' => $oldPath], ['new_path' => $newPath]);

            if ($this->loops($oldPath)) {
                DB::rollBack();
                Log::warning('Redirect refused because it would loop.', ['old' => $oldPath, 'new' => $newPath]);

                return RedirectOutcome::Refused;
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return RedirectOutcome::Recorded;
    }

    /** Whether following redirects from $start ever comes back round or fails to end. */
    private function loops(string $start): bool
    {
        $seen = [$start => true];
        $current = $start;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $next = UrlRedirect::query()->where('old_path', $current)->value('new_path');

            if ($next === null) {
                return false;
            }

            if (isset($seen[$next])) {
                return true;
            }

            $seen[$next] = true;
            $current = $next;
        }

        return true;
    }
}
