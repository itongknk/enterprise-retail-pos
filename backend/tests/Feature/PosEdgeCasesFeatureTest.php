<?php

namespace Tests\Feature;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosEdgeCasesFeatureTest extends TestCase
{
    public function test_transfer_state_guards_duplicate_open_shift_guard_and_void_cash_reconciliation(): void
    {
        $org = DB::table('organizations')->first();
        if (!$org) {
            $this->seed(\Database\Seeders\TestDatabaseSeeder::class);
            $org = DB::table('organizations')->first();
        }
        $this->assertNotNull($org);

        $branches = DB::table('branches')->where('organization_id', $org->id)->get();
        $srcBranch = $branches->first();
        $dstBranch = $branches->skip(1)->first();
        $createdTempBranch = false;

        if (! $srcBranch) {
            $srcId = (string) Str::uuid();
            DB::table('branches')->insert([
                'id' => $srcId,
                'organization_id' => $org->id,
                'code' => 'BR-EDGE-SRC',
                'name' => 'Edge Source Branch',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $srcBranch = DB::table('branches')->where('id', $srcId)->first();
        }

        if (! $dstBranch) {
            $dstId = (string) Str::uuid();
            DB::table('branches')->insert([
                'id' => $dstId,
                'organization_id' => $org->id,
                'code' => 'BR-EDGE-DST',
                'name' => 'Edge Dest Branch',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $dstBranch = DB::table('branches')->where('id', $dstId)->first();
            $createdTempBranch = true;
        }

        $userModel = User::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('status', 'ACTIVE')
            ->first() ?? User::withoutGlobalScope('organization')->where('organization_id', $org->id)->first();
        $this->assertNotNull($userModel);

        $pair = app(TokenService::class)->issueTokenPair($userModel);
        $token = $pair['access_token'] ?? $pair['plain_access_token'] ?? $pair['token'] ?? null;
        $this->assertNotEmpty($token);

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'X-Branch-Id' => (string) $srcBranch->id,
        ];

        $catId = (string) Str::uuid();
        $prodId = (string) Str::uuid();
        $shiftId = null;
        $saleVoidId = null;
        $saleKeepId = null;

        try {
            DB::table('categories')->insert([
                'id' => $catId,
                'organization_id' => $org->id,
                'code' => 'CAT-EDG-' . strtoupper(Str::random(4)),
                'name' => 'Edge Category',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('products')->insert([
                'id' => $prodId,
                'organization_id' => $org->id,
                'category_id' => $catId,
                'sku' => 'SKU-EDG-' . strtoupper(Str::random(4)),
                'name' => 'Edge Product',
                'unit_price' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->withHeaders($headers)
                ->postJson('/api/v1/inventory/adjust', [
                    'organization_id' => (string) $org->id,
                    'branch_id' => (string) $srcBranch->id,
                    'product_id' => $prodId,
                    'movement_type' => 'ADJUST',
                    'quantity_delta' => 10,
                    'note' => 'Edge initial stock',
                ])
                ->assertStatus(201);

            // 1. Transfer invalid state transitions: cannot receive while DRAFT, cannot ship twice, cannot receive twice
            $trfRes = $this->withHeaders($headers)
                ->postJson('/api/v1/stock-transfers', [
                    'organization_id' => (string) $org->id,
                    'source_branch_id' => (string) $srcBranch->id,
                    'destination_branch_id' => (string) $dstBranch->id,
                    'items' => [['product_id' => $prodId, 'quantity' => 2]],
                ])
                ->assertStatus(201);

            $transferId = $trfRes->json('data.transfer.id');

            $this->withHeaders(array_merge($headers, ['X-Branch-Id' => (string) $dstBranch->id]))
                ->postJson("/api/v1/stock-transfers/{$transferId}/receive")
                ->assertStatus(422);

            $this->withHeaders($headers)
                ->postJson("/api/v1/stock-transfers/{$transferId}/ship")
                ->assertStatus(200);

            $this->withHeaders($headers)
                ->postJson("/api/v1/stock-transfers/{$transferId}/ship")
                ->assertStatus(422);

            $this->withHeaders(array_merge($headers, ['X-Branch-Id' => (string) $dstBranch->id]))
                ->postJson("/api/v1/stock-transfers/{$transferId}/receive")
                ->assertStatus(200);

            $this->withHeaders(array_merge($headers, ['X-Branch-Id' => (string) $dstBranch->id]))
                ->postJson("/api/v1/stock-transfers/{$transferId}/receive")
                ->assertStatus(422);

            // 2. Duplicate open shift guard
            $openRes = $this->withHeaders($headers)
                ->postJson('/api/v1/shifts/open', [
                    'organization_id' => (string) $org->id,
                    'branch_id' => (string) $srcBranch->id,
                    'opening_cash' => 500,
                ])
                ->assertStatus(201);

            $shiftId = $openRes->json('data.shift.id');

            $this->withHeaders($headers)
                ->postJson('/api/v1/shifts/open', [
                    'organization_id' => (string) $org->id,
                    'branch_id' => (string) $srcBranch->id,
                    'opening_cash' => 500,
                ])
                ->assertStatus(422);

            // 3. Sale created + voided + another sale kept -> expected cash reflects only non-voided cash sale
            $sale1Res = $this->withHeaders($headers)
                ->postJson('/api/v1/sales', [
                    'organization_id' => (string) $org->id,
                    'branch_id' => (string) $srcBranch->id,
                    'items' => [['product_id' => $prodId, 'quantity' => 1]],
                    'payments' => [['payment_method' => 'CASH', 'amount_tendered' => 100]],
                ])
                ->assertStatus(201);
            $saleVoidId = $sale1Res->json('data.sale.id');

            $this->withHeaders($headers)
                ->postJson("/api/v1/sales/{$saleVoidId}/void", [
                    'reason' => 'Customer cancelled before shift close',
                ])
                ->assertStatus(200);

            $sale2Res = $this->withHeaders($headers)
                ->postJson('/api/v1/sales', [
                    'organization_id' => (string) $org->id,
                    'branch_id' => (string) $srcBranch->id,
                    'items' => [['product_id' => $prodId, 'quantity' => 2]],
                    'payments' => [['payment_method' => 'CASH', 'amount_tendered' => 200]],
                ])
                ->assertStatus(201);
            $saleKeepId = $sale2Res->json('data.sale.id');

            // Opening 500 + kept cash sale 200 = expected_cash 700 (voided 100 excluded)
            $closeRes = $this->withHeaders($headers)
                ->postJson("/api/v1/shifts/{$shiftId}/close", [
                    'closing_cash_counted' => 700,
                ])
                ->assertStatus(200);

            $this->assertEquals(1, $closeRes->json('data.reconciliation.completed_sales_count'));
            $this->assertEquals(1, $closeRes->json('data.reconciliation.voided_sales_count'));
            $this->assertEquals(700, $closeRes->json('data.reconciliation.expected_cash'));
            $this->assertEquals(0, $closeRes->json('data.reconciliation.cash_variance'));
        } finally {
            foreach (array_filter([$saleVoidId, $saleKeepId]) as $sid) {
                DB::table('sale_payments')->where('sale_id', $sid)->delete();
                DB::table('sale_items')->where('sale_id', $sid)->delete();
                DB::table('sales')->where('id', $sid)->delete();
            }
            if ($shiftId) {
                DB::table('pos_shifts')->where('id', $shiftId)->delete();
            }

            $transferIds = DB::table('stock_transfers')
                ->whereIn('id', function ($q) use ($prodId) {
                    $q->select('stock_transfer_id')->from('stock_transfer_items')->where('product_id', $prodId);
                })->pluck('id');

            if (count($transferIds) > 0) {
                DB::table('stock_transfer_items')->whereIn('stock_transfer_id', $transferIds)->delete();
                DB::table('stock_transfers')->whereIn('id', $transferIds)->delete();
            }

            $balanceIds = DB::table('inventory_balances')->where('product_id', $prodId)->pluck('id');
            DB::table('stock_movements')->where('product_id', $prodId)->delete();
            DB::table('inventory_balances')->where('product_id', $prodId)->delete();
            if (count($balanceIds) > 0) {
                DB::table('outbox_events')->whereIn('aggregate_id', $balanceIds)->delete();
            }
            DB::table('outbox_events')->where('aggregate_id', $prodId)->delete();
            DB::table('products')->where('id', $prodId)->delete();
            DB::table('categories')->where('id', $catId)->delete();

            if ($createdTempBranch && $dstBranch) {
                DB::table('branches')->where('id', $dstBranch->id)->delete();
            }
            if (! empty($pair['family_id'])) {
                DB::table('refresh_tokens')->where('family_id', $pair['family_id'])->delete();
            }
        }
    }
}