<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Restaurant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'phone',
        'address',
        'user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Restaurant $restaurant): void {
            if (empty($restaurant->slug) && ! empty($restaurant->name)) {
                $restaurant->slug = Str::slug($restaurant->name).'-'.Str::random(4);
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
