<?php

namespace App\Modules\IdentityAccess\Services;

use App\Modules\IdentityAccess\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TokenService
{
    private const ACCESS_TOKEN_TTL_MINUTES = 30;
    private const REFRESH_TOKEN_TTL_DAYS = 14;

    public function issueTokenPair(User $user, ?string $familyId = null): array
    {
        $accessTokenPlain = bin2hex(random_bytes(32));
        $accessTokenHash = hash('sha256', $accessTokenPlain);
        $accessExpiresAt = now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES);

        // เก็บ Access Token Metadata ใน Redis / Cache เพื่อตรวจสอบได้เร็วและเพิกถอนได้ทันที
        Cache::put(
            "api_access_token:{$accessTokenHash}",
            [
                'user_id' => (string) $user->id,
                'organization_id' => (string) $user->organization_id,
                'expires_at_ts' => $accessExpiresAt->timestamp,
            ],
            $accessExpiresAt
        );

        $refreshTokenPlain = bin2hex(random_bytes(40));
        $refreshTokenHash = hash('sha256', $refreshTokenPlain);
        $resolvedFamilyId = $familyId ?? (string) Str::uuid();
        $refreshExpiresAt = now()->addDays(self::REFRESH_TOKEN_TTL_DAYS);

        DB::table('refresh_tokens')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => (string) $user->organization_id,
            'user_id' => (string) $user->id,
            'token_hash' => $refreshTokenHash,
            'family_id' => $resolvedFamilyId,
            'expires_at' => $refreshExpiresAt,
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'access_token' => $accessTokenPlain,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL_MINUTES * 60,
            'refresh_token' => $refreshTokenPlain,
            'refresh_expires_at' => $refreshExpiresAt->toIso8601String(),
        ];
    }

    public function resolveAccessToken(string $plainToken): ?array
    {
        $hash = hash('sha256', $plainToken);
        $data = Cache::get("api_access_token:{$hash}");

        if (!is_array($data)) {
            return null;
        }

        if (($data['expires_at_ts'] ?? 0) < now()->timestamp) {
            Cache::forget("api_access_token:{$hash}");
            return null;
        }

        return $data;
    }

    public function revokeAccessToken(?string $plainToken): void
    {
        if (empty($plainToken)) {
            return;
        }

        Cache::forget('api_access_token:' . hash('sha256', $plainToken));
    }

    public function rotateRefreshToken(string $plainRefreshToken): array
    {
        $hash = hash('sha256', $plainRefreshToken);

        return DB::transaction(function () use ($hash): array {
            $record = DB::table('refresh_tokens')
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (!$record) {
                throw new RuntimeException('INVALID_REFRESH_TOKEN');
            }

            // หาก Refresh Token นี้ถูกยกเลิกไปแล้ว แสดงว่าอาจเกิด Token Reuse / Replay Attack -> ยกเลิกทั้ง Family
            if (!empty($record->revoked_at)) {
                DB::table('refresh_tokens')
                    ->where('family_id', $record->family_id)
                    ->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => now(),
                        'updated_at' => now(),
                    ]);

                throw new RuntimeException('REFRESH_TOKEN_REUSED');
            }

            if (now()->greaterThan($record->expires_at)) {
                throw new RuntimeException('REFRESH_TOKEN_EXPIRED');
            }

            DB::table('refresh_tokens')
                ->where('id', $record->id)
                ->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            $user = User::withoutGlobalScope('organization')
                ->where('id', $record->user_id)
                ->where('organization_id', $record->organization_id)
                ->first();

            if (!$user || $user->status !== 'ACTIVE') {
                throw new RuntimeException('USER_INACTIVE');
            }

            app()->instance('current_organization_id', (string) $user->organization_id);

            return $this->issueTokenPair($user, (string) $record->family_id);
        });
    }

    public function revokeRefreshToken(?string $plainRefreshToken): void
    {
        if (empty($plainRefreshToken)) {
            return;
        }

        $hash = hash('sha256', $plainRefreshToken);

        DB::table('refresh_tokens')
            ->where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }
}