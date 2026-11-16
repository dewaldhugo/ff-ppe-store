<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum free disk space (GB)
    |--------------------------------------------------------------------------
    |
    | app:preflight warns, without failing, when the disk holding storage/ has
    | less free space than this. Product images and their conversions live
    | there.
    |
    */

    'min_free_disk_gb' => (float) env('PREFLIGHT_MIN_FREE_DISK_GB', 10),

];
