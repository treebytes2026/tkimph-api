<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Promotion extends Model
{
    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED = 'fixed';

    public const TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FIXED,
    ];

    protected $fillable = [
        'restaurant_id',
        'code',
        'name',
        'description',
        'is_active',
        'starts_at',
        'ends_at',
        'min_spend',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'global_usage_limit',
        'per_user_usage_limit',
        'stackable',
        'auto_apply',
        'first_order_only',
        'priority',
        'eligible_user_ids',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'min_spend' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'stackable' => 'boolean',
            'auto_apply' => 'boolean',
            'first_order_only' => 'boolean',
            'priority' => 'integer',
            'eligible_user_ids' => 'array',
        ];
    }

    public function scopeActiveAt(Builder $query, ?\Illuminate\Support\Carbon $at = null): Builder
    {
        $when = $at ?? now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($when) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $when);
            })
            ->where(function (Builder $q) use ($when) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $when);
            });
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function orderDiscounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }
}
