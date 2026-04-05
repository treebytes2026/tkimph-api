<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantSettlement extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SETTLED = 'settled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SETTLED,
    ];

    protected $fillable = [
        'restaurant_id',
        'period_from',
        'period_to',
        'due_date',
        'last_overdue_notified_at',
        'order_count',
        'gross_sales',
        'service_fees',
        'delivery_fees',
        'restaurant_net',
        'platform_revenue',
        'status',
        'settled_at',
        'created_by_admin_id',
        'settled_by_admin_id',
        'reference_number',
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
            'last_overdue_notified_at' => 'datetime',
            'order_count' => 'integer',
            'gross_sales' => 'decimal:2',
            'service_fees' => 'decimal:2',
            'delivery_fees' => 'decimal:2',
            'restaurant_net' => 'decimal:2',
            'platform_revenue' => 'decimal:2',
            'settled_at' => 'datetime',
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

    public function settledByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_admin_id');
    }

    public function paymentSubmittedByPartner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_submitted_by_partner_id');
    }
}
