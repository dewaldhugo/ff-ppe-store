<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The invoice number of each placed order: the date it was placed as DDMMYYYY, a slash and a
        // sequence for that day starting at 001 (10102026/001). Owned by the storefront. A number is
        // given once, when the order is placed, and never changes (App\Domain\Orders\InvoiceNumbers).
        // The unique keys are what keep two orders from ever sharing a number.
        Schema::create('invoice_numbers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('lunar_orders')->cascadeOnDelete();
            $table->date('invoice_date');
            $table->unsignedInteger('sequence');
            $table->string('number', 20)->unique();
            $table->timestamp('created_at')->nullable();

            $table->unique(['invoice_date', 'sequence']);
        });

        // Orders placed before this existed: number them in the order they were placed.
        $counters = [];

        DB::table('lunar_orders')
            ->whereNotNull('placed_at')
            ->orderBy('placed_at')
            ->orderBy('id')
            ->select(['id', 'placed_at'])
            ->each(function (object $order) use (&$counters): void {
                $date = substr((string) $order->placed_at, 0, 10);
                $counters[$date] = ($counters[$date] ?? 0) + 1;

                DB::table('invoice_numbers')->insert([
                    'order_id' => $order->id,
                    'invoice_date' => $date,
                    'sequence' => $counters[$date],
                    'number' => date('dmY', (int) strtotime($date)).'/'.str_pad((string) $counters[$date], 3, '0', STR_PAD_LEFT),
                    'created_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_numbers');
    }
};
