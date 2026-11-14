<?php

declare(strict_types=1);

namespace App\Domain\VendorSync\Footsure;

use App\Domain\VendorSync\Contracts\VendorSource;
use App\Domain\VendorSync\Data\RawRecord;
use App\Domain\VendorSync\Data\SourceBatch;
use App\Domain\VendorSync\Exceptions\SourceAborted;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use SplFileObject;
use Throwable;

/**
 * Reads the newest Footsure CSV export from the incoming folder. Delivery
 * to that folder is handled outside this application.
 *
 * One raw record per ProductID. Each Family Product Reference inside it is
 * one colourway of that product, and each row is one variant.
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
    ) {}

    public function fetch(): SourceBatch
    {
        $this->file = $this->onlyFile ?? $this->newestFile();

        $rows = $this->rows($this->file);

        if ($rows === []) {
            throw SourceAborted::because('The Footsure file '.basename($this->file).' has no data rows.');
        }

        $this->assertFresh($rows[0][CsvColumns::FILE_DATE] ?? '');

        $products = [];

        foreach ($rows as $row) {
            $products[$row['ProductID']][] = $row;
        }

        $records = [];

        foreach ($products as $productId => $productRows) {
            $records[] = new RawRecord(
                externalId: (string) $productId,
                payload: ['rows' => $productRows],
                // The file date changes every day; it is not product data.
                hashable: ['rows' => array_map(
                    fn (array $row): array => array_diff_key($row, [CsvColumns::FILE_DATE => true]),
                    $productRows,
                )],
            );
        }

        return new SourceBatch(count($records), $records);
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
     * @return list<array<string, string>>
     */
    private function rows(string $path): array
    {
        if (! is_file($path)) {
            throw SourceAborted::because("The Footsure file {$path} does not exist.");
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',', '"', '');

        $header = null;
        $rows = [];

        foreach ($file as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }

            if ($header === null) {
                $header = $this->header($line, basename($path));

                continue;
            }

            if (count($line) !== count($header)) {
                throw SourceAborted::because(sprintf(
                    'Row %d of %s has %d columns, expected %d.',
                    $file->key() + 1,
                    basename($path),
                    count($line),
                    count($header),
                ));
            }

            // Barcodes and references stay strings, never numbers.
            $rows[] = array_combine($header, array_map(fn (?string $value): string => trim((string) $value), $line));
        }

        return $rows;
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

    private function assertFresh(string $fileDate): void
    {
        try {
            $created = CarbonImmutable::createFromFormat(CsvColumns::FILE_DATE_FORMAT, $fileDate, 'Europe/London');
        } catch (Throwable) {
            $created = null;
        }

        if (! $created instanceof CarbonImmutable) {
            throw SourceAborted::because("The Footsure file has an unreadable File_Create_Date: '{$fileDate}'.");
        }

        if (! $this->checkAge) {
            return;
        }

        $maxAge = (int) $this->config['max_age_hours'];

        if ($created->lt(now()->subHours($maxAge))) {
            throw SourceAborted::because(
                "Stale Footsure feed: the file was created {$fileDate}, more than {$maxAge} hours ago.",
            );
        }
    }
}
