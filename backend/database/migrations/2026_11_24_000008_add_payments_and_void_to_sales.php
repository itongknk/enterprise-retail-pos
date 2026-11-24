<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'amount_paid')) {
                $table->decimal('amount_paid', 14, 2)->default(0)->after('total_amount');
            }
            if (! Schema::hasColumn('sales', 'change_amount')) {
                $table->decimal('change_amount', 14, 2)->default(0)->after('amount_paid');
            }
            if (! Schema::hasColumn('sales', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('sales', 'voided_by')) {
                $table->uuid('voided_by')->nullable()->after('voided_at');
            }
            if (! Schema::hasColumn('sales', 'void_reason')) {
                $table->text('void_reason')->nullable()->after('voided_by');
            }
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sale_id');
            $table->string('payment_method', 32); // CASH, QR_TRANSFER, CARD, CREDIT
            $table->decimal('amount_tendered', 14, 2);
            $table->decimal('amount_applied', 14, 2);
            $table->decimal('change_amount', 14, 2)->default(0);
            $table->string('reference_no', 128)->nullable();
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->index(['sale_id', 'payment_method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');

        Schema::table('sales', function (Blueprint $table) {
            $cols = ['void_reason', 'voided_by', 'voided_at', 'change_amount', 'amount_paid'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('sales', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};