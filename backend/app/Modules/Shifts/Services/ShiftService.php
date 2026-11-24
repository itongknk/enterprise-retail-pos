<?php

namespace App\Modules\Shifts\Services;

use App\Modules\AuditTrail\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ShiftService
{
    public function __construct(
        private readonly AuditTrailService $auditTrail
    ) {}

    public function openShift(
        string $organizationId,
        string $branchId,
        float $openingCash,
        ?string $actorId = null,
        ?string $note = null
    ): array {
        if ($openingCash < 0) {
            throw new InvalidArgumentException('Opening cash must not be negative.');
        }

        return DB::transaction(function () use ($organizationId, $branchId, $openingCash, $actorId, $note) {
            $existingOpen = DB::table('pos_shifts')
                ->where('organization_id', $organizationId)
                ->where('branch_id', $branchId)
                ->when($actorId, fn ($q) => $q->where('opened_by', $actorId))
                ->where('status', 'OPEN')
                ->lockForUpdate()
                ->first();

            if ($existingOpen) {
                throw new RuntimeException('An open shift already exists for this branch and user.');
            }

            $shiftId = (string) Str::uuid();
            $shiftNumber = 'SHF-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));
            $openedAt = now();

            DB::table('pos_shifts')->insert([
                'id' => $shiftId,
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'shift_number' => $shiftNumber,
                'opened_by' => $actorId,
                'closed_by' => null,
                'status' => 'OPEN',
                'opening_cash' => round($openingCash, 2),
                'expected_cash' => null,
                'closing_cash_counted' => null,
                'cash_variance' => null,
                'opening_note' => $note,
                'closing_note' => null,
                'opened_at' => $openedAt,
                'closed_at' => null,
                'created_at' => $openedAt,
                'updated_at' => $openedAt,
            ]);

            $this->auditTrail->record(
                organizationId: $organizationId,
                action: 'shift.opened',
                entityType: 'pos_shift',
                entityId: $shiftId,
                beforeData: null,
                afterData: [
                    'shift_number' => $shiftNumber,
                    'branch_id' => $branchId,
                    'status' => 'OPEN',
                    'opening_cash' => round($openingCash, 2),
                ],
                branchId: $branchId,
                actorId: $actorId,
                ipAddress: request()?->ip(),
                userAgent: request()?->userAgent()
            );

            return $this->getShiftSummary($shiftId);
        });
    }

    public function getShiftSummary(string $shiftId): array
    {
        $shift = DB::table('pos_shifts')->where('id', $shiftId)->first();
        if (! $shift) {
            throw new InvalidArgumentException('Shift not found.');
        }

        $salesQuery = DB::table('sales')
            ->where('organization_id', $shift->organization_id);

        if (Schema::hasColumn('sales', 'shift_id')) {
            $salesQuery->where('shift_id', $shift->id);
        } else {
            $salesQuery->where('branch_id', $shift->branch_id)
                ->where('created_at', '>=', $shift->opened_at)
                ->when($shift->closed_at, fn ($q) => $q->where('created_at', '<=', $shift->closed_at));
        }

        $sales = $salesQuery->get();
        $voidStatuses = ['VOID', 'VOIDED'];
        $completedSales = $sales->reject(fn ($s) => in_array(strtoupper((string) ($s->status ?? '')), $voidStatuses, true));
        $voidedSales = $sales->filter(fn ($s) => in_array(strtoupper((string) ($s->status ?? '')), $voidStatuses, true));

        $grossSalesAmount = round((float) $completedSales->sum('total_amount'), 2);
        $voidedSalesAmount = round((float) $voidedSales->sum('total_amount'), 2);

        $cashReceived = 0.0;
        $cashChangeGiven = 0.0;

        if ($sales->count() > 0 && Schema::hasTable('sale_payments')) {
            $completedIds = $completedSales->pluck('id')->all();
            if (count($completedIds) > 0) {
                $payments = DB::table('sale_payments')
                    ->whereIn('sale_id', $completedIds)
                    ->get();

                foreach ($payments as $pay) {
                    $method = strtoupper((string) ($pay->payment_method ?? ''));
                    if ($method === 'CASH') {
                        $tendered = (float) ($pay->amount_tendered ?? 0);
                        $change = (float) ($pay->change_amount ?? 0);
                        $applied = $pay->amount_applied ?? ($pay->amount ?? max(0.0, $tendered - $change));
                        $cashReceived += (float) $applied;
                        $cashChangeGiven += $change;
                    }
                }
            }
        }

        $expectedCash = round(
            (float) $shift->opening_cash + $cashReceived,
            2
        );

        return [
            'shift' => $shift,
            'reconciliation' => [
                'opening_cash' => round((float) $shift->opening_cash, 2),
                'completed_sales_count' => $completedSales->count(),
                'voided_sales_count' => $voidedSales->count(),
                'gross_sales_amount' => $grossSalesAmount,
                'voided_sales_amount' => $voidedSalesAmount,
                'cash_received' => round($cashReceived, 2),
                'cash_change_given' => round($cashChangeGiven, 2),
                'expected_cash' => $shift->expected_cash !== null ? round((float) $shift->expected_cash, 2) : $expectedCash,
                'closing_cash_counted' => $shift->closing_cash_counted !== null ? round((float) $shift->closing_cash_counted, 2) : null,
                'cash_variance' => $shift->cash_variance !== null ? round((float) $shift->cash_variance, 2) : null,
            ],
        ];
    }

    public function closeShift(
        string $shiftId,
        float $closingCashCounted,
        ?string $actorId = null,
        ?string $note = null
    ): array {
        if ($closingCashCounted < 0) {
            throw new InvalidArgumentException('Closing cash counted must not be negative.');
        }

        return DB::transaction(function () use ($shiftId, $closingCashCounted, $actorId, $note) {
            $shift = DB::table('pos_shifts')
                ->where('id', $shiftId)
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                throw new InvalidArgumentException('Shift not found.');
            }

            if ($shift->status !== 'OPEN') {
                throw new RuntimeException("Cannot close shift in status {$shift->status}.");
            }

            $summary = $this->getShiftSummary($shiftId);
            $expectedCash = (float) $summary['reconciliation']['expected_cash'];
            $countedRounded = round($closingCashCounted, 2);
            $variance = round($countedRounded - $expectedCash, 2);
            $closedAt = now();

            DB::table('pos_shifts')
                ->where('id', $shiftId)
                ->update([
                    'status' => 'CLOSED',
                    'closed_by' => $actorId,
                    'expected_cash' => $expectedCash,
                    'closing_cash_counted' => $countedRounded,
                    'cash_variance' => $variance,
                    'closing_note' => $note,
                    'closed_at' => $closedAt,
                    'updated_at' => $closedAt,
                ]);

            $this->auditTrail->record(
                organizationId: (string) $shift->organization_id,
                action: 'shift.closed',
                entityType: 'pos_shift',
                entityId: (string) $shift->id,
                beforeData: ['status' => 'OPEN'],
                afterData: [
                    'shift_number' => $shift->shift_number,
                    'status' => 'CLOSED',
                    'expected_cash' => $expectedCash,
                    'closing_cash_counted' => $countedRounded,
                    'cash_variance' => $variance,
                ],
                branchId: (string) $shift->branch_id,
                actorId: $actorId,
                ipAddress: request()?->ip(),
                userAgent: request()?->userAgent()
            );

            return $this->getShiftSummary($shiftId);
        });
    }
}