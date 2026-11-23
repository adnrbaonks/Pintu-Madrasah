<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Archive extends Model
{
    use HasUuids, SoftDeletes;

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_INTERNAL = 'internal';

    public const VISIBILITY_ROLE_RESTRICTED = 'role_restricted';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_DISPOSED = 'disposed';

    protected $fillable = [
        'archive_category_id',
        'organization_unit_id',
        'academic_year_id',
        'archive_number',
        'title',
        'description',
        'classification_code',
        'visibility',
        'allowed_roles',
        'owner_user_id',
        'owner_staff_id',
        'current_version',
        'document_date',
        'retention_until',
        'status',
        'tags',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allowed_roles' => 'array',
            'tags' => 'array',
            'current_version' => 'integer',
            'document_date' => 'date:Y-m-d',
            'retention_until' => 'date:Y-m-d',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ArchiveCategory::class, 'archive_category_id');
    }

    public function organizationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationUnit::class, 'organization_unit_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function ownerStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'owner_staff_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArchiveVersion::class)->orderByDesc('version_number');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(ArchiveVersion::class)->latestOfMany('version_number');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(ArchiveAccessLog::class)->orderByDesc('created_at');
    }
}
