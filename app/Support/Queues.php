<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Queue names. config/horizon.php defines one supervisor per name, and local
 * workers run with `--queue=default,images,conversions`.
 */
final class Queues
{
    /** Short jobs (timeout 60). */
    public const string DEFAULT = 'default';

    /** Vendor image jobs: long downloads (timeout 900). */
    public const string IMAGES = 'images';

    /** Spatie media conversions, when conversions are queued (`QUEUE_CONVERSIONS_BY_DEFAULT`). */
    public const string CONVERSIONS = 'conversions';
}
