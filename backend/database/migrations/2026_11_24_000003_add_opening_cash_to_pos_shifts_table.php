<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_shifts') && ! Schema::hasColumn('pos_shifts', 'opening_cash')) {
            Schema::table('pos_shifts', function (Blueprint $table) {
                $table->decimal('opening_cash', 14, 2)->default(0)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_shifts') && Schema::hasColumn('pos_shifts', 'opening_cash')) {
            Schema::table('pos_shifts', function (Blueprint $table) {
                $table->dropColumn('opening_cash');
            });
        }
    }
};