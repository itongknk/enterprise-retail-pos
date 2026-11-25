<?php

namespace Tests\Feature;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedSalesContext(float $initialQty = 10.0): array
    {
        $orgId = (string) Str::uuid();
        $otherOrgId = (string) Str::uuid();
        $branchId = (string) Str::uuid();
        $otherOrgBranchId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'id' => $orgId,
                'code' => 'ORG-SALE',
                'name' => 'Sale Organization',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $otherOrgId,
                'code' => 'ORG-EXT',
                'name' => 'External Organization',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('branches')->insert([
            [
                'id' => $branchId,
                'organization_id' => $orgId,
                'code' => 'BR-SALE-01',
                'name' => 'Main Branch',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $otherOrgBranchId,
                'organization_id' => $otherOrgId,
                'code' => 'BR-EXT-01',
                'name' => 'External Branch',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('users')->insert([
            [
                'id' => $userId,
                'organization_id' => $orgId,
                'employee_code' => 'EMP-SALE-01',
                'email' => 'sales-test@local.test',
                'password_hash' => Hash::make('TestPass123!'),
                'full_name' => 'Sales Tester',
                'is_org_admin' => true,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        /** @var User $user */
        $user = User::withoutGlobalScope('organization')->findOrFail($userId);

        /** @var TokenService $tokenService */
        $tokenService = app(TokenService::class);
        $issued = $tokenService->issueTokenPair($user);
        $plainToken = (string) ($issued['access_token'] ?? $issued['token'] ?? '');

        DB::table('products')->insert([
            [
                'id' => $productId,
                'organization_id' => $orgId,
                'sku' => 'SKU-SALE-01',
                'name' => 'Sale Test Item',
                'unit' => 'pcs',
                'cost_price' => 40.00,
                'selling_price' => 100.00,
                'tax_rate' => 7.00,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('inventory_balances')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'quantity_on_hand' => $initialQty,
            'reserved_quantity' => 0,
            'reorder_level' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('orgId', 'branchId', 'otherOrgBranchId', 'productId', 'plainToken');
    }

    public function test_sales_endpoints_enforce_branch_and_manage_inventory_on_create_and_void(): void
    {
        $ctx = $this->seedSalesContext(10.0);

        $authHeaders = [
            'Authorization' => 'Bearer ' . $ctx['plainToken'],
            'Accept' => 'application/json',
        ];

        $this->withHeaders($authHeaders)
            ->getJson('/api/v1/sales')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BRANCH_CONTEXT_REQUIRED');

        $this->withHeaders(array_merge($authHeaders, ['X-Branch-Id' => $ctx['otherOrgBranchId']]))
            ->getJson('/api/v1/sales')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_BRANCH');

        $createRes = $this->withHeaders(array_merge($authHeaders, ['X-Branch-Id' => $ctx['branchId']]))
            ->postJson('/api/v1/sales', [
                'organization_id' => $ctx['orgId'],
                'branch_id' => $ctx['branchId'],
                'items' => [
                    [
                        'product_id' => $ctx['productId'],
                        'quantity' => 2,
                        'unit_price' => 100,
                    ],
                ],
                'payments' => [
                    [
                        'payment_method' => 'CASH',
                        'amount_tendered' => 200,
                    ],
                ],
            ])
            ->assertStatus(201);

        $saleId = (string) ($createRes->json('data.id') ?? $createRes->json('data.sale.id') ?? '');
        $this->assertNotEmpty($saleId);

        $qtyAfterSale = (float) DB::table('inventory_balances')
            ->where('organization_id', $ctx['orgId'])
            ->where('branch_id', $ctx['branchId'])
            ->where('product_id', $ctx['productId'])
            ->value('quantity_on_hand');
        $this->assertEquals(8.0, $qtyAfterSale);

        $this->withHeaders(array_merge($authHeaders, ['X-Branch-Id' => $ctx['branchId']]))
            ->postJson("/api/v1/sales/{$saleId}/void", [
                'reason' => 'Automated feature test void',
            ])
            ->assertStatus(200);

        $qtyAfterVoid = (float) DB::table('inventory_balances')
            ->where('organization_id', $ctx['orgId'])
            ->where('branch_id', $ctx['branchId'])
            ->where('product_id', $ctx['productId'])
            ->value('quantity_on_hand');
        $this->assertEquals(10.0, $qtyAfterVoid);
    }

    public function test_create_sale_calculates_discount_and_payment_totals(): void
    {
        $ctx = $this->seedSalesContext(10.0);

        $res = $this->withHeaders([
            'Authorization' => 'Bearer ' . $ctx['plainToken'],
            'Accept' => 'application/json',
            'X-Branch-Id' => $ctx['branchId'],
        ])->postJson('/api/v1/sales', [
            'organization_id' => $ctx['orgId'],
            'branch_id' => $ctx['branchId'],
            'discount_amount' => 50,
            'items' => [
                [
                    'product_id' => $ctx['productId'],
                    'quantity' => 3,
                    'unit_price' => 100,
                ],
            ],
            'payments' => [
                [
                    'payment_method' => 'CASH',
                    'amount_tendered' => 300,
                ],
            ],
        ]);

        $res->assertStatus(201);

        $saleId = (string) ($res->json('data.id') ?? $res->json('data.sale.id') ?? '');
        $this->assertNotEmpty($saleId);

        $saleRow = DB::table('sales')->where('id', $saleId)->first();
        $this->assertNotNull($saleRow);
        $this->assertEquals(300.0, (float) $saleRow->subtotal);
        $this->assertEquals(50.0, (float) $saleRow->discount_amount);
        $this->assertEquals(250.0, (float) $saleRow->total_amount);
    }

    public function test_insufficient_stock_and_duplicate_void_do_not_corrupt_inventory(): void
    {
        $ctx = $this->seedSalesContext(5.0);

        $headers = [
            'Authorization' => 'Bearer ' . $ctx['plainToken'],
            'Accept' => 'application/json',
            'X-Branch-Id' => $ctx['branchId'],
        ];

        $overSellRes = $this->withHeaders($headers)->postJson('/api/v1/sales', [
            'organization_id' => $ctx['orgId'],
            'branch_id' => $ctx['branchId'],
            'items' => [
                [
                    'product_id' => $ctx['productId'],
                    'quantity' => 99,
                    'unit_price' => 100,
                ],
            ],
            'payments' => [
                [
                    'payment_method' => 'CASH',
                    'amount_tendered' => 9900,
                ],
            ],
        ]);

        $this->assertTrue(in_array($overSellRes->status(), [400, 409, 422], true));

        $qtyAfterFailedSale = (float) DB::table('inventory_balances')
            ->where('organization_id', $ctx['orgId'])
            ->where('branch_id', $ctx['branchId'])
            ->where('product_id', $ctx['productId'])
            ->value('quantity_on_hand');
        $this->assertEquals(5.0, $qtyAfterFailedSale);

        $validSaleRes = $this->withHeaders($headers)->postJson('/api/v1/sales', [
            'organization_id' => $ctx['orgId'],
            'branch_id' => $ctx['branchId'],
            'items' => [
                [
                    'product_id' => $ctx['productId'],
                    'quantity' => 2,
                    'unit_price' => 100,
                ],
            ],
            'payments' => [
                [
                    'payment_method' => 'CASH',
                    'amount_tendered' => 200,
                ],
            ],
        ])->assertStatus(201);

        $saleId = (string) ($validSaleRes->json('data.id') ?? $validSaleRes->json('data.sale.id') ?? '');
        $this->assertNotEmpty($saleId);

        $this->withHeaders($headers)
            ->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'First void'])
            ->assertStatus(200);

        $qtyAfterFirstVoid = (float) DB::table('inventory_balances')
            ->where('organization_id', $ctx['orgId'])
            ->where('branch_id', $ctx['branchId'])
            ->where('product_id', $ctx['productId'])
            ->value('quantity_on_hand');
        $this->assertEquals(5.0, $qtyAfterFirstVoid);

        $secondVoidRes = $this->withHeaders($headers)
            ->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'Duplicate void']);

        $this->assertTrue(in_array($secondVoidRes->status(), [400, 409, 422], true));

        $qtyAfterSecondVoid = (float) DB::table('inventory_balances')
            ->where('organization_id', $ctx['orgId'])
            ->where('branch_id', $ctx['branchId'])
            ->where('product_id', $ctx['productId'])
            ->value('quantity_on_hand');
        $this->assertEquals(5.0, $qtyAfterSecondVoid);
    }

public function test_non_admin_without_branch_permission_gets_403_and_records_audit(): void
{
    $orgId = (string) Str::uuid();
    $branchId = (string) Str::uuid();
    $otherOrgId = (string) Str::uuid();
    $otherOrgBranchId = (string) Str::uuid();

    DB::table('organizations')->insert([
        [
            'id' => $orgId,
            'code' => 'ORG-RBAC-01',
            'name' => 'RBAC Test Org',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $otherOrgId,
            'code' => 'ORG-RBAC-02',
            'name' => 'Other RBAC Org',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('branches')->insert([
        [
            'id' => $branchId,
            'organization_id' => $orgId,
            'code' => 'BR-01',
            'name' => 'Main Branch',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $otherOrgBranchId,
            'organization_id' => $otherOrgId,
            'code' => 'BR-OTHER',
            'name' => 'Other Org Branch',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $nonAdminId = (string) Str::uuid();
    DB::table('users')->insert([
        'id' => $nonAdminId,
        'organization_id' => $orgId,
        'employee_code' => 'EMP-NO-PERM-01',
        'email' => 'no-perm@local.test',
        'password_hash' => Hash::make('TestPass123!'),
        'full_name' => 'No Perm Staff',
        'is_org_admin' => false,
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var User $nonAdmin */
    $nonAdmin = User::withoutGlobalScope('organization')->findOrFail($nonAdminId);
    /** @var TokenService $tokenService */
    $tokenService = app(TokenService::class);
    $issued = $tokenService->issueTokenPair($nonAdmin);
    $nonAdminToken = (string) ($issued['access_token'] ?? $issued['token'] ?? '');

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $nonAdminToken,
        'X-Branch-Id' => $branchId,
    ])->getJson('/api/v1/sales');

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->assertDatabaseHas('audit_logs', [
        'organization_id' => $orgId,
        'branch_id' => $branchId,
        'actor_id' => $nonAdminId,
        'action' => 'AUTHZ_DENIED',
    ]);

    $otherOrgResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $nonAdminToken,
        'X-Branch-Id' => $otherOrgBranchId,
    ])->getJson('/api/v1/sales');

    $otherOrgResponse->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN_BRANCH');
}
}
