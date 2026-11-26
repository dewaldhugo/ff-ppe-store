<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

use App\Domain\VendorSync\Contracts\VendorMapper;
use App\Domain\VendorSync\Contracts\VendorSource;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Data\SourceBatch;
use App\Domain\VendorSync\Exceptions\SourceAborted;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Illuminate\Support\Facades\File;

/**
 * Reads the newest Footsure CSV export from the incoming folder. Delivery
 * to that folder is handled outside this application.
 *
 * One raw record per ProductID. Each Family Product Reference inside it is
 * one colourway of that product, and each row is one variant.
 *
 * Exception: a product line the vendor split into several ProductIDs by size
 * band (the same shoe listed once for the small sizes, once for the large)
 * is one record carrying the rows of all of them, so it becomes one product.
 * `SplitDetector` finds them, from each ProductID as the mapper maps it, when
 * a mapper is given. Without one every ProductID is its own record.
 *
 * The rows of one product are not next to each other in the real file (69
 * products appear in 283 separate runs), and the file may grow, so the file
 * is never held in memory. Two passes:
 *
 * 1. `fetch()` reads the file line by line, checks the header and the file
 *    date, and keeps only `ProductID => [byte offset of each of its rows]`:
 *    one integer per row. The product count is known from this pass alone,
 *    before any product is mapped, which the safety threshold needs.
 * 2. The returned records are generated one at a time: seek to a product's
 *    row offsets, read just those rows, yield one record, release it.
 *
 * Memory is one integer per row plus one product's rows at a time.
 */
final class FootsureSource implements VendorSource
{
    private ?string $file = null;

    /**
     * @param  array<string, mixed>  $config  The `vendorsync.footsure` config.
     * @param  string|null  $onlyFile  Read this file instead of the incoming folder (local seeding).
     */
    public function __construct(
        private readonly array $config,
        private readonly ?string $onlyFile = null,
        private readonly bool $checkAge = true,
        private readonly ?VendorMapper $mapper = null,
    ) {}

    public function fetch(): SourceBatch
    {
        $this->file = $this->onlyFile ?? $this->newestFile();

        [$header, $offsets, $noProductId] = $this->index($this->file);

        // The ProductIDs of each split line, under the first of them.
        $groups = $this->splits($this->file, $header, $offsets);
        $merged = array_sum(array_map(fn (array $ids): int => count($ids) - 1, $groups));

        return new SourceBatch(count($offsets) - $merged + ($noProductId > 0 ? 1 : 0), $this->records($this->file, $header, $offsets, $noProductId, $groups));
    }

    /**
     * Move the processed file to the archive with a timestamp and prune
     * archives older than the retention period.
     */
    public function completed(): void
    {
        if ($this->file === null || $this->onlyFile !== null || ! is_file($this->file)) {
            return;
        }

        $archive = (string) $this->config['archive_path'];
        File::ensureDirectoryExists($archive);

        $name = pathinfo($this->file, PATHINFO_FILENAME).'-'.now()->format('Ymd-His').'.csv';
        File::move($this->file, $archive.DIRECTORY_SEPARATOR.$name);

        $cutoff = now()->subDays((int) $this->config['archive_days'])->getTimestamp();

        foreach (File::glob($archive.DIRECTORY_SEPARATOR.'*.csv') as $old) {
            if (File::lastModified($old) < $cutoff) {
                File::delete($old);
            }
        }
    }

    private function newestFile(): string
    {
        $directory = (string) $this->config['incoming_path'];
        $files = is_dir($directory) ? File::glob($directory.DIRECTORY_SEPARATOR.'*.csv') : [];

        if ($files === []) {
            throw SourceAborted::because("No Footsure CSV file was found in {$directory}.");
        }

        usort($files, fn (string $a, string $b): int => File::lastModified($b) <=> File::lastModified($a));

        return $files[0];
    }

    /**
     * Pass 1: validate, and index the byte offset of every row by ProductID.
     *
     * @return array{0: list<string>, 1: array<string, list<int>>, 2: int} Header, offsets by ProductID, and the number of rows without a ProductID.
     */
    private function index(string $path): array
    {
        if (! is_file($path)) {
            throw SourceAborted::because("The Footsure file {$path} does not exist.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw SourceAborted::because("The Footsure file {$path} could not be opened.");
        }

        try {
            $header = null;
            $productColumn = null;
            $offsets = [];
            $noProductId = 0;
            $firstDate = null;
            $dateColumn = null;
            $number = 0;

            while (true) {
                $offset = ftell($handle);
                $line = fgetcsv($handle, 0, ',', '"', '');

                if ($line === false) {
                    break;
                }

                // A blank line is not a record.
                if ($line === [null]) {
                    continue;
                }

                if ($header === null) {
                    $header = $this->header($line, basename($path));
                    $productColumn = array_search('ProductID', $header, true);
                    $dateColumn = array_search(CsvColumns::FILE_DATE, $header, true);

                    continue;
                }

                $number++;

                if (count($line) !== count($header)) {
                    throw SourceAborted::because(sprintf(
                        'Row %d of %s has %d columns, expected %d.',
                        $number,
                        basename($path),
                        count($line),
                        count($header),
                    ));
                }

                // The date is read from the first data row. A feed that is
                // checked once is checked before anything else is done.
                if ($firstDate === null) {
                    $firstDate = trim((string) $line[$dateColumn]);
                    $this->assertFresh($firstDate);
                }

                $productId = trim((string) $line[$productColumn]);

                if ($productId === '') {
                    $noProductId++;

                    continue;
                }

                $offsets[$productId][] = (int) $offset;
            }
        } finally {
            fclose($handle);
        }

        if ($header === null || $number === 0) {
            throw SourceAborted::because('The Footsure file '.basename($path).' has no data rows.');
        }

        return [$header, $offsets, $noProductId];
    }

    /**
     * Pass 2: one record per product, read from its row offsets.
     *
     * The file is closed when the generator finishes or is discarded, so it
     * can be archived afterwards (an open file cannot be moved on Windows).
     *
     * @param  list<string>  $header
     * @param  array<string, list<int>>  $offsets
     * @param  array<string, list<string>>  $groups  The ProductIDs of each split line, by the first of them.
     * @return Generator<int, RawRecord>
     */
    private function records(string $path, array $header, array $offsets, int $noProductId, array $groups = []): Generator
    {
        $secondary = [];

        foreach ($groups as $ids) {
            foreach (array_slice($ids, 1) as $id) {
                $secondary[$id] = true;
            }
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw SourceAborted::because("The Footsure file {$path} could not be opened.");
        }

        try {
            foreach ($offsets as $productId => $rowOffsets) {
                $productId = (string) $productId;

                // Another ProductID of a split line: yielded with its line.
                if (isset($secondary[$productId])) {
                    continue;
                }

                $members = $groups[$productId] ?? [];
                $rows = [];

                foreach ($members === [] ? [$productId] : $members as $member) {
                    array_push($rows, ...$this->rows($handle, $header, $offsets[$member]));
                }

                $payload = ['rows' => $rows];
                $hashable = ['rows' => array_map(
                    // The file date changes every day; it is not product data.
                    fn (array $row): array => array_diff_key($row, [CsvColumns::FILE_DATE => true]),
                    $rows,
                )];

                if ($members !== []) {
                    $payload['members'] = $hashable['members'] = $members;
                }

                yield new RawRecord(
                    externalId: $productId,
                    payload: $payload,
                    hashable: $hashable,
                    name: $rows[0]['Short Product Name'] ?? null,
                    memberIds: $members,
                );

                unset($rows, $payload, $hashable);
            }

            if ($noProductId > 0) {
                yield new RawRecord(
                    externalId: '(no ProductID)',
                    error: "{$noProductId} rows have no ProductID and were not imported.",
                );
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The rows at these byte offsets, read one at a time.
     *
     * @param  resource  $handle
     * @param  list<string>  $header
     * @param  list<int>  $rowOffsets
     * @return list<array<string, string>>
     */
    private function rows($handle, array $header, array $rowOffsets): array
    {
        $rows = [];

        foreach ($rowOffsets as $offset) {
            fseek($handle, $offset);
            $line = fgetcsv($handle, 0, ',', '"', '');

            if (! is_array($line) || count($line) !== count($header)) {
                throw SourceAborted::because('The Footsure file changed while it was being read.');
            }

            // Barcodes and references stay strings, never numbers.
            $rows[] = array_combine($header, array_map(fn (?string $value): string => trim((string) $value), $line));
        }

        return $rows;
    }

    /**
     * Pass 1.5: the product lines the vendor listed under several ProductIDs.
     * Each ProductID is read and mapped on its own, only to be reduced to a
     * small signature, then released, so memory stays flat. Nothing is
     * detected without a mapper.
     *
     * @param  list<string>  $header
     * @param  array<string, list<int>>  $offsets
     * @return array<string, list<string>> ProductIDs by the first of them, for lines of two or more.
     */
    private function splits(string $path, array $header, array $offsets): array
    {
        if ($this->mapper === null || count($offsets) < 2) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw SourceAborted::because("The Footsure file {$path} could not be opened.");
        }

        $signatures = [];

        try {
            foreach ($offsets as $productId => $rowOffsets) {
                $mapped = $this->mapper->map(new RawRecord((string) $productId, ['rows' => $this->rows($handle, $header, $rowOffsets)]));

                if ($mapped->product === null) {
                    continue;
                }

                $signature = ProductSignature::from($mapped->product);

                if ($signature !== null) {
                    $signatures[] = $signature;
                }

                unset($mapped);
            }
        } finally {
            fclose($handle);
        }

        $groups = [];

        foreach ((new SplitDetector)->detect($signatures)->groups as $ids) {
            $groups[$ids[0]] = $ids;
        }

        return $groups;
    }

    /**
     * @param  list<string|null>  $line
     * @return list<string>
     */
    private function header(array $line, string $fileName): array
    {
        $header = array_map(fn (?string $name): string => trim((string) $name), $line);
        // A UTF-8 byte order mark would otherwise become part of the first name.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];

        $missing = array_values(array_diff(CsvColumns::REQUIRED, $header));

        if ($missing !== []) {
            throw SourceAborted::because(
                "The Footsure file {$fileName} is missing expected columns: ".implode(', ', $missing).'.',
            );
        }

        return $header;
    }

    /**
     * The file must carry a readable creation date, not too old and not in
     * the future. The format is configured because it is easy to get wrong
     * without noticing: the real feed is month/day/year, and read as
     * day/month/year `11/15/2026` is "month 15", which PHP rolls over into
     * March 2027. A date in the future always looked fresh, so the staleness
     * rule could never fire. Three guards stop that happening again: an exact
     * format match without roll-over, a refusal of dates in the future, and
     * the age limit itself.
     */
    private function assertFresh(string $fileDate): void
    {
        $created = $this->parseFileDate($fileDate);

        if ($created === null) {
            throw SourceAborted::because(
                "The Footsure file has an unreadable File_Create_Date: '{$fileDate}' (expected format ".$this->dateFormat().').',
            );
        }

        if (! $this->checkAge) {
            return;
        }

        $futureMinutes = (int) ($this->config['max_future_minutes'] ?? 60);

        if ($created->gt(now()->addMinutes($futureMinutes))) {
            throw SourceAborted::because(
                "The Footsure file's File_Create_Date '{$fileDate}' is in the future, so it is not trusted. Check the date format ({$this->dateFormat()}).",
            );
        }

        $maxAge = (int) $this->config['max_age_hours'];

        if ($created->lt(now()->subHours($maxAge))) {
            throw SourceAborted::because(
                "Stale Footsure feed: the file was created {$fileDate}, more than {$maxAge} hours ago.",
            );
        }
    }

    private function dateFormat(): string
    {
        return (string) ($this->config['file_date_format'] ?? 'm/d/Y H:i:s');
    }

    /**
     * Exactly the configured format, in UK time. A value that PHP would only
     * accept by rolling it over (month 15, day 40) is not a date.
     */
    private function parseFileDate(string $fileDate): ?CarbonImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$this->dateFormat(), $fileDate, new DateTimeZone('Europe/London'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return CarbonImmutable::instance($parsed);
    }
}
