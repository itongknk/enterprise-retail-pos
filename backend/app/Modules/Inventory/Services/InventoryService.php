<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AuditTrail\Services\AuditTrailService;
use App\Modules\Inventory\Models\InventoryBalance;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InventoryService
{
    public function __construct(
        private readonly AuditTrailService $auditTrail
    ) {}

    public function adjustStock(
        string $organizationId,
        string $branchId,
        string $productId,
        string $documentType,
        float $quantityDelta,
        ?string $actorId = null,
        ?string $note = null,
        ?string $referenceType = null,
        ?string $referenceId = null
    ): array {
        if ($quantityDelta == 0.0) {
            throw new InvalidArgumentException('quantity_delta must not be zero.');
        }

        return DB::transaction(function () use (
            $organizationId,
            $branchId,
            $productId,
            $documentType,
            $quantityDelta,
            $actorId,
            $note,
            $referenceType,
            $referenceId
        ) {
            $balance = InventoryBalance::where('organization_id', $organizationId)
                ->where('branch_id', $branchId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                $balance = InventoryBalance::create([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'quantity_on_hand' => 0,
                    'reserved_quantity' => 0,
                    'reorder_level' => 0,
                ]);
                $balance = InventoryBalance::where('id', $balance->id)
                    ->lockForUpdate()
                    ->first();
            }

            $currentQty = (float) $balance->quantity_on_hand;
            $newQty = round($currentQty + $quantityDelta, 4);

            if ($newQty < 0) {
                throw new InvalidArgumentException('Insufficient stock quantity on hand.');
            }

            $balance->update([
                'quantity_on_hand' => $newQty,
            ]);

            $movement = StockMovement::create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'product_id' => $productId,
                'document_type' => $documentType,
                'movement_type' => $quantityDelta >= 0 ? 'IN' : 'OUT',
                'quantity_delta' => $quantityDelta,
                'balance_after' => $newQty,
                'reference_type' => $referenceType ?? 'MANUAL_ADJUST',
                'reference_id' => $referenceId,
                'note' => $note,
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            if (DB::getSchemaBuilder()->hasTable('outbox_events')) {
                DB::table('outbox_events')->insert([
                    'id' => (string) Str::uuid(),
                    'organization_id' => $organizationId,
                    'aggregate_type' => 'inventory_balance',
                    'aggregate_id' => $balance->id,
                    'event_type' => 'inventory.stock_adjusted',
                    'payload' => json_encode([
                        'organization_id' => $organizationId,
                        'branch_id' => $branchId,
                        'product_id' => $productId,
                        'movement_id' => $movement->id,
                        'document_type' => $documentType,
                        'quantity_delta' => $quantityDelta,
                        'balance_after' => $newQty,
                    ], JSON_UNESCAPED_UNICODE),
                    'status' => 'PENDING',
                    'retry_count' => 0,
                    'available_at' => now(),
                    'created_at' => now(),
                ]);
            }

            $this->auditTrail->record(
                organizationId: $organizationId,
                action: 'inventory.stock_adjusted',
                entityType: 'stock_movement',
                entityId: (string) $movement->id,
                beforeData: [
                    'product_id' => $productId,
                    'quantity_on_hand' => $currentQty,
                ],
                afterData: [
                    'product_id' => $productId,
                    'movement_id' => $movement->id,
                    'document_type' => $documentType,
                    'quantity_delta' => $quantityDelta,
                    'quantity_on_hand' => $newQty,
                ],
                branchId: $branchId,
                actorId: $actorId,
                ipAddress: request()?->ip(),
                userAgent: request()?->userAgent()
            );

            return [
                'balance' => $balance->fresh(),
                'movement' => $movement,
            ];
        });
    }
}
