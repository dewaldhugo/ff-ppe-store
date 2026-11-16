<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalogue\Urls\Redirects\CollectionRedirectSync;
use Illuminate\Console\Command;

final class SyncCollectionRedirects extends Command
{
    protected $signature = 'catalogue:sync-redirects {--dry-run : List the redirects that would be created without writing anything}';

    protected $description = 'Record 301 redirects for category URLs that changed without a model event (raw tree rebuilds, imports)';

    public function handle(CollectionRedirectSync $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $sync->sync($dryRun);

        foreach ($report->redirects as $old => $new) {
            $this->line(($dryRun ? 'Would redirect ' : 'Redirected ').$old.' -> '.$new);
        }

        foreach ($report->refused as [$old, $new]) {
            $this->error('Refused (would loop): '.$old.' -> '.$new);
        }

        $this->info(sprintf(
            '%s%d redirect(s), %d snapshot(s) %s, %d removed.',
            $dryRun ? '[dry run] ' : '',
            count($report->redirects),
            $report->snapshotsCreated,
            $dryRun ? 'to create' : 'created',
            $report->snapshotsRemoved,
        ));

        return $report->refused === [] ? self::SUCCESS : self::FAILURE;
    }
}
