<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\Staff;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    /**
     * Ambil daftar unit kerja terurut beserta relasi induk, sub-unit, dan jumlah jabatan.
     */
    public function getUnitTree(): Collection
    {
        return OrganizationUnit::query()
            ->with(['parent:id,code,name', 'children:id,parent_id,code,name,type,is_active'])
            ->withCount(['positions', 'assignments'])
            ->orderBy('code')
            ->get();
    }

    /**
     * Ambil seluruh penugasan yang aktif pada tanggal tertentu.
     */
    public function getEffectiveAssignments(CarbonInterface|string|null $date = null): Collection
    {
        return PositionAssignment::query()
            ->with([
                'staff:id,full_name_with_titles,nip,nuptk,employment_status',
                'position:id,org_unit_id,code,name,level,is_structural',
                'unit:id,code,name,type',
            ])
            ->effectiveOn($date)
            ->orderByDesc('is_primary')
            ->orderByDesc('valid_from')
            ->get();
    }

    /**
     * Resolusi pejabat penyetuju (approver) berdasarkan level jabatan struktural pada tanggal tertentu.
     * Level 1 = Kepala Madrasah (Head Approval)
     * Level 2 = Kepala Tata Usaha / Wakil Kepala (TU Review / Verifikator)
     */
    public function resolveApproverByLevel(int $level, ?string $orgUnitId = null, CarbonInterface|string|null $date = null): ?Staff
    {
        $assignment = PositionAssignment::query()
            ->with('staff')
            ->effectiveOn($date)
            ->whereHas('position', function (Builder $query) use ($level) {
                $query->where('level', $level)->where('is_structural', true);
            })
            ->when($orgUnitId, fn (Builder $query) => $query->where('org_unit_id', $orgUnitId))
            ->orderByDesc('is_primary')
            ->orderBy('valid_from')
            ->first();

        return $assignment?->staff;
    }

    /**
     * Buat atau perbarui penugasan jabatan dengan validasi rentang tanggal dan overlap penugasan utama.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function assignPosition(array $attributes, ?PositionAssignment $existing = null, ?string $actorUserId = null): PositionAssignment
    {
        $validFrom = Carbon::parse($attributes['valid_from'])->toDateString();
        $validUntil = ! empty($attributes['valid_until'])
            ? Carbon::parse($attributes['valid_until'])->toDateString()
            : null;

        if ($validUntil !== null && $validFrom > $validUntil) {
            throw ValidationException::withMessages([
                'valid_until' => 'Tanggal berakhir penugasan tidak boleh mendahului tanggal mulai berlaku.',
            ]);
        }

        $position = Position::findOrFail($attributes['position_id']);
        $orgUnitId = $attributes['org_unit_id'] ?? $position->org_unit_id;

        if ($position->org_unit_id !== $orgUnitId) {
            throw ValidationException::withMessages([
                'org_unit_id' => 'Jabatan yang dipilih tidak berada di bawah unit kerja tersebut.',
            ]);
        }

        $isPrimary = (bool) ($attributes['is_primary'] ?? false);
        $status = $attributes['status'] ?? 'active';
        $staffId = $attributes['staff_id'];

        if ($isPrimary && $status === 'active') {
            $this->ensureNoOverlappingPrimaryAssignment(
                staffId: $staffId,
                validFrom: $validFrom,
                validUntil: $validUntil,
                ignoreId: $existing?->id,
            );
        }

        return DB::transaction(function () use ($attributes, $existing, $orgUnitId, $validFrom, $validUntil, $isPrimary, $status, $actorUserId): PositionAssignment {
            $payload = [
                'staff_id' => $attributes['staff_id'],
                'position_id' => $attributes['position_id'],
                'org_unit_id' => $orgUnitId,
                'sk_number' => $attributes['sk_number'] ?? null,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'is_primary' => $isPrimary,
                'status' => $status,
            ];

            if ($existing) {
                $before = $existing->only(array_keys($payload));
                $existing->update($payload);
                $assignment = $existing->fresh(['staff', 'position', 'unit']);

                ActivityLog::create([
                    'user_id' => $actorUserId ?? auth()->id(),
                    'action' => 'updated_position_assignment',
                    'model_type' => PositionAssignment::class,
                    'model_id' => $assignment->id,
                    'before_payload' => $before,
                    'after_payload' => $payload,
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);
            } else {
                $assignment = PositionAssignment::create($payload)->load(['staff', 'position', 'unit']);

                ActivityLog::create([
                    'user_id' => $actorUserId ?? auth()->id(),
                    'action' => 'created_position_assignment',
                    'model_type' => PositionAssignment::class,
                    'model_id' => $assignment->id,
                    'before_payload' => null,
                    'after_payload' => $payload,
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);
            }

            $eventPayload = [
                'assignment_id' => $assignment->id,
                'staff_id' => $assignment->staff_id,
                'staff_name' => $assignment->staff?->full_name_with_titles,
                'position_code' => $assignment->position?->code,
                'position_name' => $assignment->position?->name,
                'org_unit_code' => $assignment->unit?->code,
                'org_unit_name' => $assignment->unit?->name,
                'sk_number' => $assignment->sk_number,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'is_primary' => $assignment->is_primary,
                'status' => $assignment->status,
            ];

            app(OutboxService::class)->record(
                aggregateType: Staff::class,
                aggregateId: $assignment->staff_id,
                eventType: 'staff.position.assigned',
                payload: $eventPayload,
            );

            return $assignment;
        });
    }

    /**
     * Pastikan satu pegawai tidak memiliki dua penugasan utama (is_primary = true) aktif yang tumpang tindih periode.
     */
    private function ensureNoOverlappingPrimaryAssignment(
        string $staffId,
        string $validFrom,
        ?string $validUntil,
        ?string $ignoreId = null
    ): void {
        $overlapExists = PositionAssignment::query()
            ->where('staff_id', $staffId)
            ->where('is_primary', true)
            ->where('status', 'active')
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->where(function (Builder $query) use ($validFrom, $validUntil) {
                if ($validUntil !== null) {
                    $query->whereDate('valid_from', '<=', $validUntil);
                }

                $query->where(function (Builder $sub) use ($validFrom) {
                    $sub->whereNull('valid_until')
                        ->orWhereDate('valid_until', '>=', $validFrom);
                });
            })
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'is_primary' => 'Pegawai ini sudah memiliki penugasan jabatan utama (primary) yang aktif pada rentang tanggal tersebut.',
            ]);
        }
    }
}
