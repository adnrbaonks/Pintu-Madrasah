<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmisImportSessionPage extends Model
{
    protected $fillable = ['session_id', 'page', 'items_count', 'payload_hash', 'payload'];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'items_count' => 'integer',
            'payload' => 'array',
        ];
    }
}
