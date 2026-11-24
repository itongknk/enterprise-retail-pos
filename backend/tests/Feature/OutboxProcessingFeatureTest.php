<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxProcessingFeatureTest extends TestCase
{
    public function test_outbox_process_marks_ready_pending_events_processed_and_skips_future_events(): void
    {
        $org = DB::table('organizations')->first();
        if (!$org) {
            $this->seed(\Database\Seeders\TestDatabaseSeeder::class);
            $org = DB::table('organizations')->first();
        }
        $this->assertNotNull($org);

        $readyId = (string) Str::uuid();
        $futureId = (string) Str::uuid();

        try {
            DB::table('outbox_events')->insert([
                [
                    'id' => $readyId,
                    'organization_id' => $org->id,
                    'aggregate_type' => 'inventory_balance',
                    'aggregate_id' => (string) Str::uuid(),
                    'event_type' => 'inventory.stock_adjusted',
                    'payload' => json_encode(['test' => 'ready']),
                    'status' => 'PENDING',
                    'retry_count' => 0,
                    'available_at' => now()->subSeconds(5),
                    'processed_at' => null,
                    'last_error' => null,
                    'created_at' => now(),
                ],
                [
                    'id' => $futureId,
                    'organization_id' => $org->id,
                    'aggregate_type' => 'inventory_balance',
                    'aggregate_id' => (string) Str::uuid(),
                    'event_type' => 'inventory.stock_adjusted',
                    'payload' => json_encode(['test' => 'future']),
                    'status' => 'PENDING',
                    'retry_count' => 0,
                    'available_at' => now()->addMinutes(10),
                    'processed_at' => null,
                    'last_error' => null,
                    'created_at' => now(),
                ],
            ]);

            $exitCode = Artisan::call('outbox:process', ['--limit' => 20]);
            $this->assertSame(0, $exitCode);

            $readyRow = DB::table('outbox_events')->where('id', $readyId)->first();
            $futureRow = DB::table('outbox_events')->where('id', $futureId)->first();

            $this->assertNotNull($readyRow);
            $this->assertSame('PROCESSED', $readyRow->status);
            $this->assertNotNull($readyRow->processed_at);

            $this->assertNotNull($futureRow);
            $this->assertSame('PENDING', $futureRow->status);
            $this->assertNull($futureRow->processed_at);
        } finally {
            DB::table('outbox_events')->whereIn('id', [$readyId, $futureId])->delete();
        }
    }

    public function test_outbox_process_handles_event_with_prior_retries_and_respects_limit(): void
    {
        $org = DB::table('organizations')->first();
        if (!$org) {
            $this->seed(\Database\Seeders\TestDatabaseSeeder::class);
            $org = DB::table('organizations')->first();
        }
        $this->assertNotNull($org);

        $event1Id = (string) Str::uuid();
        $event2Id = (string) Str::uuid();

        try {
            DB::table('outbox_events')->insert([
                [
                    'id' => $event1Id,
                    'organization_id' => $org->id,
                    'aggregate_type' => 'sales',
                    'aggregate_id' => (string) Str::uuid(),
                    'event_type' => 'sales.sale_created',
                    'payload' => json_encode(['seq' => 1, 'retried' => true]),
                    'status' => 'PENDING',
                    'retry_count' => 4,
                    'available_at' => now()->subSeconds(10),
                    'processed_at' => null,
                    'last_error' => 'Previous transient error',
                    'created_at' => now()->subSeconds(10),
                ],
                [
                    'id' => $event2Id,
                    'organization_id' => $org->id,
                    'aggregate_type' => 'sales',
                    'aggregate_id' => (string) Str::uuid(),
                    'event_type' => 'sales.sale_created',
                    'payload' => json_encode(['seq' => 2]),
                    'status' => 'PENDING',
                    'retry_count' => 0,
                    'available_at' => now()->subSeconds(5),
                    'processed_at' => null,
                    'last_error' => null,
                    'created_at' => now()->subSeconds(5),
                ],
            ]);

            $exitCode = Artisan::call('outbox:process', ['--limit' => 1]);
            $this->assertSame(0, $exitCode);

            $row1 = DB::table('outbox_events')->where('id', $event1Id)->first();
            $row2 = DB::table('outbox_events')->where('id', $event2Id)->first();

            $this->assertSame('PROCESSED', $row1->status);
            $this->assertNotNull($row1->processed_at);
            $this->assertNull($row1->last_error);

            $this->assertSame('PENDING', $row2->status);
            $this->assertNull($row2->processed_at);
        } finally {
            DB::table('outbox_events')->whereIn('id', [$event1Id, $event2Id])->delete();
        }
    }
}