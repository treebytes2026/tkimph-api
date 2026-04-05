<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionCollection extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RECEIVED = 'received';
    public const PAYMENT_METHOD_GCASH = 'gcash';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RECEIVED,
    ];

    public const PAYMENT_METHODS = [
        self::PAYMENT_METHOD_GCASH,
    ];

    protected $fillable = [
        'restaurant_id',
        'period_from',
        'period_to',
        'due_date',
        'order_count',
        'gross_sales',
        'commission_amount',
        'restaurant_net',
        'status',
        'received_at',
        'last_overdue_notified_at',
        'created_by_admin_id',
        'received_by_admin_id',
        'collection_reference',
        'partner_payment_method',
        'partner_reference_number',
        'payment_proof_path',
        'partner_payment_note',
        'payment_submitted_at',
        'payment_submitted_by_partner_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'due_date' => 'date',
            'order_count' => 'integer',
            'gross_sales' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'restaurant_net' => 'decimal:2',
            'received_at' => 'datetime',
            'last_overdue_notified_at' => 'datetime',
            'payment_submitted_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function receivedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_admin_id');
    }

    public function paymentSubmittedByPartner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_submitted_by_partner_id');
    }
}
