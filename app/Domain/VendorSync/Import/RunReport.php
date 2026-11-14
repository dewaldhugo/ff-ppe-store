<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Import;

/**
 * Counts and report entries collected during one run.
 */
final class RunReport
{
    public const string SUCCEEDED = 'succeeded';

    public const string FAILED = 'failed';

    public const string ABORTED = 'aborted';

    public string $status = 'running';

    public int $seen = 0;

    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $drafted = 0;

    public int $errors = 0;

    public int $outOfScope = 0;

    /** @var list<string> */
    public array $messages = [];

    /** @var list<string> A sample of what was (or would be) written. */
    public array $samples = [];

    /** @var array<string, array<string, int>> Distributions, e.g. tax_class => [standard => 12]. */
    public array $tallies = [];

    /** @var list<int> Lunar product IDs written by the run. */
    public array $productIds = [];

    /** @var list<int> Lunar product IDs reindexed by the run. */
    public array $reindexedProductIds = [];

    public function __construct(
        public readonly string $vendorCode,
        public readonly string $type,
        public readonly bool $dryRun = false,
    ) {}

    public function message(string $message): void
    {
        $this->messages[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors++;
        $this->messages[] = $message;
    }

    public function sample(string $line, int $limit = 20): void
    {
        if (count($this->samples) < $limit) {
            $this->samples[] = $line;
        }
    }

    public function tally(string $group, string $key): void
    {
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        ksort($this->tallies);

        return [
            'vendor' => $this->vendorCode,
            'type' => $this->type,
            'dry_run' => $this->dryRun,
            'status' => $this->status,
            'counts' => $this->counts(),
            'tallies' => $this->tallies,
            'samples' => $this->samples,
            'messages' => $this->messages,
        ];
    }
}
