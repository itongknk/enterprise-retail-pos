<?php

namespace Tests\Feature;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockTransferAndShiftFeatureTest extends TestCase
{
    public function test_inter_branch_transfer_and_shift_sale_reconciliation_via_api(): void
    {
        $org = DB::table('organizations')->first();
        if (!$org) {
            $this->seed(\Database\Seeders\TestDatabaseSeeder::class);
            $org = DB::table('organizations')->first();
        }
        $this->assertNotNull($org, 'Expected an organization record in database.');

        $branches = DB::table('branches')->where('organization_id', $org->id)->get();
        $srcBranch = $branches->first();
        $dstBranch = $branches->skip(1)->first();
        $createdTempBranch = false;

        if (! $srcBranch) {
            $srcId = (string) Str::uuid();
            DB::table('branches')->insert([
                'id' => $srcId,
                'organization_id' => $org->id,
                'code' => 'BR-FT-SRC',
                'name' => 'Feature Test Source Branch',
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
                'code' => 'BR-FT-DST',
                'name' => 'Feature Test Dest Branch',
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
        $this->assertNotNull($userModel, 'Expected a user record in organization.');

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
        $saleId = null;

        try {
            DB::table('categories')->insert([
                'id' => $catId,
                'organization_id' => $org->id,
                'code' => 'CAT-FT-' . strtoupper(Str::random(4)),
                'name' => 'Feature Test Category',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('products')->insert([
                'id' => $prodId,
                'organization_id' => $org->id,
                'category_id' => $catId,
                'sku' => 'SKU-FT-' . strtoupper(Str::random(4)),
                'name' => 'Feature Test Product',
                'unit_price' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 1. Seed stock in source branch
            $this->withHeaders($headers)
                ->postJson('/api/v1/inventory/adjust', [
                    'organization_id' => (string) $org->id,
                    'branch_id' => (string) $srcBranch->id,
                    'product_id' => $prodId,
                    'movement_type' => 'ADJUST',
                    'quantity_delta' => 10,
                    'note' => 'Feature test initial stock',
                ])
                ->assertStatus(201);

            // 2. Create -> Ship -> Receive inter-branch stock transfer
            $createTrfRes = $this->withHeaders($headers)
                ->postJson('/api/v1/stock-transfers', [
                    'organization_id' => (string) $org->id,
                    'source_branch_id' => (string) $srcBranch->id,
                    'destination_branch_id' => (string) $dstBranch->id,
                    'items' => [
                        ['product_id' => $prodId, 'quantity' => 4],
                    ],
                    'note' => 'Feature test transfer',
                ])
                ->assertStatus(201);

            $transferId = $createTrfRes->json('data.transfer.id');
            $this->assertNotEmpty($transferId);

            $this->withHeaders($headers)
                ->postJson("/api/v1/stock-transfers/{$transferId}/ship")
                ->assertStatus(200);

            $this->withHeaders(array_merge($headers, ['X-Branch-Id' => (string) $dstBranch->id]))
                ->postJson("/api/v1/stock-transfers/{$transferId}/receive")
                ->assertStatus(200);

            $srcQty = (float) DB::table('inventory_balances')
                ->where('organization_id', $org->id)
                ->where('branch_id', $srcBranch->id)
                ->where('product_id', $prodId)
                ->value('quantity_on_hand');

            $dstQty = (float) DB::table('inventory_balances')
                ->where('organization_id', $org->id)
                ->where('branch_id', $dstBranch->id)
                ->where('product_id', $prodId)
                ->value('quantity_on_hand');

            $this->assertSame(6.0, $srcQty);
            $this->assertSame(4.0, $dstQty);

            // 3. Open shift -> Create sale linked to shift -> Close shift & verify cash variance
            $openShiftRes = $this->withHeaders($headers)
                ->postJson('/api/v1/shifts/open', [
                    'organization_id' => (string) $org->id,
                    'branch_id' => (string) $srcBranch->id,
                    'opening_cash' => 1000,
                    'note' => 'Feature test shift open',
                ])
                ->assertStatus(201);

            $shiftId = $openShiftRes->json('data.shift.id');
            $this->assertNotEmpty($shiftId);

            $saleRes = $this->withHeaders($headers)
                ->postJson('/api/v1/sales', [
                    'organization_id' => (string) $org->id,
                    'branch_id' => (string) $srcBranch->id,
                    'items' => [
                        ['product_id' => $prodId, 'quantity' => 2],
                    ],
                    'discount_amount' => 10,
                    'payments' => [
                        ['payment_method' => 'CASH', 'amount_tendered' => 200],
                    ],
                    'note' => 'Feature test cash sale',
                ])
                ->assertStatus(201);

            $saleId = $saleRes->json('data.sale.id');
            $this->assertSame($shiftId, $saleRes->json('data.sale.shift_id'));

            $closeShiftRes = $this->withHeaders($headers)
                ->postJson("/api/v1/shifts/{$shiftId}/close", [
                    'closing_cash_counted' => 1190,
                    'note' => 'Feature test shift close',
                ])
                ->assertStatus(200);

            $this->assertEquals(1190, $closeShiftRes->json('data.reconciliation.expected_cash'));
            $this->assertEquals(1190, $closeShiftRes->json('data.reconciliation.closing_cash_counted'));
            $this->assertEquals(0, $closeShiftRes->json('data.reconciliation.cash_variance'));
        } finally {
            if ($saleId) {
                DB::table('sale_payments')->where('sale_id', $saleId)->delete();
                DB::table('sale_items')->where('sale_id', $saleId)->delete();
                DB::table('sales')->where('id', $saleId)->delete();
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