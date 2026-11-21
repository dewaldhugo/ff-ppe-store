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
        // Staff can now delete an order in the admin (App\Domain\Orders\DeleteOrder). Its invoice number must
        // not be given to a later order, so the number stays in the table with no order, instead of being
        // deleted with it: the next order placed that day continues the sequence, and the gap is on record.
        Schema::table('invoice_numbers', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });

        Schema::table('invoice_numbers', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable()->change();
        });

        Schema::table('invoice_numbers', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('lunar_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // A number with no order cannot go back to a table that needs one.
        DB::table('invoice_numbers')->whereNull('order_id')->delete();

        Schema::table('invoice_numbers', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });

        Schema::table('invoice_numbers', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });

        Schema::table('invoice_numbers', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('lunar_orders')->cascadeOnDelete();
        });
    }
};
