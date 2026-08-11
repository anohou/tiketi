<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Outbox de publication vers le portefeuille Okohi (§7.2).
 *
 * Une panne Okohi ne doit jamais annuler une vente Tiketi : la vente est
 * confirmée, puis la publication est mise en file et reprise par le job
 * jusqu'à livraison. La clé d'idempotence (billet + version) garantit
 * qu'une reprise ne crée jamais de doublon côté Okohi.
 */
class OkohiTicketOutbox extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const OPERATION_CREATE = 'create';

    public const OPERATION_UPDATE = 'update';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DELIVERED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'okohi_ticket_outbox';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ticket_id',
        'external_ticket_id',
        'status',
        'operation',
        'version',
        'idempotency_key',
        'payload',
        'attempt_count',
        'next_attempt_at',
        'last_attempt_at',
        'delivered_at',
        'last_error',
        'last_error_code',
        'last_response',
    ];

    protected $casts = [
        'version' => 'integer',
        'payload' => 'array',
        'attempt_count' => 'integer',
        'next_attempt_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
        'last_response' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }
}
