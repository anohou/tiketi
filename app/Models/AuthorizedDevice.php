<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuthorizedDevice extends Model
{
    use HasUuids;

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_CONTROL = 'control';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVOKED = 'revoked';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'secret_hash',
        'channel',
        'status',
        'name',
        'platform',
        'app_version',
        'requested_by_type',
        'requested_by_id',
        'approved_by_user_id',
        'last_ip',
        'last_user_agent',
        'requested_at',
        'approved_at',
        'revoked_at',
        'last_seen_at',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function requester(): MorphTo
    {
        return $this->morphTo('requester', 'requested_by_type', 'requested_by_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
