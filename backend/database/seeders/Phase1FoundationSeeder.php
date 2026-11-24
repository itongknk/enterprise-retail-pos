<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Phase1FoundationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            // 1. Seed Permissions พื้นฐานสำหรับ Phase 1 และเตรียมชื่อสิทธิ์สำหรับโมดูลถัดไป
            $permissions = [
                ['module' => 'organization', 'code' => 'branches.view', 'description' => 'ดูข้อมูลสาขา'],
                ['module' => 'organization', 'code' => 'branches.manage', 'description' => 'สร้างและแก้ไขข้อมูลสาขา/คลัง/เครื่อง POS'],
                ['module' => 'identity', 'code' => 'users.view', 'description' => 'ดูรายชื่อผู้ใช้งาน'],
                ['module' => 'identity', 'code' => 'users.manage', 'description' => 'สร้าง แก้ไข และกำหนดสิทธิ์ผู้ใช้รายสาขา'],
                ['module' => 'identity', 'code' => 'roles.view', 'description' => 'ดูบทบาทและสิทธิ์'],
                ['module' => 'identity', 'code' => 'roles.manage', 'description' => 'จัดการบทบาทและสิทธิ์'],
                ['module' => 'audit', 'code' => 'audit.view', 'description' => 'ตรวจสอบประวัติ Audit Trail'],
            ];

            $permissionIds = [];
            foreach ($permissions as $perm) {
                $existing = DB::table('permissions')->where('code', $perm['code'])->first();
                if ($existing) {
                    $permissionIds[$perm['code']] = $existing->id;
                } else {
                    $id = (string) Str::uuid();
                    DB::table('permissions')->insert([
                        'id' => $id,
                        'module' => $perm['module'],
                        'code' => $perm['code'],
                        'description' => $perm['description'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $permissionIds[$perm['code']] = $id;
                }
            }

            // 2. สร้างองค์กรและสาขาเริ่มต้น (เฉพาะกรณีที่ยังไม่มี)
            $org = DB::table('organizations')->where('code', 'ORG-MAIN')->first();
            if (!$org) {
                $orgId = (string) Str::uuid();
                DB::table('organizations')->insert([
                    'id' => $orgId,
                    'code' => 'ORG-MAIN',
                    'name' => 'Main Enterprise Organization',
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $orgId = $org->id;
            }

            $branch = DB::table('branches')
                ->where('organization_id', $orgId)
                ->where('code', 'BR-001')
                ->first();

            if (!$branch) {
                $branchId = (string) Str::uuid();
                DB::table('branches')->insert([
                    'id' => $branchId,
                    'organization_id' => $orgId,
                    'code' => 'BR-001',
                    'name' => 'สำนักงานใหญ่ / สาขาหลัก',
                    'timezone' => 'Asia/Bangkok',
                    'is_headquarters' => true,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $branchId = $branch->id;
            }

            // 3. สร้าง Roles เริ่มต้น (BRANCH_MANAGER และ CASHIER)
            $rolesSpec = [
                'BRANCH_MANAGER' => [
                    'name' => 'ผู้จัดการสาขา',
                    'scope_level' => 'BRANCH',
                    'perms' => ['branches.view', 'users.view', 'audit.view'],
                ],
                'CASHIER' => [
                    'name' => 'พนักงานขายหน้าร้าน',
                    'scope_level' => 'BRANCH',
                    'perms' => ['branches.view'],
                ],
            ];

            foreach ($rolesSpec as $code => $spec) {
                $role = DB::table('roles')
                    ->where('organization_id', $orgId)
                    ->where('code', $code)
                    ->first();

                $roleId = $role ? $role->id : (string) Str::uuid();
                if (!$role) {
                    DB::table('roles')->insert([
                        'id' => $roleId,
                        'organization_id' => $orgId,
                        'code' => $code,
                        'name' => $spec['name'],
                        'scope_level' => $spec['scope_level'],
                        'is_system' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                foreach ($spec['perms'] as $pCode) {
                    if (isset($permissionIds[$pCode])) {
                        DB::table('role_permissions')->insertOrIgnore([
                            'role_id' => $roleId,
                            'permission_id' => $permissionIds[$pCode],
                        ]);
                    }
                }
            }

            // 4. สร้างบัญชี Admin เริ่มต้น (เปลี่ยนรหัสผ่านทันทีหลังใช้งานจริง)
            $adminEmail = 'admin@local.test';
            $existingAdmin = DB::table('users')
                ->where('organization_id', $orgId)
                ->where('email', $adminEmail)
                ->first();

            if (!$existingAdmin) {
                $adminId = (string) Str::uuid();
                DB::table('users')->insert([
                    'id' => $adminId,
                    'organization_id' => $orgId,
                    'employee_code' => 'EMP-0001',
                    'email' => $adminEmail,
                    'password_hash' => Hash::make('ChangeMe@12345'),
                    'full_name' => 'System Administrator',
                    'is_org_admin' => true,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // ผูกสิทธิ์ระดับสาขาหลักให้ด้วยเพื่อความชัดเจนในการทดสอบ Branch Context
                $managerRole = DB::table('roles')
                    ->where('organization_id', $orgId)
                    ->where('code', 'BRANCH_MANAGER')
                    ->first();

                if ($managerRole) {
                    DB::table('user_branch_roles')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'organization_id' => $orgId,
                        'user_id' => $adminId,
                        'branch_id' => $branchId,
                        'role_id' => $managerRole->id,
                        'created_at' => now(),
                    ]);
                }
            }
        });
    }
}