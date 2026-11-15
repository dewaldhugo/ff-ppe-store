<?php

declare(strict_types=1);

use App\Domain\Catalogue\Options\ShoeSizes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owned by the ingestion module.
//
// Shoe size values sort by their canonical EU size (see ShoeSizes), for both
// vendors. Values created before that rule sorted by UK size, which would
// put an infant size (EU 23) after UK 10. This brings them up to date.
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('lunar.database.table_prefix');

        $optionId = DB::table($prefix.'product_options')->where('handle', 'shoe_size')->value('id');

        if ($optionId === null) {
            return;
        }

        foreach (DB::table($prefix.'product_option_values')->where('product_option_id', $optionId)->get() as $value) {
            $name = json_decode((string) $value->name, true)['en'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            $meta = json_decode((string) ($value->meta ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $meta['sort'] = ShoeSizes::sort($name);

            DB::table($prefix.'product_option_values')->where('id', $value->id)->update(['meta' => json_encode($meta)]);
        }
    }

    public function down(): void
    {
        // The previous sort was the UK size. Nothing to restore: the sort is
        // set again from the canonical scale whenever a size is imported.
    }
};
