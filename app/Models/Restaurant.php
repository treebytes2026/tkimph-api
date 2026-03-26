<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
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
        'business_type_id',
        'business_category_id',
        'cuisine_id',
        'is_active',
        'opening_hours',
        'profile_image_path',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opening_hours' => 'array',
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

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function businessCategory(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class);
    }

    public function cuisine(): BelongsTo
    {
        return $this->belongsTo(Cuisine::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function locationImages(): HasMany
    {
        return $this->hasMany(RestaurantImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Shape returned by partner overview and PATCH /partner/restaurants/{id}. */
    public function toPartnerApiArray(): array
    {
        $this->loadMissing([
            'businessType:id,name,slug',
            'businessCategory:id,name',
            'cuisine:id,name',
            'locationImages',
        ]);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'phone' => $this->phone,
            'address' => $this->address,
            'is_active' => (bool) $this->is_active,
            'opening_hours' => $this->opening_hours,
            'profile_image_path' => $this->profile_image_path,
            'profile_image_url' => $this->profile_image_path
                ? Storage::disk('public')->url($this->profile_image_path)
                : null,
            'location_images' => $this->locationImages->map(static fn (RestaurantImage $img) => [
                'id' => $img->id,
                'path' => $img->path,
                'url' => $img->url,
                'sort_order' => $img->sort_order,
            ])->values()->all(),
            'business_type' => $this->businessType ? [
                'id' => $this->businessType->id,
                'name' => $this->businessType->name,
            ] : null,
            'business_category' => $this->businessCategory ? [
                'id' => $this->businessCategory->id,
                'name' => $this->businessCategory->name,
            ] : null,
            'cuisine' => $this->cuisine ? [
                'id' => $this->cuisine->id,
                'name' => $this->cuisine->name,
            ] : null,
        ];
    }
}
