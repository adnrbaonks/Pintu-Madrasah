<?php

namespace App\Http\Controllers;

use App\Models\IntegrationToken;
use App\Services\IntegrationTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrationTokenController extends Controller
{
    public function __construct(
        private IntegrationTokenService $tokenService,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'purpose' => [
                'required',
                Rule::in([
                    IntegrationToken::PURPOSE_EMIS_SISWA,
                    IntegrationToken::PURPOSE_EMIS_GTK,
                ]),
            ],
            'label' => ['required', 'string', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($validated['expires_in_days'] ?? 30);
        $issued = $this->tokenService->issue(
            user: $request->user(),
            purpose: $validated['purpose'],
            label: $validated['label'],
            expiresAt: now()->addDays($days),
        );

        return back()
            ->with('new_integration_token', $issued['plain_token'])
            ->with('success', "Token bookmarklet '{$issued['token']->label}' berhasil dibuat. Salin bookmarklet sekarang — token rahasia hanya ditampilkan satu kali.");
    }

    public function revoke(Request $request, IntegrationToken $integrationToken): RedirectResponse
    {
        $this->tokenService->revoke($integrationToken, $request->user());

        return back()->with('success', "Token bookmarklet '{$integrationToken->label}' telah dicabut.");
    }
}
