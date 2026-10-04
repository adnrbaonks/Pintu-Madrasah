<?php

namespace App\Http\Controllers;

use App\Models\DocumentVerification;
use App\Support\Qr;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Portal verifikasi publik — tanpa login (untuk pemindai QR). */
class VerifyController extends Controller
{
    public function show(string $token): Response
    {
        $verification = DocumentVerification::where('token', $token)->first();

        if (! $verification) {
            return Inertia::render('Verify/Show', [
                'valid' => false,
                'token' => $token,
            ]);
        }

        return Inertia::render('Verify/Show', [
            'valid' => true,
            'documentType' => $verification->document_type,
            'snapshot' => $verification->snapshot,
            'issuedAt' => $verification->created_at->toIso8601String(),
            'updatedAt' => $verification->updated_at->toIso8601String(),
            'madrasahName' => $verification->snapshot['madrasah'] ?? null,
        ]);
    }

    public function qr(string $token): HttpResponse
    {
        $verification = DocumentVerification::where('token', $token)->firstOrFail();

        return response(Qr::png($verification->verifyUrl()), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
