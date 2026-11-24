<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('tax_id', 20)->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, SUSPENDED
            $table->timestampsTz();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('timezone', 50)->default('Asia/Bangkok');
            $table->boolean('is_headquarters')->default(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('type', 30)->default('RETAIL'); // RETAIL, WHOLESALE, MAIN
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'branch_id']);
        });

        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('terminal_code', 30);
            $table->string('device_fingerprint', 255)->unique();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'terminal_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_terminals');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('organizations');
    }
};