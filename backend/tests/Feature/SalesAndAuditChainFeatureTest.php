<?php

namespace Tests\Feature;

use App\Modules\AuditTrail\Services\AuditTrailService;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Services\TokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesAndAuditChainFeatureTest extends TestCase
{
    public function test_sale_void_and_audit_chain_verification_via_api(): void
    {
        $org = DB::table('organizations')->first();
        if (!$org) {
            $this->seed(\Database\Seeders\TestDatabaseSeeder::class);
            $org = DB::table('organizations')->first();
        }
        $this->assertNotNull($org, 'Expected at least one organization in database.');

        $branch = DB::table('branches')->where('organization_id', $org->id)->first();
        $this->assertNotNull($branch, 'Expected at least one branch in organization.');

        $user = User::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('status', 'ACTIVE')
            ->first() ?? User::withoutGlobalScope('organization')->where('organization_id', $org->id)->first();
        $this->assertNotNull($user, 'Expected at least one user in organization.');

        /** @var TokenService $tokenService */
        $tokenService = app(TokenService::class);
        $pair = $tokenService->issueTokenPair($user);
        $token = $pair['access_token'] ?? $pair['plain_access_token'] ?? $pair['token'] ?? null;
        $this->assertNotEmpty($token, 'Expected access token from TokenService.');

        $catId = (string) Str::uuid();
        $prodId = (string) Str::uuid();
        $createdSaleId = null;

        try {
            DB::table('categories')->insert([
                'id' => $catId,
                'organization_id' => $org->id,
                'code' => 'CAT-FEAT-01',
                'name' => 'Feature Test Category',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('products')->insert([
                'id' => $prodId,
                'organization_id' => $org->id,
                'category_id' => $catId,
                'sku' => 'SKU-FEAT-01',
                'name' => 'Feature Test Product',
                'unit_price' => 150,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'X-Branch-Id' => (string) $branch->id,
            ];

            $adjustRes = $this->withHeaders($headers)->postJson('/api/v1/inventory/adjust', [
                'organization_id' => (string) $org->id,
                'branch_id' => (string) $branch->id,
                'product_id' => $prodId,
                'movement_type' => 'ADJUST',
                'quantity_delta' => 10,
                'note' => 'Feature test stock seed',
            ]);
            $adjustRes->assertStatus(201);

            $saleRes = $this->withHeaders($headers)->postJson('/api/v1/sales', [
                'organization_id' => (string) $org->id,
                'branch_id' => (string) $branch->id,
                'items' => [
                    ['product_id' => $prodId, 'quantity' => 2],
                ],
                'discount_amount' => 20,
                'payments' => [
                    ['payment_method' => 'CASH', 'amount_tendered' => 300],
                ],
                'note' => 'Feature test sale',
            ]);
            $saleRes->assertStatus(201);

            $saleJson = $saleRes->json();
            $createdSaleId = $saleJson['data']['sale']['id']
                ?? $saleJson['sale']['id']
                ?? $saleJson['data']['id']
                ?? $saleJson['id']
                ?? null;
            $this->assertNotEmpty($createdSaleId, 'Expected created sale ID.');

            $voidRes = $this->withHeaders($headers)->postJson("/api/v1/sales/{$createdSaleId}/void", [
                'reason' => 'Feature test void',
            ]);
            $voidRes->assertStatus(200);

            /** @var AuditTrailService $auditService */
            $auditService = app(AuditTrailService::class);
            $logs = DB::table('audit_logs')
                ->where('organization_id', $org->id)
                ->orderBy('id')
                ->get();

            $this->assertGreaterThanOrEqual(3, $logs->count());

            $expectedPrevHash = str_repeat('0', 64);
            foreach ($logs as $log) {
                $this->assertSame($expectedPrevHash, (string) $log->prev_hash, "Chain break at audit_logs.id={$log->id}");

                $afterData = $log->after_data !== null
                    ? (is_string($log->after_data) ? json_decode($log->after_data, true) : (array) $log->after_data)
                    : null;
                $afterJson = $auditService->normalizeJson($afterData);

                $createdAtStr = (string) $log->created_at;
                $parsedUtc = CarbonImmutable::parse($createdAtStr)->utc();
                $timeVariants = array_values(array_unique([
                    $createdAtStr,
                    $parsedUtc->toIso8601ZuluString(),
                    $parsedUtc->format('Y-m-d\TH:i:s.u\Z'),
                    $parsedUtc->format('Y-m-d\TH:i:s\Z'),
                ]));

                $matched = false;
                foreach ($timeVariants as $timeStr) {
                    $candidate = $auditService->computeHash(
                        prevHash: (string) $log->prev_hash,
                        organizationId: (string) $log->organization_id,
                        branchId: (string) ($log->branch_id ?? ''),
                        actorId: (string) ($log->actor_id ?? ''),
                        action: (string) $log->action,
                        entityType: (string) $log->entity_type,
                        entityId: (string) $log->entity_id,
                        afterJson: $afterJson,
                        createdAt: $timeStr
                    );
                    if (hash_equals((string) $log->record_hash, $candidate)) {
                        $matched = true;
                        break;
                    }
                }

                $this->assertTrue($matched, "Hash mismatch at audit_logs.id={$log->id}");
                $expectedPrevHash = (string) $log->record_hash;
            }
        } finally {
            $prodIds = DB::table('products')->where('sku', 'like', 'SKU-FEAT-%')->pluck('id');
            $catIds = DB::table('categories')->where('code', 'like', 'CAT-FEAT-%')->pluck('id');
            $balanceIds = DB::table('inventory_balances')->whereIn('product_id', $prodIds)->pluck('id');
            $saleIds = DB::table('sales')->whereIn('id', function ($q) use ($prodIds) {
                $q->select('sale_id')->from('sale_items')->whereIn('product_id', $prodIds);
            })->pluck('id');

            if (count($saleIds) > 0) {
                DB::table('sale_payments')->whereIn('sale_id', $saleIds)->delete();
                DB::table('sale_items')->whereIn('sale_id', $saleIds)->delete();
                DB::table('sales')->whereIn('id', $saleIds)->delete();
            }
            if (count($prodIds) > 0) {
                DB::table('stock_movements')->whereIn('product_id', $prodIds)->delete();
                DB::table('inventory_balances')->whereIn('product_id', $prodIds)->delete();
                if (count($balanceIds) > 0) {
                    DB::table('outbox_events')->whereIn('aggregate_id', $balanceIds)->delete();
                }
                DB::table('outbox_events')->whereIn('aggregate_id', $prodIds)->delete();
                if (Schema::hasTable('product_barcodes')) {
                    DB::table('product_barcodes')->whereIn('product_id', $prodIds)->delete();
                }
                DB::table('products')->whereIn('id', $prodIds)->delete();
            }
            if (count($catIds) > 0) {
                DB::table('categories')->whereIn('id', $catIds)->delete();
            }
            if (! empty($pair['family_id'])) {
                DB::table('refresh_tokens')->where('family_id', $pair['family_id'])->delete();
            }
        }
    }
}