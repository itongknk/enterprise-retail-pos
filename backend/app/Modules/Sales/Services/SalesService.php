<?php

namespace App\Modules\Sales\Services;

use App\Modules\AuditTrail\Services\AuditTrailService;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SalesService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly AuditTrailService $auditTrail
    ) {}

    /**
     * @param  array<int, array{product_id: string, quantity: float|int|string, unit_price?: float|int|string|null}>  $items
     * @param  array<int, array{payment_method: string, amount_tendered: float|int|string, reference_no?: string|null}>  $payments
     */
    public function createSale(
        string $organizationId,
        string $branchId,
        array $items,
        float $discountAmount = 0.0,
        array $payments = [],
        ?string $actorId = null,
        ?string $note = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $shiftId = null
    ): array {
        if (count($items) === 0) {
            throw new InvalidArgumentException('Sale must contain at least one item.');
        }

        if ($discountAmount < 0) {
            throw new InvalidArgumentException('Discount amount must not be negative.');
        }

        return DB::transaction(function () use (
            $organizationId,
            $branchId,
            $items,
            $discountAmount,
            $payments,
            $actorId,
            $note,
            $ipAddress,
            $userAgent,
            $shiftId
        ) {
            $resolvedShiftId = null;
            if (Schema::hasColumn('sales', 'shift_id') && Schema::hasTable('pos_shifts')) {
                if ($shiftId !== null && $shiftId !== '') {
                    $explicitShift = DB::table('pos_shifts')
                        ->where('id', $shiftId)
                        ->where('organization_id', $organizationId)
                        ->where('branch_id', $branchId)
                        ->first();
                    if (! $explicitShift) {
                        throw new InvalidArgumentException("Shift not found in branch: {$shiftId}");
                    }
                    if ($explicitShift->status !== 'OPEN') {
                        throw new InvalidArgumentException("Shift is not open: {$shiftId}");
                    }
                    $resolvedShiftId = (string) $explicitShift->id;
                } else {
                    $openShift = DB::table('pos_shifts')
                        ->where('organization_id', $organizationId)
                        ->where('branch_id', $branchId)
                        ->where('status', 'OPEN')
                        ->when($actorId, fn ($q) => $q->where('opened_by', $actorId))
                        ->orderByDesc('opened_at')
                        ->first();

                    if (! $openShift && $actorId) {
                        $openShift = DB::table('pos_shifts')
                            ->where('organization_id', $organizationId)
                            ->where('branch_id', $branchId)
                            ->where('status', 'OPEN')
                            ->orderByDesc('opened_at')
                            ->first();
                    }

                    if ($openShift) {
                        $resolvedShiftId = (string) $openShift->id;
                    }
                }
            }

            $saleId = (string) Str::uuid();
            $saleNumber = 'SALE-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(5));

            $subtotal = 0.0;
            $preparedItems = [];

            foreach ($items as $item) {
                $productId = (string) ($item['product_id'] ?? '');
                $qty = (float) ($item['quantity'] ?? 0);

                if ($productId === '' || $qty <= 0) {
                    throw new InvalidArgumentException('Each sale item requires a valid product_id and positive quantity.');
                }

                $product = DB::table('products')
                    ->where('id', $productId)
                    ->where('organization_id', $organizationId)
                    ->first();

                if (! $product) {
                    throw new InvalidArgumentException("Product not found in organization: {$productId}");
                }

                $unitPrice = array_key_exists('unit_price', $item) && $item['unit_price'] !== null
                    ? (float) $item['unit_price']
                    : (float) ($product->unit_price ?? 0);

                if ($unitPrice < 0) {
                    throw new InvalidArgumentException('Unit price must not be negative.');
                }

                $lineTotal = round($qty * $unitPrice, 2);
                $subtotal = round($subtotal + $lineTotal, 2);

                $preparedItems[] = [
                    'id' => (string) Str::uuid(),
                    'sale_id' => $saleId,
                    'product_id' => $productId,
                    'quantity' => round($qty, 4),
                    'unit_price' => round($unitPrice, 2),
                    'line_total' => $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $totalAmount = round(max(0.0, $subtotal - $discountAmount), 2);

            if (count($payments) === 0) {
                $payments = [
                    ['payment_method' => 'CASH', 'amount_tendered' => $totalAmount],
                ];
            }

            $remainingDue = $totalAmount;
            $totalTendered = 0.0;
            $totalChange = 0.0;
            $preparedPayments = [];

            foreach ($payments as $pay) {
                $method = strtoupper(trim((string) ($pay['payment_method'] ?? 'CASH')));
                $tendered = round((float) ($pay['amount_tendered'] ?? 0), 2);

                if ($tendered < 0) {
                    throw new InvalidArgumentException('Payment amount must not be negative.');
                }

                $applied = min($remainingDue, $tendered);
                $change = round(max(0.0, $tendered - $applied), 2);
                $remainingDue = round(max(0.0, $remainingDue - $applied), 2);

                $totalTendered = round($totalTendered + $tendered, 2);
                $totalChange = round($totalChange + $change, 2);

                $preparedPayments[] = [
                    'id' => (string) Str::uuid(),
                    'sale_id' => $saleId,
                    'payment_method' => $method,
                    'amount_tendered' => $tendered,
                    'amount_applied' => $applied,
                    'change_amount' => $change,
                    'reference_no' => $pay['reference_no'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($remainingDue > 0) {
                throw new InvalidArgumentException("Insufficient payment amount. Remaining due: {$remainingDue}");
            }

            $saleInsert = [
                'id' => $saleId,
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'sale_number' => $saleNumber,
                'subtotal' => $subtotal,
                'discount_amount' => round($discountAmount, 2),
                'total_amount' => $totalAmount,
                'amount_paid' => $totalTendered,
                'change_amount' => $totalChange,
                'status' => 'COMPLETED',
                'note' => $note,
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('sales', 'shift_id')) {
                $saleInsert['shift_id'] = $resolvedShiftId;
            }

            DB::table('sales')->insert($saleInsert);

            DB::table('sale_items')->insert($preparedItems);
            DB::table('sale_payments')->insert($preparedPayments);

            $stockResults = [];
            foreach ($preparedItems as $line) {
                $adj = $this->inventoryService->adjustStock(
                    organizationId: $organizationId,
                    branchId: $branchId,
                    productId: $line['product_id'],
                    documentType: 'SALE_OUT',
                    quantityDelta: -abs((float) $line['quantity']),
                    actorId: $actorId,
                    note: $note ?? "Sale {$saleNumber}",
                    referenceType: 'SALE',
                    referenceId: $saleId
                );
                $stockResults[] = [
                    'product_id' => $line['product_id'],
                    'quantity_delta' => -abs((float) $line['quantity']),
                    'balance_after' => (float) $adj['balance']->quantity_on_hand,
                    'movement_id' => $adj['movement']->id,
                ];
            }

            $saleData = [
                'id' => $saleId,
                'shift_id' => $resolvedShiftId,
                'sale_number' => $saleNumber,
                'subtotal' => $subtotal,
                'discount_amount' => round($discountAmount, 2),
                'total_amount' => $totalAmount,
                'amount_paid' => $totalTendered,
                'change_amount' => $totalChange,
                'status' => 'COMPLETED',
                'items_count' => count($preparedItems),
            ];

            $this->auditTrail->record(
                organizationId: $organizationId,
                action: 'sales.sale_created',
                entityType: 'sale',
                entityId: $saleId,
                beforeData: null,
                afterData: [
                    'sale' => $saleData,
                    'items' => $preparedItems,
                    'payments' => $preparedPayments,
                    'stock_movements' => $stockResults,
                ],
                branchId: $branchId,
                actorId: $actorId,
                ipAddress: $ipAddress,
                userAgent: $userAgent
            );

            return [
                'sale' => $saleData,
                'items' => $preparedItems,
                'payments' => $preparedPayments,
                'stock_movements' => $stockResults,
            ];
        });
    }

    public function voidSale(
        string $saleId,
        string $reason,
        ?string $actorId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Void reason is required.');
        }

        return DB::transaction(function () use ($saleId, $reason, $actorId, $ipAddress, $userAgent) {
            $sale = DB::table('sales')->where('id', $saleId)->lockForUpdate()->first();

            if (! $sale) {
                throw new InvalidArgumentException("Sale not found: {$saleId}");
            }

            if ($sale->status === 'VOIDED') {
                throw new InvalidArgumentException("Sale has already been voided: {$saleId}");
            }

            $items = DB::table('sale_items')->where('sale_id', $saleId)->get();
            $voidedAt = now();

            DB::table('sales')->where('id', $saleId)->update([
                'status' => 'VOIDED',
                'voided_at' => $voidedAt,
                'voided_by' => $actorId,
                'void_reason' => $reason,
                'updated_at' => $voidedAt,
            ]);

            $stockRestores = [];
            foreach ($items as $item) {
                $adj = $this->inventoryService->adjustStock(
                    organizationId: (string) $sale->organization_id,
                    branchId: (string) $sale->branch_id,
                    productId: (string) $item->product_id,
                    documentType: 'VOID_IN',
                    quantityDelta: abs((float) $item->quantity),
                    actorId: $actorId,
                    note: "Void Sale {$sale->sale_number}: {$reason}",
                    referenceType: 'SALE_VOID',
                    referenceId: $saleId
                );
                $stockRestores[] = [
                    'product_id' => $item->product_id,
                    'quantity_restored' => abs((float) $item->quantity),
                    'balance_after' => (float) $adj['balance']->quantity_on_hand,
                    'movement_id' => $adj['movement']->id,
                ];
            }

            $this->auditTrail->record(
                organizationId: (string) $sale->organization_id,
                action: 'sales.sale_voided',
                entityType: 'sale',
                entityId: $saleId,
                beforeData: ['status' => $sale->status],
                afterData: [
                    'status' => 'VOIDED',
                    'voided_at' => $voidedAt->toIso8601String(),
                    'void_reason' => $reason,
                    'stock_restores' => $stockRestores,
                ],
                branchId: (string) $sale->branch_id,
                actorId: $actorId,
                ipAddress: $ipAddress,
                userAgent: $userAgent
            );

            return [
                'sale_id' => $saleId,
                'sale_number' => $sale->sale_number,
                'status' => 'VOIDED',
                'voided_at' => $voidedAt->toIso8601String(),
                'void_reason' => $reason,
                'stock_restores' => $stockRestores,
            ];
        });
    }
}