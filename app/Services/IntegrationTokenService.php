<?php

namespace App\Services;

use App\Models\IntegrationLog;
use App\Models\IntegrationToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class IntegrationTokenService
{
    /**
     * Terbitkan token integrasi baru (mis. untuk bookmarklet EMIS Siswa / EMIS GTK).
     * Plain token hanya dikembalikan saat pembuatan dan tidak pernah disimpan di DB.
     *
     * @return array{token: IntegrationToken, plain_token: string}
     */
    public function issue(
        User $user,
        string $purpose,
        string $label,
        ?Carbon $expiresAt = null,
    ): array {
        $prefix = Str::lower(Str::random(8));
        $secret = Str::random(40);
        $plainToken = "pm_int_{$prefix}_{$secret}";
        $hash = hash('sha256', $plainToken);

        $token = IntegrationToken::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'token_prefix' => $prefix,
            'token_hash' => $hash,
            'token_last4' => substr($plainToken, -4),
            'label' => $label,
            'expires_at' => $expiresAt ?? now()->addDays(30),
            'created_by' => $user->id,
        ]);

        IntegrationLog::record(
            channel: $purpose,
            event: 'token.issued',
            status: 'success',
            context: [
                'label' => $label,
                'token_prefix' => $prefix,
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
            userId: $user->id,
            integrationTokenId: $token->id,
        );

        return [
            'token' => $token,
            'plain_token' => $plainToken,
        ];
    }

    /**
     * Verifikasi token integrasi secara constant-time dan cek purpose / masa berlaku / pencabutan.
     */
    public function verify(string $plainToken, ?string $requiredPurpose = null): ?IntegrationToken
    {
        $hash = hash('sha256', $plainToken);

        $token = IntegrationToken::where('token_hash', $hash)->first();
        if (! $token || ! hash_equals((string) $token->token_hash, $hash)) {
            return null;
        }

        if (! $token->isValidFor($requiredPurpose)) {
            return null;
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->update(['last_used_at' => now()]);
        }

        return $token;
    }

    /**
     * Cabut (revoke) token integrasi.
     */
    public function revoke(IntegrationToken $token, ?User $actor = null): IntegrationToken
    {
        $token->update(['revoked_at' => now()]);

        IntegrationLog::record(
            channel: $token->purpose,
            event: 'token.revoked',
            status: 'success',
            context: [
                'token_prefix' => $token->token_prefix,
            ],
            userId: $actor?->id ?? auth()->id(),
            integrationTokenId: $token->id,
        );

        return $token->fresh();
    }
}
