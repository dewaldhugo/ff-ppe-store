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
        Schema::table('users', function (Blueprint $table): void {
            // Null until staff activate the login in the admin panel (docs/accounts.md).
            $table->timestamp('approved_at')->nullable()->after('email_verified_at');
            // Every customer may pay by card; 30 day account terms are switched on per customer.
            $table->boolean('account_terms')->default(false)->after('approved_at');
        });

        // Customers who registered before approval existed keep their login.
        DB::table('users')->whereNull('approved_at')->update(['approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['approved_at', 'account_terms']);
        });
    }
};
