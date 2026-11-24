<?php

namespace App\Modules\Outbox\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboxPublisher
{
    public function publish(
        string $organizationId,
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload
    ): string {
        $eventId = (string) Str::uuid();

        DB::table('outbox_events')->insert([
            'id' => $eventId,
            'organization_id' => $organizationId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'status' => 'PENDING',
            'retry_count' => 0,
            'available_at' => now(),
            'created_at' => now(),
        ]);

        return $eventId;
    }
}