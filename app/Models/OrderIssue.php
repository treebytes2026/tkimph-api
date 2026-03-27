<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderIssue extends Model
{
    public const TYPE_CANCEL_REQUEST = 'cancel_request';
    public const TYPE_REFUND_REQUEST = 'refund_request';
    public const TYPE_DISPUTE = 'dispute';
    public const TYPE_HELP = 'help';

    public const TYPES = [
        self::TYPE_CANCEL_REQUEST,
        self::TYPE_REFUND_REQUEST,
        self::TYPE_DISPUTE,
        self::TYPE_HELP,
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'order_id',
        'customer_id',
        'issue_type',
        'status',
        'subject',
        'description',
        'resolution',
        'resolved_by_admin_id',
        'resolved_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function resolvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_admin_id');
    }
}
