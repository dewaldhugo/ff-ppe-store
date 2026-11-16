<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Preflight\Preflight as Checks;
use App\Support\Preflight\Result;
use Illuminate\Console\Command;

final class Preflight extends Command
{
    protected $signature = 'app:preflight';

    protected $description = 'Check the server before a deploy: PHP extensions, database, Redis, Meilisearch, disks and queue';

    public function handle(Checks $checks): int
    {
        $results = $checks->run();

        $this->table(
            ['Check', 'Status', 'Detail'],
            array_map(fn (Result $result): array => [$result->check, strtoupper($result->status), $result->message], $results),
        );

        foreach ($results as $result) {
            if ($result->status === Result::WARN) {
                $this->warn("Warning: {$result->check}: {$result->message}");
            }
        }

        $failures = array_values(array_filter($results, fn (Result $result): bool => $result->failed()));

        if ($failures === []) {
            $this->info('Preflight passed.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->error("{$failure->check}: {$failure->message}");
        }

        $this->error(count($failures).' preflight check(s) failed. Do not deploy until they are fixed.');

        return self::FAILURE;
    }
}
