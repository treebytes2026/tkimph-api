<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const REFUND_STATUS_NOT_REQUIRED = 'not_required';
    public const REFUND_STATUS_PENDING = 'pending';
    public const REFUND_STATUS_PROCESSING = 'processing';
    public const REFUND_STATUS_REFUNDED = 'refunded';
    public const REFUND_STATUS_REJECTED = 'rejected';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNDELIVERABLE = 'undeliverable';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_PREPARING,
        self::STATUS_OUT_FOR_DELIVERY,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_FAILED,
        self::STATUS_UNDELIVERABLE,
    ];

    protected $fillable = [
        'order_number',
        'customer_id',
        'restaurant_id',
        'rider_id',
        'status',
        'payment_method',
        'payment_status',
        'refund_status',
        'refund_requested_at',
        'refunded_at',
        'refund_reference',
        'refund_reason',
        'delivery_mode',
        'delivery_address',
        'delivery_floor',
        'delivery_note',
        'location_label',
        'subtotal',
        'service_fee',
        'delivery_fee',
        'discounts_total',
        'gross_sales',
        'restaurant_net',
        'total',
        'placed_at',
        'assigned_at',
        'cancelled_by_role',
        'cancellation_reason',
        'cancelled_at',
        'customer_cancel_requested_at',
        'customer_cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discounts_total' => 'decimal:2',
            'gross_sales' => 'decimal:2',
            'restaurant_net' => 'decimal:2',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
            'assigned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refund_requested_at' => 'datetime',
            'refunded_at' => 'datetime',
            'customer_cancel_requested_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function adminNotes(): HasMany
    {
        return $this->hasMany(OrderAdminNote::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('created_at');
    }

    public function supportNotes(): HasMany
    {
        return $this->hasMany(SupportNote::class)->orderByDesc('created_at');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(OrderIssue::class)->orderByDesc('created_at');
    }

    public function review(): HasOne
    {
        return $this->hasOne(OrderReview::class);
    }
}
