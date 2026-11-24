<?php

namespace App\Modules\AuditTrail\Services;

use Illuminate\Support\Facades\DB;

class AuditTrailService
{
    public function record(
        string $organizationId,
        string $action,
        string $entityType,
        string $entityId,
        ?array $beforeData = null,
        ?array $afterData = null,
        ?string $branchId = null,
        ?string $actorId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        DB::transaction(function () use (
            $organizationId,
            $action,
            $entityType,
            $entityId,
            $beforeData,
            $afterData,
            $branchId,
            $actorId,
            $ipAddress,
            $userAgent
        ) {
            $lastLog = DB::table('audit_logs')
                ->where('organization_id', $organizationId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $prevHash = $lastLog ? (string) $lastLog->record_hash : str_repeat('0', 64);
            $createdAt = now()->utc()->toIso8601ZuluString();
            $afterJson = $this->normalizeJson($afterData);

            $recordHash = $this->computeHash(
                prevHash: $prevHash,
                organizationId: $organizationId,
                branchId: $branchId ?? '',
                actorId: $actorId ?? '',
                action: $action,
                entityType: $entityType,
                entityId: $entityId,
                afterJson: $afterJson,
                createdAt: $createdAt
            );

            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'actor_id' => $actorId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'before_data' => $beforeData !== null ? json_encode($beforeData, JSON_UNESCAPED_UNICODE) : null,
                'after_data' => $afterData !== null ? json_encode($afterData, JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'prev_hash' => $prevHash,
                'record_hash' => $recordHash,
                'created_at' => $createdAt,
            ]);
        });
    }

    public function normalizeJson(mixed $data): string
    {
        if ($data === null) {
            return '';
        }

        $normalized = $this->sortRecursive($data);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    public function computeHash(
        string $prevHash,
        string $organizationId,
        string $branchId,
        string $actorId,
        string $action,
        string $entityType,
        string $entityId,
        string $afterJson,
        string $createdAt
    ): string {
        $payloadToHash = implode('|', [
            $prevHash,
            $organizationId,
            $branchId,
            $actorId,
            $action,
            $entityType,
            $entityId,
            $afterJson,
            $createdAt,
        ]);

        return hash('sha256', $payloadToHash);
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc) {
            ksort($value);
        }

        foreach ($value as $k => $v) {
            $value[$k] = $this->sortRecursive($v);
        }

        return $value;
    }
}