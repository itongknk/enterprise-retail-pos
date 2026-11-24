<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TestDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $orgId = '11111111-1111-4111-8111-111111111101';
        $branch1Id = '22222222-2222-4222-8222-222222222201';
        $branch2Id = '22222222-2222-4222-8222-222222222202';
        $userId = '33333333-3333-4333-8333-333333333301';
        $productId = '44444444-4444-4444-8444-444444444401';

        DB::table('organizations')->insertOrIgnore([
            [
                'id' => $orgId,
                'code' => 'ORG-DEFAULT',
                'name' => 'Default Test Organization',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('branches')->insertOrIgnore([
            [
                'id' => $branch1Id,
                'organization_id' => $orgId,
                'code' => 'BR-001',
                'name' => 'Branch 001',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $branch2Id,
                'organization_id' => $orgId,
                'code' => 'BR-002',
                'name' => 'Branch 002',
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('users')->insertOrIgnore([
            [
                'id' => $userId,
                'organization_id' => $orgId,
                'employee_code' => 'EMP-001',
                'email' => 'admin@local.test',
                'password_hash' => Hash::make('Password123!'),
                'full_name' => 'Default Admin',
                'is_org_admin' => true,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('products')->insertOrIgnore([
            [
                'id' => $productId,
                'organization_id' => $orgId,
                'sku' => 'SKU-001',
                'name' => 'Default Product 001',
                'unit' => 'pcs',
                'cost_price' => 50.00,
                'selling_price' => 100.00,
                'tax_rate' => 7.00,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        foreach ([$branch1Id, $branch2Id] as $brId) {
            DB::table('inventory_balances')->insertOrIgnore([
                [
                    'id' => (string) Str::uuid(),
                    'organization_id' => $orgId,
                    'branch_id' => $brId,
                    'product_id' => $productId,
                    'quantity_on_hand' => 100.000,
                    'reserved_quantity' => 0.000,
                    'reorder_level' => 10.000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
}