<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

/**
 * Counts and report entries collected during one run.
 *
 * It only ever holds small summaries: counters, distinct value sets
 * (tallies), a bounded list of messages and at most twenty sample
 * products. It never holds payloads or products, so its size does not grow
 * with the catalogue.
 */
final class RunReport
{
    public const string SUCCEEDED = 'succeeded';

    public const string FAILED = 'failed';

    public const string ABORTED = 'aborted';

    /** Messages kept in memory. Further ones are only counted. */
    public const int MESSAGE_LIMIT = 500;

    public const int SAMPLE_LIMIT = 20;

    /** Distinct values kept per tally group. */
    private const int TALLY_LIMIT = 200;

    public string $status = 'running';

    public int $seen = 0;

    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $drafted = 0;

    public int $errors = 0;

    public int $outOfScope = 0;

    /** Variants priced by the vendor in a currency other than GBP: imported unpriced. */
    public int $nonGbpVariants = 0;

    /** Products drafted `non_gbp_price`. */
    public int $nonGbpProducts = 0;

    /**
     * @var list<HeldPrice> Variants whose cost, price and compare price were
     *                      kept (or, in a dry run, would be). Never capped: the
     *                      full list goes to the stored report.
     */
    public array $held = [];

    /** @var list<string> */
    public array $messages = [];

    public int $messagesDropped = 0;

    /** @var list<string> A sample of what was (or would be) written. */
    public array $samples = [];

    /** @var array<string, array<string, int>> Distinct values and how often they occurred. */
    public array $tallies = [];

    /** @var list<int> Lunar product IDs written by the run. IDs only. */
    public array $productIds = [];

    /**
     * @var list<int> Lunar product IDs the run sent to the search index.
     *
     * Sent, not necessarily searchable: with Scout queueing they are only
     * queued, and Meilisearch applies documents asynchronously either way.
     */
    public array $reindexedProductIds = [];

    /** What the run did with the search index, in words (see CatalogueImporter::reindex()). */
    public ?string $indexing = null;

    /** @var array<string, int|float> API request counts and timings for the run. */
    public array $stats = [];

    /** Where a dry run saved its full report. */
    public ?string $reportPath = null;

    public function __construct(
        public readonly string $vendorCode,
        public readonly string $type,
        public readonly bool $dryRun = false,
    ) {}

    public function message(string $message): void
    {
        if (count($this->messages) >= self::MESSAGE_LIMIT) {
            $this->messagesDropped++;

            return;
        }

        $this->messages[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors++;
        $this->message($message);
    }

    public function sample(string $line): void
    {
        if (count($this->samples) < self::SAMPLE_LIMIT) {
            $this->samples[] = $line;
        }
    }

    public function tally(string $group, string $key): void
    {
        if (! isset($this->tallies[$group][$key]) && count($this->tallies[$group] ?? []) >= self::TALLY_LIMIT) {
            $key = '(other)';
        }

        $this->tallies[$group][$key] = ($this->tallies[$group][$key] ?? 0) + 1;
    }

    public function finish(string $status, ?string $message = null): self
    {
        $this->status = $status;

        if ($message !== null) {
            array_unshift($this->messages, $message);
        }

        return $this;
    }

    public function ok(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'seen' => $this->seen,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'drafted' => $this->drafted,
            'errors' => $this->errors,
            'out_of_scope' => $this->outOfScope,
            'non_gbp_variants' => $this->nonGbpVariants,
            'non_gbp_products' => $this->nonGbpProducts,
            'held_prices' => count($this->held),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tallies = $this->tallies;
        ksort($tallies);

        foreach ($tallies as $group => $values) {
            ksort($values);
            $tallies[$group] = $values;
        }

        return [
            'vendor' => $this->vendorCode,
            'type' => $this->type,
            'dry_run' => $this->dryRun,
            'status' => $this->status,
            'counts' => $this->counts(),
            'stats' => $this->stats,
            'indexing' => $this->indexing,
            'tallies' => $tallies,
            'samples' => $this->samples,
            'held_prices' => array_map(fn (HeldPrice $held): array => $held->toArray(), $this->held),
            'messages' => $this->messages,
            'messages_not_shown' => $this->messagesDropped,
        ];
    }
}
