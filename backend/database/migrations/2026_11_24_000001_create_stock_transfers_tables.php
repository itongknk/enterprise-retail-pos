<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('transfer_number', 50)->unique();
            $table->uuid('source_branch_id');
            $table->uuid('destination_branch_id');
            $table->string('status', 30)->default('DRAFT'); // DRAFT, SHIPPED, RECEIVED, CANCELLED
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('shipped_by')->nullable();
            $table->uuid('received_by')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'source_branch_id']);
            $table->index(['organization_id', 'destination_branch_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_transfer_id');
            $table->uuid('product_id');
            $table->decimal('quantity_requested', 14, 3);
            $table->decimal('quantity_shipped', 14, 3)->default(0);
            $table->decimal('quantity_received', 14, 3)->default(0);
            $table->timestamps();

            $table->foreign('stock_transfer_id')->references('id')->on('stock_transfers')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['stock_transfer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};