<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sync_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('device_id', 100);
            $table->uuid('event_id');
            $table->string('event_type', 60);
            $table->string('status', 30);
            $table->jsonb('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'event_id']);
            $table->index(['organization_id', 'branch_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
};
