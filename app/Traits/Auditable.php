<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable()
    {
        static::created(function (Model $model) {
            ActivityLog::record('created', $model, null, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            ActivityLog::record('updated', $model, $model->getOriginal(), $model->getChanges());
        });

        static::deleted(function (Model $model) {
            ActivityLog::record('deleted', $model, $model->getAttributes(), null);
        });
    }
}
