<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class IntegrationLog extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'channel',
        'event',
        'status',
        'user_id',
        'integration_token_id',
        'request_id',
        'ip_address',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'integration_token_id' => 'string',
            'context' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(
        string $channel,
        string $event,
        string $status = 'success',
        array $context = [],
        ?string $userId = null,
        ?string $integrationTokenId = null,
    ): self {
        return self::create([
            'channel' => $channel,
            'event' => $event,
            'status' => $status,
            'user_id' => $userId ?? auth()->id(),
            'integration_token_id' => $integrationTokenId ?? request()?->attributes->get('integration_token')?->id,
            'request_id' => request()?->attributes->get('request_id') ?? request()?->header('X-Request-Id'),
            'ip_address' => request()?->ip(),
            'context' => $context,
        ]);
    }
}
