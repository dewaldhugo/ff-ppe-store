<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tax Driver
    |--------------------------------------------------------------------------
    |
    | Here you can specify which tax driver should be used. By default system is used
    | and should work for you in most cases.
    |
    */
    // 'store' is App\Domain\Pricing\Vat\StoreTaxDriver (the system driver plus the employer
    // rate for zero-protective lines), registered in AppServiceProvider.
    'driver' => 'store',

];
