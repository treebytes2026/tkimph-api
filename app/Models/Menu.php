<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    protected $fillable = [
        'restaurant_id',
        'name',
        'sort_order',
        'is_active',
        'discount_enabled',
        'discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'discount_enabled' => 'boolean',
            'discount_percent' => 'decimal:2',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

}
