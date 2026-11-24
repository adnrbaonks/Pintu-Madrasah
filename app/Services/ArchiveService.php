<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Archive;
use App\Models\ArchiveAccessLog;
use App\Models\ArchiveCategory;
use App\Models\ArchiveVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ArchiveService
{
    public const DISK = 'local';

    /**
     * Pastikan kategori default tersedia jika tabel masih kosong.
     */
    public function ensureDefaultCategories(): void
    {
        if (ArchiveCategory::query()->exists()) {
            return;
        }

        $defaults = [
            [
                'code' => 'SK-KAMAD',
                'name' => 'Surat Keputusan Kepala Madrasah',
                'description' => 'SK Pembagian Tugas, Kepanitiaan, dan Penetapan Kebijakan Madrasah',
                'retention_days' => 1825,
                'default_visibility' => Archive::VISIBILITY_INTERNAL,
            ],
            [
                'code' => 'KEPEGAWAIAN',
                'name' => 'Berkas Kepegawaian & GTK',
                'description' => 'Dokumen SK, Sertifikat, PKG, dan Berkas Pribadi GTK',
                'retention_days' => 3650,
                'default_visibility' => Archive::VISIBILITY_PRIVATE,
            ],
            [
                'code' => 'KURIKULUM',
                'name' => 'Dokumen Kurikulum & Akademik',
                'description' => 'KOM/KTSP, Kalender Pendidikan, Pedoman Akademik, dan SKL',
                'retention_days' => 1825,
                'default_visibility' => Archive::VISIBILITY_INTERNAL,
            ],
            [
                'code' => 'TATA-USAHA',
                'name' => 'Administrasi Umum & Persuratan',
                'description' => 'Laporan Bulanan, Berita Acara, MoU, dan Arsip Ketatausahaan',
                'retention_days' => 1095,
                'default_visibility' => Archive::VISIBILITY_ROLE_RESTRICTED,
            ],
        ];

        foreach ($defaults as $item) {
            ArchiveCategory::firstOrCreate(
                ['code' => $item['code']],
                array_merge($item, ['is_active' => true])
            );
        }
    }

    /**
     * Periksa apakah pengguna berhak melihat metadata & mengunduh berkas arsip.
     */
    public function canView(User $user, Archive $archive): bool
    {
        // Admin sistem selalu dapat mengakses arsip
        if ($user->hasRole('admin')) {
            return true;
        }

        // Pembuat atau pemilik dokumen selalu berhak mengakses arsipnya sendiri
        if ($archive->created_by === $user->id || $archive->owner_user_id === $user->id) {
            return true;
        }

        if ($archive->owner_staff_id && $user->staff && $user->staff->id === $archive->owner_staff_id) {
            return true;
        }

        // Internal: seluruh GTK / civitas madrasah terautentikasi
        if ($archive->visibility === Archive::VISIBILITY_INTERNAL) {
            return true;
        }

        // Role-restricted: WAJIB mengikuti allowlist secara ketat tanpa bypass role umum
        if ($archive->visibility === Archive::VISIBILITY_ROLE_RESTRICTED) {
            $allowed = is_array($archive->allowed_roles) ? $archive->allowed_roles : [];

            return $user->hasAnyRole($allowed);
        }

        // Privat: hanya pemilik dokumen dan admin (sudah ditangani di atas)
        return false;
    }

    /**
     * Periksa apakah pengguna berhak membuat/mengubah/mengunggah versi baru arsip.
     */
    public function canManage(User $user, ?Archive $archive = null): bool
    {
        if ($user->hasAnyRole(['admin', 'kepala_madrasah', 'kaur_tu', 'staf', 'operator'])) {
            return true;
        }

        if ($archive && ($archive->created_by === $user->id || $archive->owner_user_id === $user->id)) {
            return true;
        }

        return false;
    }

    /**
     * Simpan arsip baru beserta versi pertama (v1) secara atomik.
     *
     * @param  array<string, mixed>  $data
     */
    public function createArchive(array $data, UploadedFile $file, User $actor, ?Request $request = null): Archive
    {
        return DB::transaction(function () use ($data, $file, $actor, $request): Archive {
            /** @var ArchiveCategory $category */
            $category = ArchiveCategory::findOrFail($data['archive_category_id']);

            $documentDate = ! empty($data['document_date'])
                ? (string) $data['document_date']
                : now()->toDateString();

            $retentionUntil = $data['retention_until'] ?? null;
            if (! $retentionUntil && $category->retention_days) {
                $retentionUntil = now()->parse($documentDate)->addDays($category->retention_days)->toDateString();
            }

            $activeYearId = $data['academic_year_id']
                ?? AcademicYear::where('is_active', true)->value('id');

            $archiveNumber = ! empty($data['archive_number'])
                ? (string) $data['archive_number']
                : $this->generateArchiveNumber($category);

            $visibility = (string) ($data['visibility'] ?? $category->default_visibility ?? Archive::VISIBILITY_INTERNAL);

            $archive = Archive::create([
                'archive_category_id' => $category->id,
                'organization_unit_id' => $data['organization_unit_id'] ?? null,
                'academic_year_id' => $activeYearId,
                'archive_number' => $archiveNumber,
                'title' => (string) $data['title'],
                'description' => $data['description'] ?? null,
                'classification_code' => $data['classification_code'] ?? $category->code,
                'visibility' => $visibility,
                'allowed_roles' => $data['allowed_roles'] ?? null,
                'owner_user_id' => $data['owner_user_id'] ?? $actor->id,
                'owner_staff_id' => $data['owner_staff_id'] ?? $actor->staff?->id,
                'current_version' => 1,
                'document_date' => $documentDate,
                'retention_until' => $retentionUntil,
                'status' => Archive::STATUS_ACTIVE,
                'tags' => $data['tags'] ?? [],
                'created_by' => $actor->id,
            ]);

            $version = $this->storeVersionFile(
                archive: $archive,
                versionNumber: 1,
                file: $file,
                actor: $actor,
                changeNotes: 'Unggahan dokumen awal (Versi 1)',
            );

            $this->logAccess(
                archive: $archive,
                action: 'created',
                actor: $actor,
                version: $version,
                request: $request,
                metadata: ['version' => 1, 'file_name' => $version->original_name],
            );

            return $archive->load(['category', 'latestVersion', 'creator']);
        });
    }

    /**
     * Unggah revisi/versi baru ke arsip yang sudah ada tanpa menimpa file versi sebelumnya.
     */
    public function uploadNewVersion(
        Archive $archive,
        UploadedFile $file,
        User $actor,
        ?string $changeNotes = null,
        ?Request $request = null,
    ): ArchiveVersion {
        return DB::transaction(function () use ($archive, $file, $actor, $changeNotes, $request): ArchiveVersion {
            $locked = Archive::whereKey($archive->id)->lockForUpdate()->firstOrFail();
            $nextVersion = ((int) $locked->current_version) + 1;

            $version = $this->storeVersionFile(
                archive: $locked,
                versionNumber: $nextVersion,
                file: $file,
                actor: $actor,
                changeNotes: $changeNotes ?: "Pembaruan dokumen (Versi {$nextVersion})",
            );

            $locked->update([
                'current_version' => $nextVersion,
            ]);

            $this->logAccess(
                archive: $locked,
                action: 'version_uploaded',
                actor: $actor,
                version: $version,
                request: $request,
                metadata: [
                    'version' => $nextVersion,
                    'change_notes' => $version->change_notes,
                ],
            );

            return $version;
        });
    }

    /**
     * Buat URL unduhan bertanda tangan sementara (Temporary Signed URL) untuk akses privat.
     */
    public function temporaryDownloadUrl(Archive $archive, ?ArchiveVersion $version = null, int $ttlMinutes = 15): string
    {
        $targetVersion = $version ?? $archive->latestVersion;

        return URL::temporarySignedRoute(
            'digital-archives.download-signed',
            now()->addMinutes($ttlMinutes),
            [
                'archive' => $archive->id,
                'version' => $targetVersion?->version_number ?? $archive->current_version,
            ]
        );
    }

    /**
     * Catat jejak audit akses/unduhan arsip ke `archive_access_logs`.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logAccess(
        Archive $archive,
        string $action,
        ?User $actor = null,
        ?ArchiveVersion $version = null,
        ?Request $request = null,
        array $metadata = [],
    ): ArchiveAccessLog {
        return ArchiveAccessLog::create([
            'archive_id' => $archive->id,
            'archive_version_id' => $version?->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'request_id' => $request?->attributes->get('request_id'),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Evaluasi dan tandai arsip yang telah melewati tanggal `retention_until` menjadi `expired`.
     */
    public function markExpiredArchives(): int
    {
        return Archive::query()
            ->where('status', Archive::STATUS_ACTIVE)
            ->whereNotNull('retention_until')
            ->whereDate('retention_until', '<', now()->toDateString())
            ->update(['status' => Archive::STATUS_EXPIRED]);
    }

    private function storeVersionFile(
        Archive $archive,
        int $versionNumber,
        UploadedFile $file,
        User $actor,
        string $changeNotes,
    ): ArchiveVersion {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $safeFilename = "v{$versionNumber}_".Str::random(16).'.'.$ext;
        $directory = "digital-archives/{$archive->id}";
        $storedPath = $file->storeAs($directory, $safeFilename, self::DISK);

        $realPath = Storage::disk(self::DISK)->path($storedPath);
        $checksum = is_file($realPath) ? hash_file('sha256', $realPath) : null;

        return ArchiveVersion::create([
            'archive_id' => $archive->id,
            'version_number' => $versionNumber,
            'storage_disk' => self::DISK,
            'file_path' => $storedPath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => (int) $file->getSize(),
            'checksum_sha256' => $checksum,
            'change_notes' => $changeNotes,
            'uploaded_by' => $actor->id,
        ]);
    }

    private function generateArchiveNumber(ArchiveCategory $category): string
    {
        $year = now()->format('Y');
        $seq = Archive::withTrashed()
            ->where('archive_category_id', $category->id)
            ->count() + 1;

        return sprintf('ARS/%s/%s/%04d', strtoupper($category->code), $year, $seq);
    }
}
