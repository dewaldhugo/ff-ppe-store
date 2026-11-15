<?php

declare(strict_types=1);

use App\Domain\VendorSync\VendorSyncServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    VendorSyncServiceProvider::class,
];
