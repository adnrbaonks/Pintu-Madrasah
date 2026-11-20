<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WebhookDelivery extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'webhook_subscription_id', 'event', 'payload',
        'status_code', 'response_body', 'delivered', 'error',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'payload' => 'array',
            'delivered' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function subscription()
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }
}
