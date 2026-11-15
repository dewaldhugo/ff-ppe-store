<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Notifications\SyncRunAlert;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Persists runs in `sync_runs` and raises the alert for runs that failed or
 * were aborted (a stale feed aborts).
 */
final class RunRecorder
{
    public function vendor(string $code): Vendor
    {
        return Vendor::query()->firstOrCreate(
            ['code' => $code],
            ['name' => config("vendorsync.vendors.{$code}.name", $code)],
        );
    }

    /**
     * Seconds a run keeps its lock. It must exceed the longest expected run
     * with margin, so it never lapses under a run that is still going: at
     * least two hours, whatever the config says. A run releases the lock when
     * it ends; the time limit is only for a process that died.
     */
    public function lockSeconds(): int
    {
        return max(7200, (int) config('vendorsync.lock_seconds', 4 * 3600));
    }

    /**
     * Take the lock for a vendor and type. Null when another run holds it.
     * Once it is held, an earlier run still marked `running` has ended without
     * saying so (the process stopped or crashed) and is marked failed.
     *
     * Image runs finish asynchronously, when their last queued job does, so a
     * running image run is only failed once it is older than `$staleAfterSeconds`.
     */
    public function lock(Vendor $vendor, string $type, ?int $staleAfterSeconds = null): ?Lock
    {
        $lock = Cache::lock("vendorsync.run.{$vendor->code}.{$type}", $this->lockSeconds());

        if (! $lock->get()) {
            return null;
        }

        $this->failLeftover($vendor, $type, $staleAfterSeconds);

        return $lock;
    }

    /**
     * @return int The number of runs marked failed.
     */
    private function failLeftover(Vendor $vendor, string $type, ?int $staleAfterSeconds): int
    {
        $leftovers = SyncRun::query()
            ->where('vendor_id', $vendor->id)
            ->where('type', $type)
            ->where('status', 'running')
            ->when($staleAfterSeconds !== null, fn ($query) => $query->where('started_at', '<', now()->subSeconds($staleAfterSeconds)))
            ->get();

        foreach ($leftovers as $run) {
            $run->update([
                'status' => RunReport::FAILED,
                'finished_at' => now(),
                'messages' => [...($run->messages ?? []), 'Marked failed: this run never finished. The process stopped or crashed, and a later run found it still marked running.'],
            ]);

            $this->alert($run);
        }

        return $leftovers->count();
    }

    public function start(Vendor $vendor, string $type): SyncRun
    {
        return SyncRun::query()->create([
            'vendor_id' => $vendor->id,
            'type' => $type,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function finish(SyncRun $run, RunReport $report): void
    {
        $cap = (int) config('vendorsync.messages_cap', 200);
        $messages = $report->messages;

        if (count($messages) > $cap) {
            $dropped = count($messages) - $cap;
            $messages = [...array_slice($messages, 0, $cap), "... and {$dropped} more entries."];
        }

        $run->update([
            'status' => $report->status,
            'finished_at' => now(),
            'messages' => $messages,
            'stats' => $report->stats === [] ? null : $report->stats,
            ...$report->counts(),
        ]);

        if (! $report->ok()) {
            $this->alert($run);
        }
    }

    public function lastSuccessful(Vendor $vendor, string $type): ?SyncRun
    {
        return SyncRun::query()
            ->where('vendor_id', $vendor->id)
            ->where('type', $type)
            ->where('status', RunReport::SUCCEEDED)
            ->latest('id')
            ->first();
    }

    /**
     * Failed, aborted and stale runs are logged at error level and mailed.
     */
    public function alert(SyncRun $run): void
    {
        $run->loadMissing('vendor');

        Log::error("VendorSync {$run->type} run {$run->id} for {$run->vendor->code} {$run->status}.", [
            'counts' => $run->only(['seen', 'created', 'updated', 'unchanged', 'drafted', 'errors', 'out_of_scope']),
            'messages' => array_slice($run->messages ?? [], 0, 10),
        ]);

        $email = config('vendorsync.alert_email');

        if (filled($email)) {
            Notification::route('mail', $email)->notify(new SyncRunAlert($run));
        }
    }
}
