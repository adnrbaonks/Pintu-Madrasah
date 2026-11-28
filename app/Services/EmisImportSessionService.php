<?php

namespace App\Services;

use App\Models\EmisGtkStaging;
use App\Models\EmisImportSession;
use App\Models\EmisImportSessionPage;
use App\Models\IntegrationLog;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use RuntimeException;
use Throwable;

class EmisImportSessionService
{
    public const STAGING_MUTATION_LOCK_KEY = 'emis_gtk:staging:mutation';

    /**
     * Hitung SHA-256 kanonis yang stabil untuk payload halaman import.
     * Urutan daftar item dipertahankan; kunci associative map diurutkan secara rekursif.
     *
     * @param  array<int, mixed>  $items
     */
    public function computePayloadHash(array $items): string
    {
        $normalized = $this->normalizeForHash($items);
        $canonicalJson = json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return hash('sha256', $canonicalJson);
    }

    /**
     * Proses penerimaan satu halaman import GTK beserta idempotensi, deteksi konflik,
     * penguncian baris sesi, dan klaim finalisasi satu-kali.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function ingestGtkPage(
        array $items,
        int $page,
        int $totalPages,
        ?int $totalCount,
        ?string $sessionId,
        string $ownerKey,
        ?string $authMethod = null,
    ): array {
        if ($page < 1 || $page > $totalPages) {
            return $this->errorOutcome(422, 'INVALID_PAGE', 'Nomor halaman melebihi total_pages.');
        }

        if (blank($sessionId)) {
            if ($page !== 1) {
                return $this->errorOutcome(
                    422,
                    'IMPORT_SESSION_REQUIRED',
                    'Halaman > 1 wajib menyertakan import_session_id dari respons halaman pertama.'
                );
            }

            $this->expireOverdueOpenSessions(limit: 50);

            $created = EmisImportSession::create([
                'entity_type' => 'staff',
                'status' => EmisImportSession::STATUS_OPEN,
                'owner_key' => $ownerKey,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'source' => 'bookmarklet',
                'started_at' => now(),
                'expires_at' => now()->addMinutes($this->sessionTtlMinutes()),
            ]);

            $sessionId = $created->id;
        }

        $payloadHash = $this->computePayloadHash($items);
        $itemsCount = count($items);

        /** @var array<string, mixed> $phaseOne */
        $phaseOne = $this->runWriteTransaction(function () use (
            $sessionId,
            $ownerKey,
            $page,
            $totalPages,
            $totalCount,
            $items,
            $itemsCount,
            $payloadHash
        ): array {
            $session = EmisImportSession::query()
                ->where('id', $sessionId)
                ->where('entity_type', 'staff')
                ->lockForUpdate()
                ->first();

            if (! $session || ! hash_equals((string) $session->owner_key, $ownerKey)) {
                return $this->errorOutcome(404, 'IMPORT_SESSION_NOT_FOUND', 'Sesi import tidak ditemukan.');
            }

            if ($session->status === EmisImportSession::STATUS_FINALIZING && $this->isFinalizingLeaseStale($session)) {
                $session->update([
                    'status' => EmisImportSession::STATUS_FAILED,
                    'finalizing_at' => null,
                    'failed_at' => now(),
                    'failure_reason' => 'Finalisasi sebelumnya melebihi batas waktu lease; siap dicoba ulang.',
                ]);
                $session->refresh();
            }

            if ($session->status === EmisImportSession::STATUS_COMPLETED) {
                return $this->errorOutcome(
                    409,
                    'IMPORT_SESSION_CLOSED',
                    'Sesi import sudah selesai.',
                    [
                        'import_session_id' => $session->id,
                        'status' => EmisImportSession::STATUS_COMPLETED,
                        'complete' => true,
                        'saved' => (int) ($session->saved_rows ?? 0),
                    ]
                );
            }

            if ($session->status === EmisImportSession::STATUS_FINALIZING) {
                return $this->errorOutcome(
                    409,
                    'IMPORT_FINALIZING',
                    'Sesi import sedang memfinalisasi data ke staging. Tunggu sebentar sebelum memeriksa status.',
                    [
                        'import_session_id' => $session->id,
                        'status' => EmisImportSession::STATUS_FINALIZING,
                        'complete' => false,
                    ]
                );
            }

            if ($session->status === EmisImportSession::STATUS_EXPIRED || $session->expires_at?->isPast()) {
                if ($session->status !== EmisImportSession::STATUS_EXPIRED) {
                    $session->update([
                        'status' => EmisImportSession::STATUS_EXPIRED,
                        'finalizing_at' => null,
                    ]);
                }
                EmisImportSessionPage::query()->where('session_id', $session->id)->delete();

                return $this->errorOutcome(
                    410,
                    'IMPORT_SESSION_EXPIRED',
                    'Sesi import kedaluwarsa. Mulai ulang dari halaman pertama.'
                );
            }

            if ($session->total_pages !== $totalPages) {
                return $this->errorOutcome(
                    422,
                    'IMPORT_SESSION_MISMATCH',
                    'total_pages tidak sesuai dengan sesi import.'
                );
            }

            if ($totalCount !== null && $session->total_count !== $totalCount) {
                return $this->errorOutcome(
                    422,
                    'IMPORT_SESSION_MISMATCH',
                    'total_count tidak sesuai dengan metadata sesi import.'
                );
            }

            $existingPage = EmisImportSessionPage::query()
                ->where('session_id', $session->id)
                ->where('page', $page)
                ->first();

            $idempotentReplay = false;
            if ($existingPage !== null) {
                if (! hash_equals((string) $existingPage->payload_hash, $payloadHash)) {
                    return $this->errorOutcome(
                        409,
                        'IMPORT_PAGE_CONFLICT',
                        'Nomor halaman ini sudah diterima dengan isi payload yang berbeda pada sesi import yang sama.'
                    );
                }

                $idempotentReplay = true;
            } else {
                EmisImportSessionPage::create([
                    'session_id' => $session->id,
                    'page' => $page,
                    'items_count' => $itemsCount,
                    'payload_hash' => $payloadHash,
                    'payload' => $items,
                ]);
            }

            $aggregate = EmisImportSessionPage::query()
                ->where('session_id', $session->id)
                ->selectRaw('COUNT(*) as pages_count, COALESCE(SUM(items_count), 0) as rows_sum')
                ->first();

            $receivedPages = (int) ($aggregate->pages_count ?? 0);
            $receivedRows = (int) ($aggregate->rows_sum ?? 0);

            if ($receivedPages < $session->total_pages) {
                $session->update([
                    'status' => EmisImportSession::STATUS_OPEN,
                    'received_pages' => $receivedPages,
                    'received_rows' => $receivedRows,
                    'failed_at' => null,
                    'failure_reason' => null,
                ]);

                return [
                    'action' => 'accepted',
                    'session_id' => $session->id,
                    'status' => EmisImportSession::STATUS_OPEN,
                    'received_pages' => $receivedPages,
                    'received_rows' => $receivedRows,
                    'total_pages' => $session->total_pages,
                    'idempotent_replay' => $idempotentReplay,
                ];
            }

            if ($session->total_count !== null && $receivedRows !== $session->total_count) {
                $session->update([
                    'status' => EmisImportSession::STATUS_FAILED,
                    'received_pages' => $receivedPages,
                    'received_rows' => $receivedRows,
                    'finalizing_at' => null,
                    'failed_at' => now(),
                    'failure_reason' => "Jumlah baris unik yang diterima ({$receivedRows}) tidak sesuai dengan total_count ({$session->total_count}).",
                ]);

                return $this->errorOutcome(
                    422,
                    'IMPORT_COUNT_MISMATCH',
                    'Jumlah data yang diterima tidak sama dengan total_count. Mulai sesi import baru dengan seluruh halaman lengkap.'
                );
            }

            $session->update([
                'status' => EmisImportSession::STATUS_FINALIZING,
                'finalizing_at' => now(),
                'received_pages' => $receivedPages,
                'received_rows' => $receivedRows,
                'failure_reason' => null,
            ]);

            return [
                'action' => 'finalize',
                'session_id' => $session->id,
                'received_pages' => $receivedPages,
                'received_rows' => $receivedRows,
                'total_pages' => $session->total_pages,
            ];
        }, $sessionId);

        if (isset($phaseOne['http_status'])) {
            return $phaseOne;
        }

        if (($phaseOne['action'] ?? '') === 'finalize') {
            return $this->executeFinalization(
                sessionId: (string) $phaseOne['session_id'],
                page: $page,
                authMethod: $authMethod,
            );
        }

        $receivedPages = (int) $phaseOne['received_pages'];
        $sessionTotalPages = (int) $phaseOne['total_pages'];
        $progress = $sessionTotalPages > 0
            ? min(99, (int) round(($receivedPages / $sessionTotalPages) * 100))
            : 100;

        Cache::put('emis_gtk:pull:progress', [
            'progress' => $progress,
            'import_session_id' => $phaseOne['session_id'],
            'status' => "Menerima data halaman {$receivedPages} dari {$sessionTotalPages}...",
        ], now()->addHours(2));

        return [
            'http_status' => 200,
            'body' => [
                'success' => true,
                'import_session_id' => $phaseOne['session_id'],
                'status' => $phaseOne['status'],
                'saved' => 0,
                'page' => $page,
                'received_pages' => $receivedPages,
                'total_pages' => $sessionTotalPages,
                'complete' => false,
                'idempotent_replay' => (bool) ($phaseOne['idempotent_replay'] ?? false),
                'message' => "Halaman {$page}/{$sessionTotalPages} diterima ({$receivedPages} halaman terkumpul).",
            ],
        ];
    }

    /**
     * Hapus baris pending staging GTK dengan koordinasi lock terhadap finalisasi aktif.
     *
     * @return array{ok: bool, removed: int}
     */
    public function clearPendingGtkStaging(?string $authMethod = null): array
    {
        $lock = $this->acquireCacheLock(self::STAGING_MUTATION_LOCK_KEY, 60, 5);
        if ($lock === null) {
            return ['ok' => false, 'removed' => 0];
        }

        try {
            $removed = $this->runWriteTransaction(
                fn (): int => EmisGtkStaging::query()->where('status', 'pending')->delete()
            );

            IntegrationLog::record('emis_gtk', 'staging.cleared', 'success', [
                'removed_pending_rows' => $removed,
                'import_session_id' => null,
                'auth_method' => $authMethod,
            ]);

            return ['ok' => true, 'removed' => $removed];
        } finally {
            $lock->release();
        }
    }

    /**
     * Bersihkan sesi import kedaluwarsa, payload sesi gagal yang melewati retensi,
     * serta metadata terminal yang melewati masa retensi audit secara ber-batch dan idempoten.
     *
     * @return array{
     *     expired_open_sessions: int,
     *     recovered_stale_finalizing_sessions: int,
     *     purged_failed_payload_sessions: int,
     *     pruned_terminal_metadata_sessions: int,
     *     deleted_page_rows: int
     * }
     */
    public function cleanupSessions(?int $batchSize = null): array
    {
        $limit = max(1, $batchSize ?? $this->cleanupBatchSize());

        $expiredStats = $this->expireOverdueOpenSessions($limit, drainAll: true);
        $recoveredStale = $this->recoverStaleFinalizingSessions($limit);
        $failedPayloadStats = $this->purgeExpiredFailedPayloads($limit);
        $prunedMetadata = $this->pruneExpiredTerminalMetadata($limit);

        $summary = [
            'expired_open_sessions' => $expiredStats['sessions'],
            'recovered_stale_finalizing_sessions' => $recoveredStale,
            'purged_failed_payload_sessions' => $failedPayloadStats['sessions'],
            'pruned_terminal_metadata_sessions' => $prunedMetadata,
            'deleted_page_rows' => $expiredStats['pages'] + $failedPayloadStats['pages'],
        ];

        if (array_sum($summary) > 0) {
            Log::info('[EMIS Import] Cleanup sesi import selesai', $summary);
        }

        return $summary;
    }

    /**
     * Eksekusi finalisasi penggantian staging secara atomik oleh satu pemenang klaim.
     *
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function executeFinalization(string $sessionId, int $page, ?string $authMethod): array
    {
        $lock = $this->acquireCacheLock(self::STAGING_MUTATION_LOCK_KEY, $this->finalizingLeaseSeconds(), 15);
        if ($lock === null) {
            $this->runWriteTransaction(function () use ($sessionId): void {
                EmisImportSession::query()
                    ->where('id', $sessionId)
                    ->where('status', EmisImportSession::STATUS_FINALIZING)
                    ->update([
                        'status' => EmisImportSession::STATUS_FAILED,
                        'finalizing_at' => null,
                        'failed_at' => now(),
                        'failure_reason' => 'Penggantian staging GTK lain sedang berlangsung; silakan ulangi halaman terakhir.',
                    ]);
            }, $sessionId);

            return $this->errorOutcome(
                409,
                'IMPORT_IN_PROGRESS',
                'Penggantian staging GTK lain sedang berjalan. Kirim ulang halaman terakhir sebentar lagi.'
            );
        }

        try {
            $result = $this->runWriteTransaction(function () use ($sessionId, $authMethod): array {
                $session = EmisImportSession::query()
                    ->where('id', $sessionId)
                    ->lockForUpdate()
                    ->first();

                if (! $session) {
                    throw new RuntimeException('Sesi import tidak ditemukan saat finalisasi.');
                }

                if ($session->status === EmisImportSession::STATUS_COMPLETED) {
                    return [
                        'already_completed' => true,
                        'saved' => (int) ($session->saved_rows ?? 0),
                        'session' => $session,
                    ];
                }

                if ($session->status !== EmisImportSession::STATUS_FINALIZING) {
                    throw new RuntimeException("Status sesi tidak valid untuk finalisasi ({$session->status}).");
                }

                $pages = EmisImportSessionPage::query()
                    ->where('session_id', $session->id)
                    ->orderBy('page')
                    ->get();

                if ($pages->count() !== $session->total_pages) {
                    throw new RuntimeException('Jumlah halaman tersimpan tidak lengkap untuk finalisasi.');
                }

                $expectedPageNumber = 1;
                $verifiedRows = 0;
                foreach ($pages as $pageRow) {
                    if ($pageRow->page !== $expectedPageNumber) {
                        throw new RuntimeException("Urutan halaman tidak berkesinambungan pada halaman {$expectedPageNumber}.");
                    }
                    if (! is_array($pageRow->payload) || $pageRow->payload === []) {
                        throw new RuntimeException("Payload halaman {$expectedPageNumber} kosong atau tidak valid.");
                    }
                    if (
                        $pageRow->payload_hash !== ''
                        && ! hash_equals($pageRow->payload_hash, $this->computePayloadHash($pageRow->payload))
                    ) {
                        throw new RuntimeException("Integritas hash payload gagal pada halaman {$expectedPageNumber}.");
                    }

                    $verifiedRows += count($pageRow->payload);
                    $expectedPageNumber++;
                }

                if ($session->total_count !== null && $verifiedRows !== $session->total_count) {
                    throw new RuntimeException('Total baris terverifikasi tidak sesuai dengan total_count sesi.');
                }

                $saved = 0;
                foreach ($pages as $pageRow) {
                    $saved += $this->storeGtkImportItems($pageRow->payload, $session->id);
                }

                $removed = EmisGtkStaging::query()
                    ->where('status', 'pending')
                    ->where(function ($q) use ($session) {
                        $q->whereNull('import_session_id')->orWhere('import_session_id', '!=', $session->id);
                    })
                    ->delete();

                IntegrationLog::record('emis_gtk', 'staging.cleared', 'success', [
                    'removed_pending_rows' => $removed,
                    'import_session_id' => $session->id,
                    'auth_method' => $authMethod,
                ]);

                IntegrationLog::record('emis_gtk', 'staging.replaced', 'success', [
                    'import_session_id' => $session->id,
                    'total_pages' => $session->total_pages,
                    'received_rows' => $verifiedRows,
                    'saved_rows' => $saved,
                    'removed_pending_rows' => $removed,
                    'auth_method' => $authMethod,
                ]);

                $session->update([
                    'status' => EmisImportSession::STATUS_COMPLETED,
                    'received_pages' => $session->total_pages,
                    'received_rows' => $verifiedRows,
                    'saved_rows' => $saved,
                    'finalizing_at' => null,
                    'completed_at' => now(),
                    'failed_at' => null,
                    'failure_reason' => null,
                ]);

                EmisImportSessionPage::query()->where('session_id', $session->id)->delete();

                return [
                    'already_completed' => false,
                    'saved' => $saved,
                    'session' => $session->fresh(),
                ];
            });

            if ($result['already_completed']) {
                return $this->errorOutcome(409, 'IMPORT_SESSION_CLOSED', 'Sesi import sudah selesai diproses.');
            }

            /** @var EmisImportSession $completedSession */
            $completedSession = $result['session'];
            $saved = (int) $result['saved'];

            Cache::put('emis_gtk:pull:progress', [
                'progress' => 100,
                'import_session_id' => $completedSession->id,
                'status' => "Selesai! {$completedSession->received_rows} data GTK berhasil diterima dari bookmarklet.",
            ], now()->addHours(2));

            return [
                'http_status' => 200,
                'body' => [
                    'success' => true,
                    'import_session_id' => $completedSession->id,
                    'status' => EmisImportSession::STATUS_COMPLETED,
                    'saved' => $saved,
                    'page' => $page,
                    'received_pages' => $completedSession->received_pages,
                    'total_pages' => $completedSession->total_pages,
                    'complete' => true,
                    'message' => "Import selesai: {$saved} data GTK masuk staging.",
                ],
            ];
        } catch (Throwable $e) {
            $safeReason = $this->sanitizeFailureReason($e);

            Log::error('[EMIS GTK] Finalisasi import gagal', [
                'import_session_id' => $sessionId,
                'exception_class' => $e::class,
                'reason' => $safeReason,
            ]);

            $this->runWriteTransaction(function () use ($sessionId, $safeReason): void {
                EmisImportSession::query()
                    ->where('id', $sessionId)
                    ->whereIn('status', [EmisImportSession::STATUS_FINALIZING, EmisImportSession::STATUS_OPEN])
                    ->update([
                        'status' => EmisImportSession::STATUS_FAILED,
                        'finalizing_at' => null,
                        'failed_at' => now(),
                        'failure_reason' => $safeReason,
                    ]);
            }, $sessionId);

            return $this->errorOutcome(
                500,
                'IMPORT_FINALIZE_FAILED',
                'Penggantian staging gagal; staging lama tidak berubah. Kirim ulang halaman terakhir.'
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Simpan item halaman ke tabel staging GTK dengan mempertahankan riwayat committed.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function storeGtkImportItems(array $items, string $sessionId): int
    {
        $saved = 0;
        foreach ($items as $item) {
            $name = mb_strtoupper(trim((string) ($item['name'] ?? '')));
            if ($name === '') {
                continue;
            }

            $uuid = $item['uuid'] ?? null;
            $identity = $uuid
                ? ['emis_gtk_id' => $uuid]
                : $this->gtkIdentity($item, $name);

            $data = [
                'emis_gtk_id' => $uuid,
                'full_name_with_titles' => $name,
                'nik' => $item['nik'] ?? null,
                'nip' => $item['nip'] ?? null,
                'npk' => $item['npk'] ?? null,
                'nuptk' => $item['nuptk'] ?? null,
                'nrg' => $item['nrg'] ?? null,
                'peg_id' => $item['peg_id'] ?? null,
                'function_role' => $item['fungsi'] ?? $item['function_role'] ?? null,
                'employment_status' => $item['employment'] ?? $item['employment_status'] ?? null,
                'source' => 'bookmarklet',
                'last_seen_at' => now(),
                'raw_data' => $item,
                'status' => 'pending',
                'import_session_id' => $sessionId,
            ];

            if (array_key_exists('active', $item)) {
                $data['k_aktif'] = filter_var($item['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            if (array_key_exists('is_sertifikasi', $item)) {
                $data['is_sertifikasi'] = filter_var($item['is_sertifikasi'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            $existing = EmisGtkStaging::query()->where($identity)->first();
            if ($existing && $existing->status === 'committed') {
                continue;
            }

            if ($existing) {
                $existing->update($data);
            } else {
                EmisGtkStaging::create($identity + $data);
            }
            $saved++;
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function gtkIdentity(array $item, string $name): array
    {
        foreach (['nik', 'nuptk', 'nip', 'npk'] as $column) {
            if (filled($item[$column] ?? null)) {
                return [$column => (string) $item[$column]];
            }
        }

        return ['full_name_with_titles' => $name];
    }

    /**
     * Tandai sesi `open` yang melewati `expires_at` menjadi `expired` dan bersihkan payloadnya,
     * tanpa pernah menyentuh sesi yang sudah beralih ke `finalizing`.
     *
     * @return array{sessions: int, pages: int}
     */
    private function expireOverdueOpenSessions(int $limit, bool $drainAll = false): array
    {
        $expiredSessions = 0;
        $deletedPages = 0;

        do {
            $candidateIds = EmisImportSession::query()
                ->where('status', EmisImportSession::STATUS_OPEN)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->orderBy('expires_at')
                ->limit($limit)
                ->pluck('id');

            if ($candidateIds->isEmpty()) {
                break;
            }

            $batchResult = $this->runWriteTransaction(function () use ($candidateIds): array {
                $lockedIds = EmisImportSession::query()
                    ->whereIn('id', $candidateIds)
                    ->where('status', EmisImportSession::STATUS_OPEN)
                    ->where('expires_at', '<', now())
                    ->lockForUpdate()
                    ->pluck('id');

                if ($lockedIds->isEmpty()) {
                    return ['sessions' => 0, 'pages' => 0];
                }

                $pagesRemoved = EmisImportSessionPage::query()
                    ->whereIn('session_id', $lockedIds)
                    ->delete();

                $sessionsUpdated = EmisImportSession::query()
                    ->whereIn('id', $lockedIds)
                    ->where('status', EmisImportSession::STATUS_OPEN)
                    ->update(['status' => EmisImportSession::STATUS_EXPIRED]);

                return ['sessions' => $sessionsUpdated, 'pages' => $pagesRemoved];
            });

            $expiredSessions += $batchResult['sessions'];
            $deletedPages += $batchResult['pages'];
        } while ($drainAll && $candidateIds->count() >= $limit && $batchResult['sessions'] > 0);

        return ['sessions' => $expiredSessions, 'pages' => $deletedPages];
    }

    /**
     * Pulihkan sesi yang terjebak pada status `finalizing` melampaui batas lease.
     */
    private function recoverStaleFinalizingSessions(int $limit): int
    {
        $cutoff = now()->subSeconds($this->finalizingLeaseSeconds());
        $recovered = 0;

        do {
            $candidateIds = EmisImportSession::query()
                ->where('status', EmisImportSession::STATUS_FINALIZING)
                ->where(function ($q) use ($cutoff) {
                    $q->whereNull('finalizing_at')->orWhere('finalizing_at', '<', $cutoff);
                })
                ->limit($limit)
                ->pluck('id');

            if ($candidateIds->isEmpty()) {
                break;
            }

            $batchRecovered = $this->runWriteTransaction(function () use ($candidateIds, $cutoff): int {
                $lockedIds = EmisImportSession::query()
                    ->whereIn('id', $candidateIds)
                    ->where('status', EmisImportSession::STATUS_FINALIZING)
                    ->where(function ($q) use ($cutoff) {
                        $q->whereNull('finalizing_at')->orWhere('finalizing_at', '<', $cutoff);
                    })
                    ->lockForUpdate()
                    ->pluck('id');

                if ($lockedIds->isEmpty()) {
                    return 0;
                }

                return EmisImportSession::query()
                    ->whereIn('id', $lockedIds)
                    ->where('status', EmisImportSession::STATUS_FINALIZING)
                    ->update([
                        'status' => EmisImportSession::STATUS_FAILED,
                        'finalizing_at' => null,
                        'failed_at' => now(),
                        'failure_reason' => 'Finalisasi melebihi batas waktu lease dan dipulihkan oleh scheduler.',
                    ]);
            });

            $recovered += $batchRecovered;
        } while ($candidateIds->count() >= $limit && $batchRecovered > 0);

        return $recovered;
    }

    /**
     * Hapus payload halaman untuk sesi `failed` yang telah melewati masa retensi payload sementara,
     * sambil tetap mempertahankan metadata sesi untuk audit.
     *
     * @return array{sessions: int, pages: int}
     */
    private function purgeExpiredFailedPayloads(int $limit): array
    {
        $cutoff = now()->subHours($this->failedPayloadRetentionHours());
        $purgedSessions = 0;
        $deletedPages = 0;

        do {
            $candidateIds = EmisImportSession::query()
                ->where('status', EmisImportSession::STATUS_FAILED)
                ->whereHas('pages')
                ->where(function ($q) use ($cutoff) {
                    $q->where(function ($sub) use ($cutoff) {
                        $sub->whereNotNull('failed_at')->where('failed_at', '<', $cutoff);
                    })->orWhere(function ($sub) use ($cutoff) {
                        $sub->whereNull('failed_at')->where('updated_at', '<', $cutoff);
                    });
                })
                ->limit($limit)
                ->pluck('id');

            if ($candidateIds->isEmpty()) {
                break;
            }

            $batch = $this->runWriteTransaction(function () use ($candidateIds): array {
                $lockedIds = EmisImportSession::query()
                    ->whereIn('id', $candidateIds)
                    ->where('status', EmisImportSession::STATUS_FAILED)
                    ->lockForUpdate()
                    ->pluck('id');

                if ($lockedIds->isEmpty()) {
                    return ['sessions' => 0, 'pages' => 0];
                }

                $pagesRemoved = EmisImportSessionPage::query()
                    ->whereIn('session_id', $lockedIds)
                    ->delete();

                return ['sessions' => $pagesRemoved > 0 ? $lockedIds->count() : 0, 'pages' => $pagesRemoved];
            });

            $purgedSessions += $batch['sessions'];
            $deletedPages += $batch['pages'];
        } while ($candidateIds->count() >= $limit && $batch['pages'] > 0);

        return ['sessions' => $purgedSessions, 'pages' => $deletedPages];
    }

    /**
     * Hapus metadata sesi berstatus terminal (`completed`, `expired`, `failed`) yang telah
     * melampaui periode retensi audit agar tabel tidak tumbuh tanpa batas.
     */
    private function pruneExpiredTerminalMetadata(int $limit): int
    {
        $cutoff = now()->subDays($this->metadataRetentionDays());
        $pruned = 0;

        do {
            $candidateIds = EmisImportSession::query()
                ->where(function ($q) use ($cutoff) {
                    $q->where(function ($sub) use ($cutoff) {
                        $sub->where('status', EmisImportSession::STATUS_COMPLETED)
                            ->whereNotNull('completed_at')
                            ->where('completed_at', '<', $cutoff);
                    })->orWhere(function ($sub) use ($cutoff) {
                        $sub->where('status', EmisImportSession::STATUS_EXPIRED)
                            ->where(function ($inner) use ($cutoff) {
                                $inner->where('expires_at', '<', $cutoff)
                                    ->orWhere(function ($fallback) use ($cutoff) {
                                        $fallback->whereNull('expires_at')->where('updated_at', '<', $cutoff);
                                    });
                            });
                    })->orWhere(function ($sub) use ($cutoff) {
                        $sub->where('status', EmisImportSession::STATUS_FAILED)
                            ->where(function ($inner) use ($cutoff) {
                                $inner->where(function ($f) use ($cutoff) {
                                    $f->whereNotNull('failed_at')->where('failed_at', '<', $cutoff);
                                })->orWhere(function ($f) use ($cutoff) {
                                    $f->whereNull('failed_at')->where('updated_at', '<', $cutoff);
                                });
                            });
                    });
                })
                ->limit($limit)
                ->pluck('id');

            if ($candidateIds->isEmpty()) {
                break;
            }

            $batchDeleted = $this->runWriteTransaction(function () use ($candidateIds): int {
                $lockedIds = EmisImportSession::query()
                    ->whereIn('id', $candidateIds)
                    ->whereIn('status', [
                        EmisImportSession::STATUS_COMPLETED,
                        EmisImportSession::STATUS_EXPIRED,
                        EmisImportSession::STATUS_FAILED,
                    ])
                    ->lockForUpdate()
                    ->pluck('id');

                if ($lockedIds->isEmpty()) {
                    return 0;
                }

                EmisImportSessionPage::query()->whereIn('session_id', $lockedIds)->delete();

                return EmisImportSession::query()->whereIn('id', $lockedIds)->delete();
            });

            $pruned += $batchDeleted;
        } while ($candidateIds->count() >= $limit && $batchDeleted > 0);

        return $pruned;
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
        }

        ksort($value);

        $sorted = [];
        foreach ($value as $key => $val) {
            $sorted[$key] = $this->normalizeForHash($val);
        }

        return $sorted;
    }

    private function isFinalizingLeaseStale(EmisImportSession $session): bool
    {
        if ($session->finalizing_at === null) {
            return true;
        }

        return $session->finalizing_at->lt(now()->subSeconds($this->finalizingLeaseSeconds()));
    }

    /**
     * Sanitasi pesan error agar tidak pernah menyimpan SQL bindings atau deretan angka identitas PII.
     */
    private function sanitizeFailureReason(Throwable $e): string
    {
        if ($e instanceof QueryException || $e instanceof PDOException) {
            $sqlState = is_scalar($e->getCode()) ? (string) $e->getCode() : 'UNKNOWN';

            return "Kesalahan database [SQLSTATE {$sqlState}] saat finalisasi staging.";
        }

        $cleaned = preg_replace('/\b\d{8,20}\b/', '[REDACTED]', $e->getMessage()) ?? 'Kesalahan internal saat finalisasi.';

        return mb_substr(trim($cleaned), 0, 300);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function errorOutcome(int $status, string $code, string $message, array $extra = []): array
    {
        return [
            'http_status' => $status,
            'body' => array_merge([
                'success' => false,
                'message' => $message,
                'error' => ['code' => $code],
            ], $extra),
        ];
    }

    private function sessionTtlMinutes(): int
    {
        return max(1, (int) config('data_ownership.emis_import.session_ttl_minutes', 120));
    }

    private function finalizingLeaseSeconds(): int
    {
        return max(10, (int) config('data_ownership.emis_import.finalizing_lease_seconds', 300));
    }

    private function failedPayloadRetentionHours(): int
    {
        return max(1, (int) config('data_ownership.emis_import.failed_payload_retention_hours', 24));
    }

    private function metadataRetentionDays(): int
    {
        return max(1, (int) config('data_ownership.emis_import.metadata_retention_days', 30));
    }

    private function cleanupBatchSize(): int
    {
        return max(1, (int) config('data_ownership.emis_import.cleanup_batch_size', 200));
    }

    /**
     * Jalankan transaksi tulis yang kompatibel dengan MySQL 8 (`SELECT ... FOR UPDATE`)
     * dan SQLite multi-proses (mempromosikan transaksi dari `NONE -> RESERVED` dengan
     * `busy_timeout` sebelum `SELECT` dieksekusi agar tidak terjadi upgrade deadlock `SHARED -> RESERVED`).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function runWriteTransaction(callable $callback, ?string $anchorSessionId = null): mixed
    {
        $connection = DB::connection();
        $isSqliteTopLevel = $connection->getDriverName() === 'sqlite' && $connection->transactionLevel() === 0;

        return DB::transaction(function () use ($callback, $isSqliteTopLevel, $anchorSessionId) {
            if ($isSqliteTopLevel) {
                DB::statement(
                    'UPDATE emis_import_sessions SET id = id WHERE id = ?',
                    [$anchorSessionId ?? '']
                );
            }

            return $callback();
        }, attempts: 3);
    }

    /**
     * Ambil distributed cache lock dengan batas tunggu tanpa melempar LockTimeoutException.
     */
    private function acquireCacheLock(string $key, int $ttlSeconds, int $waitSeconds): ?Lock
    {
        $lock = Cache::lock($key, $ttlSeconds);

        try {
            return $lock->block($waitSeconds) ? $lock : null;
        } catch (LockTimeoutException) {
            return null;
        }
    }
}
