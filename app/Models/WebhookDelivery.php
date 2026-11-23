<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WebhookDelivery extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'webhook_subscription_id',
        'event_id',
        'outbox_event_id',
        'event',
        'payload',
        'status_code',
        'response_body',
        'response_headers',
        'delivered',
        'attempt',
        'next_retry_at',
        'delivered_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'event_id' => 'string',
            'outbox_event_id' => 'string',
            'payload' => 'array',
            'response_headers' => 'array',
            'delivered' => 'boolean',
            'attempt' => 'integer',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }
}
