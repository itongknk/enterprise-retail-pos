<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();$table->uuid('organization_id');
            $table->uuid('branch_id');$table->string('shift_number', 50)->unique();
            $table->uuid('opened_by')->nullable();$table->uuid('closed_by')->nullable();
            $table->string('status', 20)->default('OPEN'); // OPEN, CLOSED$table->decimal('opening_cash', 14, 2)->default(0);
            $table->decimal('expected_cash', 14, 2)->nullable();$table->decimal('closing_cash_counted', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();$table->text('opening_note')->nullable();
            $table->text('closing_note')->nullable();$table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();$table->timestamps();

            $table->index(['organization_id', 'branch_id', 'status']);$table->index(['organization_id', 'opened_by', 'status']);
        });

        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'shift_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->uuid('shift_id')->nullable()->after('branch_id');$table->index(['organization_id', 'shift_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'shift_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropIndex(['organization_id', 'shift_id']);$table->dropColumn('shift_id');
            });
        }

        Schema::dropIfExists('pos_shifts');
    }
};