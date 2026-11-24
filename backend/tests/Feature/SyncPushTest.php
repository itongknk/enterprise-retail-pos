<?php

namespace Tests\Feature;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncPushTest extends TestCase
{
    use RefreshDatabase;

    private function seedContext(float $initialQty = 10.0): array
    {
        $orgId = (string) Str::uuid();
        $otherOrgId = (string) Str::uuid();
        $branchAId = (string) Str::uuid();
        $branchBId = (string) Str::uuid();
        $otherOrgBranchId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $productId = (string) Str::uuid();

        DB::table('organizations')->insert([
            [
                'id' => $orgId,
                'code' => 'ORG-TEST',
                'name' => 'Test Organization',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $otherOrgId,
                'code' => 'ORG-OTHER',
                'name' => 'Other Organization',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('branches')->insert([
            [
                'id' => $branchAId,
                'organization_id' => $orgId,
                'code' => 'BR-01',
                'name' => 'Branch 01',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $branchBId,
                'organization_id' => $orgId,
                'code' => 'BR-02',
                'name' => 'Branch 02',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $otherOrgBranchId,
                'organization_id' => $otherOrgId,
                'code' => 'BR-EXT',
                'name' => 'External Org Branch',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('users')->insert([
            [
                'id' => $userId,
                'organization_id' => $orgId,
                'employee_code' => 'EMP-001',
                'email' => 'sync-test@local.test',
                'password_hash' => Hash::make('TestPass123!'),
                'full_name' => 'Test Admin',
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
            'id' => $productId,
            'organization_id' => $orgId,
            'sku' => 'SKU-TEST-01',
            'name' => 'Test Product',
            'unit' => 'pcs',
            'cost_price' => 50.00,
            'selling_price' => 100.00,
            'tax_rate' => 7.00,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_balances')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $orgId,
            'branch_id' => $branchAId,
            'product_id' => $productId,
            'quantity_on_hand' => $initialQty,
            'reserved_quantity' => 0,
            'reorder_level' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('orgId', 'branchAId', 'branchBId', 'otherOrgBranchId', 'productId', 'plainToken');
    }

    public function test_sync_push_enforces_branch_header_and_idempotency(): void
    {
        $ctx = $this->seedContext(10.0);
        $eventId = (string) Str::uuid();

        $payload = [
            'organization_id' => $ctx['orgId'],
            'branch_id' => $ctx['branchAId'],
            'device_id' => 'POS-TEST-01',
            'events' => [
                [
                    'event_id' => $eventId,
                    'event_type' => 'SALE_CREATED',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => [
                        'items' => [
                            [
                                'product_id' => $ctx['productId'],
                                'quantity' => 1,
                                'unit_price' => 100,
                            ],
                        ],
                        'payments' => [
                            [
                                'payment_method' => 'CASH',
                                'amount_tendered' => 100,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $authHeaders = [
            'Authorization' => 'Bearer ' . $ctx['plainToken'],
            'Accept' => 'application/json',
        ];

        $this->withHeaders($authHeaders)
            ->postJson('/api/v1/sync/push', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BRANCH_CONTEXT_REQUIRED');

        $this->withHeaders(array_merge($authHeaders, ['X-Branch-Id' => $ctx['otherOrgBranchId']]))
            ->postJson('/api/v1/sync/push', $payload)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_BRANCH');

        $this->withHeaders(array_merge($authHeaders, ['X-Branch-Id' => $ctx['branchBId']]))
            ->postJson('/api/v1/sync/push', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BRANCH_MISMATCH');

        $this->withHeaders(array_merge($authHeaders, ['X-Branch-Id' => $ctx['branchAId']]))
            ->postJson('/api/v1/sync/push', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.event_id', $eventId)
            ->assertJsonPath('data.results.0.status', 'PROCESSED')
            ->assertJsonPath('data.results.0.idempotent_replay', false);

        $this->withHeaders(array_merge($authHeaders, ['X-Branch-Id' => $ctx['branchAId']]))
            ->postJson('/api/v1/sync/push', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.results.0.event_id', $eventId)
            ->assertJsonPath('data.results.0.status', 'DUPLICATE')
            ->assertJsonPath('data.results.0.idempotent_replay', true);

        $this->assertEquals(1, DB::table('sync_events')->where('event_id', $eventId)->count());
    }

    public function test_sync_push_processes_multiple_events_in_batch(): void
    {
        $ctx = $this->seedContext(20.0);
        $event1 = (string) Str::uuid();
        $event2 = (string) Str::uuid();

        $payload = [
            'organization_id' => $ctx['orgId'],
            'branch_id' => $ctx['branchAId'],
            'device_id' => 'POS-TEST-BATCH',
            'events' => [
                [
                    'event_id' => $event1,
                    'event_type' => 'SALE_CREATED',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => [
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
                    ],
                ],
                [
                    'event_id' => $event2,
                    'event_type' => 'SALE_CREATED',
                    'occurred_at' => now()->addSecond()->toIso8601String(),
                    'payload' => [
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
                    ],
                ],
            ],
        ];

        $res = $this->withHeaders([
            'Authorization' => 'Bearer ' . $ctx['plainToken'],
            'Accept' => 'application/json',
            'X-Branch-Id' => $ctx['branchAId'],
        ])->postJson('/api/v1/sync/push', $payload);

        $res->assertStatus(200)
            ->assertJsonPath('data.results.0.event_id', $event1)
            ->assertJsonPath('data.results.0.status', 'PROCESSED')
            ->assertJsonPath('data.results.1.event_id', $event2)
            ->assertJsonPath('data.results.1.status', 'PROCESSED');

        $qtyRemaining = (float) DB::table('inventory_balances')
            ->where('organization_id', $ctx['orgId'])
            ->where('branch_id', $ctx['branchAId'])
            ->where('product_id', $ctx['productId'])
            ->value('quantity_on_hand');

        $this->assertEquals(15.0, $qtyRemaining);
    }

    public function test_sync_push_handles_invalid_event_in_batch_without_corrupting_stock(): void
    {
        $ctx = $this->seedContext(10.0);
        $validEvent = (string) Str::uuid();
        $invalidEvent = (string) Str::uuid();

        $payload = [
            'organization_id' => $ctx['orgId'],
            'branch_id' => $ctx['branchAId'],
            'device_id' => 'POS-TEST-MIXED',
            'events' => [
                [
                    'event_id' => $validEvent,
                    'event_type' => 'SALE_CREATED',
                    'occurred_at' => now()->toIso8601String(),
                    'payload' => [
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
                    ],
                ],
                [
                    'event_id' => $invalidEvent,
                    'event_type' => 'SALE_CREATED',
                    'occurred_at' => now()->addSecond()->toIso8601String(),
                    'payload' => [
                        'items' => [
                            [
                                'product_id' => (string) Str::uuid(),
                                'quantity' => 1,
                                'unit_price' => 100,
                            ],
                        ],
                        'payments' => [
                            [
                                'payment_method' => 'CASH',
                                'amount_tendered' => 100,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->withHeaders([
            'Authorization' => 'Bearer ' . $ctx['plainToken'],
            'Accept' => 'application/json',
            'X-Branch-Id' => $ctx['branchAId'],
        ])->postJson('/api/v1/sync/push', $payload);

        $this->assertTrue(in_array($res->status(), [200, 207, 422], true));

        $qtyRemaining = (float) DB::table('inventory_balances')
            ->where('organization_id', $ctx['orgId'])
            ->where('branch_id', $ctx['branchAId'])
            ->where('product_id', $ctx['productId'])
            ->value('quantity_on_hand');

        $this->assertGreaterThanOrEqual(0.0, $qtyRemaining);
        $this->assertLessThanOrEqual(10.0, $qtyRemaining);
    }
}