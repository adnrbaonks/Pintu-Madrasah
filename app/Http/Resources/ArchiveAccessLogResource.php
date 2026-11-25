<?php

namespace App\Http\Resources;

use App\Models\ArchiveAccessLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArchiveAccessLogResource extends JsonResource
{
    /**
     * Transformasikan log audit arsip dengan daftar atribut eksplisit
     * untuk pengguna yang memegang permission `view_audit_logs`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ArchiveAccessLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'archive_id' => $log->archive_id,
            'archive_version_id' => $log->archive_version_id,
            'action' => $log->action,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'request_id' => $log->request_id,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at?->toIso8601String(),
            'user' => $log->relationLoaded('user') && $log->user !== null
                ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'role' => $log->user->role,
                ]
                : null,
        ];
    }
}
