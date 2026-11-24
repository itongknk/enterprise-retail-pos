<?php

namespace App\Modules\Outbox\Console;

use App\Modules\AuditTrail\Services\AuditTrailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessOutboxEventsCommand extends Command
{
    protected $signature = 'outbox:process {--limit=50 : Maximum number of pending outbox events to process}';

    protected $description = 'Process pending outbox events';

    public function handle(AuditTrailService $auditTrail): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $processedCount = 0;

        $events = DB::table('outbox_events')
            ->where('status', 'PENDING')
            ->where('available_at', '<=', now())
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            $claimed = DB::table('outbox_events')
                ->where('id', $event->id)
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'PROCESSING',
                ]);

            if ($claimed === 0) {
                continue;
            }

            try {
                $this->dispatchEvent($event);

                DB::table('outbox_events')
                    ->where('id', $event->id)
                    ->update([
                        'status' => 'PROCESSED',
                        'processed_at' => now(),
                        'last_error' => null,
                    ]);

                $processedCount++;

                if (! empty($event->organization_id)) {
                    $auditTrail->record(
                        organizationId: (string) $event->organization_id,
                        action: 'outbox.event_processed',
                        entityType: 'outbox_event',
                        entityId: (string) $event->id,
                        beforeData: [
                            'status' => 'PENDING',
                            'event_type' => $event->event_type,
                        ],
                        afterData: [
                            'status' => 'PROCESSED',
                            'event_type' => $event->event_type,
                            'aggregate_type' => $event->aggregate_type,
                            'aggregate_id' => $event->aggregate_id,
                        ],
                    );
                }
            } catch (Throwable $e) {
                $nextRetry = (int) $event->retry_count + 1;
                $maxRetries = 5;
                $nextStatus = $nextRetry >= $maxRetries ? 'FAILED' : 'PENDING';

                DB::table('outbox_events')
                    ->where('id', $event->id)
                    ->update([
                        'status' => $nextStatus,
                        'retry_count' => $nextRetry,
                        'last_error' => mb_substr($e->getMessage(), 0, 2000),
                        'available_at' => now()->addSeconds(min(300, $nextRetry * 10)),
                    ]);

                if (! empty($event->organization_id)) {
                    $auditTrail->record(
                        organizationId: (string) $event->organization_id,
                        action: 'outbox.event_failed',
                        entityType: 'outbox_event',
                        entityId: (string) $event->id,
                        beforeData: [
                            'status' => 'PROCESSING',
                            'retry_count' => (int) $event->retry_count,
                        ],
                        afterData: [
                            'status' => $nextStatus,
                            'retry_count' => $nextRetry,
                            'event_type' => $event->event_type,
                            'last_error' => mb_substr($e->getMessage(), 0, 500),
                        ],
                    );
                }
            }
        }

        $this->info("Processed {$processedCount} outbox events.");

        return self::SUCCESS;
    }

    protected function dispatchEvent(object $event): void
    {
        $payload = is_string($event->payload)
            ? json_decode($event->payload, true)
            : (array) $event->payload;

        // Hook for downstream event handlers / projections / webhooks
        match ((string) $event->event_type) {
            'inventory.stock_adjusted' => null,
            default => null,
        };

        unset($payload);
    }
}