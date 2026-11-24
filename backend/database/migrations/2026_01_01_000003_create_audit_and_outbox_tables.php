<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('branch_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->string('entity_id', 100);
            $table->jsonb('before_data')->nullable();
            $table->jsonb('after_data')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->char('prev_hash', 64);
            $table->char('record_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'entity_type', 'entity_id']);
        });

        // ป้องกันการแก้ไขหรือลบข้อมูลใน audit_logs ระดับ Database Trigger
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_audit_log_modification()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs table is append-only; UPDATE and DELETE are prohibited';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_audit_logs_immutable
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_modification();
        ");

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('aggregate_type', 100);
            $table->uuid('aggregate_id');
            $table->string('event_type', 120);
            $table->jsonb('payload');
            $table->string('status', 20)->default('PENDING'); // PENDING, PROCESSING, PROCESSED, FAILED
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['status', 'available_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS trg_audit_logs_immutable ON audit_logs;");
        DB::unprepared("DROP FUNCTION IF EXISTS prevent_audit_log_modification();");
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('audit_logs');
    }
};