<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use App\Support\MenuPricing;

class MenuItem extends Model
{
    protected $fillable = [
        'menu_id',
        'menu_category_id',
        'name',
        'description',
        'image_path',
        'price',
        'commission_rate',
        'platform_commission',
        'restaurant_net',
        'discount_enabled',
        'discount_percent',
        'sort_order',
        'is_available',
    ];

    protected $appends = [
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'commission_rate' => 'decimal:4',
            'platform_commission' => 'decimal:2',
            'restaurant_net' => 'decimal:2',
            'discount_enabled' => 'boolean',
            'discount_percent' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (MenuItem $item): void {
            if ($item->image_path) {
                Storage::disk('public')->delete($item->image_path);
            }
        });
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : null;
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function menuCategory(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MenuItemReview::class);
    }

    public function discountedPrice(): float
    {
        return MenuPricing::discountedPriceForItem($this);
    }

    public function effectiveDiscountPercent(): float
    {
        $menu = $this->relationLoaded('menu') ? $this->menu : $this->menu()->first();

        return MenuPricing::effectiveDiscountPercent($menu, $this);
    }
}
