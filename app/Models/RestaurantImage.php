<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class RestaurantImage extends Model
{
    protected $fillable = [
        'restaurant_id',
        'path',
        'sort_order',
    ];

    protected $appends = [
        'url',
    ];

    protected static function booted(): void
    {
        static::deleting(function (RestaurantImage $image): void {
            if ($image->path) {
                Storage::disk('public')->delete($image->path);
            }
        });
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path
            ? Storage::disk('public')->url($this->path)
            : null;
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
