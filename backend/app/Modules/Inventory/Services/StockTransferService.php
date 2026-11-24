<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AuditTrail\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class StockTransferService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly AuditTrailService $auditTrail
    ) {}

    public function createTransfer(
        string $organizationId,
        string $sourceBranchId,
        string $destinationBranchId,
        array $items,
        ?string $actorId = null,
        ?string $note = null
    ): array {
        if ($sourceBranchId === $destinationBranchId) {
            throw new InvalidArgumentException('Source and destination branches must be different.');
        }

        if (count($items) === 0) {
            throw new InvalidArgumentException('At least one transfer item is required.');
        }

        return DB::transaction(function () use (
            $organizationId,
            $sourceBranchId,
            $destinationBranchId,
            $items,
            $actorId,
            $note
        ) {
            $transferId = (string) Str::uuid();
            $transferNumber = 'TRF-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));
            $now = now();

            DB::table('stock_transfers')->insert([
                'id' => $transferId,
                'organization_id' => $organizationId,
                'transfer_number' => $transferNumber,
                'source_branch_id' => $sourceBranchId,
                'destination_branch_id' => $destinationBranchId,
                'status' => 'DRAFT',
                'note' => $note,
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $insertedItems = [];
            foreach ($items as $item) {
                $qty = (float) ($item['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException('Transfer item quantity must be greater than zero.');
                }

                $row = [
                    'id' => (string) Str::uuid(),
                    'stock_transfer_id' => $transferId,
                    'product_id' => (string) $item['product_id'],
                    'quantity_requested' => $qty,
                    'quantity_shipped' => 0,
                    'quantity_received' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                DB::table('stock_transfer_items')->insert($row);
                $insertedItems[] = $row;
            }

            $this->auditTrail->record(
                organizationId: $organizationId,
                action: 'inventory.transfer_created',
                entityType: 'stock_transfer',
                entityId: $transferId,
                beforeData: null,
                afterData: [
                    'transfer_number' => $transferNumber,
                    'source_branch_id' => $sourceBranchId,
                    'destination_branch_id' => $destinationBranchId,
                    'status' => 'DRAFT',
                    'items_count' => count($insertedItems),
                ],
                branchId: $sourceBranchId,
                actorId: $actorId,
                ipAddress: request()?->ip(),
                userAgent: request()?->userAgent()
            );

            return [
                'transfer' => DB::table('stock_transfers')->where('id', $transferId)->first(),
                'items' => $insertedItems,
            ];
        });
    }

    public function shipTransfer(string $transferId, ?string $actorId = null): array
    {
        return DB::transaction(function () use ($transferId, $actorId) {
            $transfer = DB::table('stock_transfers')
                ->where('id', $transferId)
                ->lockForUpdate()
                ->first();

            if (! $transfer) {
                throw new InvalidArgumentException('Stock transfer not found.');
            }

            if ($transfer->status !== 'DRAFT') {
                throw new RuntimeException("Cannot ship transfer in status {$transfer->status}.");
            }

            $items = DB::table('stock_transfer_items')
                ->where('stock_transfer_id', $transferId)
                ->get();

            foreach ($items as $item) {
                $qty = (float) $item->quantity_requested;
                $this->inventoryService->adjustStock(
                    organizationId: (string) $transfer->organization_id,
                    branchId: (string) $transfer->source_branch_id,
                    productId: (string) $item->product_id,
                    documentType: 'TRANSFER_OUT',
                    quantityDelta: -$qty,
                    actorId: $actorId,
                    note: "Ship transfer {$transfer->transfer_number}",
                    referenceType: 'STOCK_TRANSFER',
                    referenceId: (string) $transfer->id
                );

                DB::table('stock_transfer_items')
                    ->where('id', $item->id)
                    ->update([
                        'quantity_shipped' => $qty,
                        'updated_at' => now(),
                    ]);
            }

            $shippedAt = now();
            DB::table('stock_transfers')
                ->where('id', $transferId)
                ->update([
                    'status' => 'SHIPPED',
                    'shipped_by' => $actorId,
                    'shipped_at' => $shippedAt,
                    'updated_at' => $shippedAt,
                ]);

            $updated = DB::table('stock_transfers')->where('id', $transferId)->first();

            $this->auditTrail->record(
                organizationId: (string) $transfer->organization_id,
                action: 'inventory.transfer_shipped',
                entityType: 'stock_transfer',
                entityId: (string) $transfer->id,
                beforeData: ['status' => 'DRAFT'],
                afterData: [
                    'transfer_number' => $transfer->transfer_number,
                    'status' => 'SHIPPED',
                    'source_branch_id' => $transfer->source_branch_id,
                ],
                branchId: (string) $transfer->source_branch_id,
                actorId: $actorId,
                ipAddress: request()?->ip(),
                userAgent: request()?->userAgent()
            );

            return [
                'transfer' => $updated,
                'items' => DB::table('stock_transfer_items')->where('stock_transfer_id', $transferId)->get(),
            ];
        });
    }

    public function receiveTransfer(string $transferId, ?string $actorId = null): array
    {
        return DB::transaction(function () use ($transferId, $actorId) {
            $transfer = DB::table('stock_transfers')
                ->where('id', $transferId)
                ->lockForUpdate()
                ->first();

            if (! $transfer) {
                throw new InvalidArgumentException('Stock transfer not found.');
            }

            if ($transfer->status !== 'SHIPPED') {
                throw new RuntimeException("Cannot receive transfer in status {$transfer->status}.");
            }

            $items = DB::table('stock_transfer_items')
                ->where('stock_transfer_id', $transferId)
                ->get();

            foreach ($items as $item) {
                $qty = (float) ($item->quantity_shipped > 0 ? $item->quantity_shipped : $item->quantity_requested);
                $this->inventoryService->adjustStock(
                    organizationId: (string) $transfer->organization_id,
                    branchId: (string) $transfer->destination_branch_id,
                    productId: (string) $item->product_id,
                    documentType: 'TRANSFER_IN',
                    quantityDelta: $qty,
                    actorId: $actorId,
                    note: "Receive transfer {$transfer->transfer_number}",
                    referenceType: 'STOCK_TRANSFER',
                    referenceId: (string) $transfer->id
                );

                DB::table('stock_transfer_items')
                    ->where('id', $item->id)
                    ->update([
                        'quantity_received' => $qty,
                        'updated_at' => now(),
                    ]);
            }

            $receivedAt = now();
            DB::table('stock_transfers')
                ->where('id', $transferId)
                ->update([
                    'status' => 'RECEIVED',
                    'received_by' => $actorId,
                    'received_at' => $receivedAt,
                    'updated_at' => $receivedAt,
                ]);

            $updated = DB::table('stock_transfers')->where('id', $transferId)->first();

            $this->auditTrail->record(
                organizationId: (string) $transfer->organization_id,
                action: 'inventory.transfer_received',
                entityType: 'stock_transfer',
                entityId: (string) $transfer->id,
                beforeData: ['status' => 'SHIPPED'],
                afterData: [
                    'transfer_number' => $transfer->transfer_number,
                    'status' => 'RECEIVED',
                    'destination_branch_id' => $transfer->destination_branch_id,
                ],
                branchId: (string) $transfer->destination_branch_id,
                actorId: $actorId,
                ipAddress: request()?->ip(),
                userAgent: request()?->userAgent()
            );

            return [
                'transfer' => $updated,
                'items' => DB::table('stock_transfer_items')->where('stock_transfer_id', $transferId)->get(),
            ];
        });
    }
}