<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

use App\Domain\VendorSync\Models\SyncRun;
use App\Domain\VendorSync\Models\Vendor;
use App\Domain\VendorSync\Notifications\SyncRunAlert;
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
