<?php

namespace App\Modules\AuditTrail\Console;

use App\Modules\AuditTrail\Services\AuditTrailService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyAuditChainCommand extends Command
{
    protected $signature = 'audit:verify-chain {--organization_id= : Verify a specific organization UUID}';
    protected $description = 'Verify cryptographic hash chain integrity of audit_logs';

    public function __construct(
        private readonly AuditTrailService $auditTrailService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $orgFilter = $this->option('organization_id');
        $orgIdsQuery = DB::table('audit_logs')->select('organization_id')->distinct();
        if (! empty($orgFilter)) {
            $orgIdsQuery->where('organization_id', (string) $orgFilter);
        }

        $orgIds = $orgIdsQuery->pluck('organization_id');
        if ($orgIds->isEmpty()) {
            $this->info('No audit log records found to verify.');
            return self::SUCCESS;
        }

        $hasFailure = false;
        foreach ($orgIds as $orgId) {
            $expectedPrevHash = str_repeat('0', 64);
            $checked = 0;

            $logs = DB::table('audit_logs')
                ->where('organization_id', $orgId)
                ->orderBy('id')
                ->cursor();

            foreach ($logs as $log) {
                $checked++;

                if ((string) $log->prev_hash !== $expectedPrevHash) {
                    $this->error("Chain break at audit_logs.id={$log->id} (org={$orgId}): prev_hash mismatch.");
                    $hasFailure = true;
                }

                $rawAfterCol = $log->after_data !== null ? (string) $log->after_data : '';
                $afterData = $log->after_data !== null
                    ? (is_string($log->after_data) ? json_decode($log->after_data, true) : (array) $log->after_data)
                    : null;

                $jsonCandidates = [
                    $rawAfterCol,
                    $afterData !== null ? (string) json_encode($afterData, JSON_UNESCAPED_UNICODE) : '',
                    $this->auditTrailService->normalizeJson($afterData),
                ];
                $jsonVariants = array_values(array_unique(array_filter(
                    $jsonCandidates,
                    static fn ($v) => $v !== null
                )));
                if ($log->after_data === null) {
                    $jsonVariants[] = '';
                    $jsonVariants = array_values(array_unique($jsonVariants));
                }

                $createdAtStr = (string) $log->created_at;
                $parsedUtc = CarbonImmutable::parse($createdAtStr)->utc();
                $timeVariants = array_values(array_unique([
                    $createdAtStr,
                    $parsedUtc->toIso8601ZuluString(),
                    $parsedUtc->format('Y-m-d\TH:i:s.u\Z'),
                    $parsedUtc->format('Y-m-d\TH:i:s\Z'),
                ]));

                $matched = false;
                foreach ($jsonVariants as $jsonStr) {
                    foreach ($timeVariants as $timeStr) {
                        $candidateHash = $this->auditTrailService->computeHash(
                            prevHash: (string) $log->prev_hash,
                            organizationId: (string) $log->organization_id,
                            branchId: (string) ($log->branch_id ?? ''),
                            actorId: (string) ($log->actor_id ?? ''),
                            action: (string) $log->action,
                            entityType: (string) $log->entity_type,
                            entityId: (string) $log->entity_id,
                            afterJson: $jsonStr,
                            createdAt: $timeStr
                        );

                        if (hash_equals((string) $log->record_hash, $candidateHash)) {
                            $matched = true;
                            break 2;
                        }
                    }
                }

                if (! $matched) {
                    $this->error("Hash tamper detected at audit_logs.id={$log->id} (org={$orgId}).");
                    $hasFailure = true;
                }

                $expectedPrevHash = (string) $log->record_hash;
            }

            $this->info("Organization {$orgId}: verified {$checked} audit records.");
        }

        if ($hasFailure) {
            $this->warn('Audit chain verification FAILED.');
            return self::FAILURE;
        }

        $this->info('Audit chain verification PASSED.');
        return self::SUCCESS;
    }
}