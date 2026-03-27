<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Restaurant extends Model
{
    public const OPERATING_STATUS_OPEN = 'open';
    public const OPERATING_STATUS_PAUSED = 'paused';
    public const OPERATING_STATUS_TEMPORARILY_CLOSED = 'temporarily_closed';
    public const OPERATING_STATUS_SUSPENDED = 'suspended';

    public const OPERATING_STATUSES = [
        self::OPERATING_STATUS_OPEN,
        self::OPERATING_STATUS_PAUSED,
        self::OPERATING_STATUS_TEMPORARILY_CLOSED,
        self::OPERATING_STATUS_SUSPENDED,
    ];

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
        'operating_status',
        'operating_note',
        'paused_until',
        'force_publicly_orderable',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opening_hours' => 'array',
            'paused_until' => 'datetime',
            'force_publicly_orderable' => 'boolean',
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

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function supportNotes(): HasMany
    {
        return $this->hasMany(SupportNote::class)->orderByDesc('created_at');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(OrderReview::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    public function isPartnerSelfPauseEnabled(): bool
    {
        return AdminSetting::readBool('partner_self_pause_enabled', true);
    }

    public function isOperationallyAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (($this->operating_status ?? self::OPERATING_STATUS_OPEN) !== self::OPERATING_STATUS_OPEN) {
            return false;
        }

        if ($this->force_publicly_orderable) {
            return true;
        }

        $this->loadMissing(['menus.items']);
        $openingHours = collect($this->opening_hours ?? []);
        $hasHours = $openingHours->contains(fn ($row) => ($row['closed'] ?? true) === false);
        $hasMenu = $this->menus->isNotEmpty();
        $hasAvailableItem = $this->menus->contains(
            fn (Menu $menu) => $menu->items->contains(fn (MenuItem $item) => (bool) $item->is_available)
        );

        return $hasHours && $hasMenu && $hasAvailableItem;
    }

    public function readinessStatus(): array
    {
        $this->loadMissing(['menus.items']);

        $openingHours = collect($this->opening_hours ?? []);
        $hasHours = $openingHours->contains(fn ($row) => ($row['closed'] ?? true) === false);
        $hasMenu = $this->menus->isNotEmpty();
        $hasAvailableItem = $this->menus->contains(
            fn (Menu $menu) => $menu->items->contains(fn (MenuItem $item) => (bool) $item->is_available)
        );
        $status = $this->operating_status ?? self::OPERATING_STATUS_OPEN;

        $checks = [
            [
                'key' => 'profile_complete',
                'label' => 'Profile complete',
                'passed' => filled($this->name) && filled($this->phone) && filled($this->description),
            ],
            [
                'key' => 'address_set',
                'label' => 'Address set',
                'passed' => filled($this->address),
            ],
            [
                'key' => 'opening_hours_set',
                'label' => 'Opening hours set',
                'passed' => $hasHours,
            ],
            [
                'key' => 'has_menu',
                'label' => 'At least one menu',
                'passed' => $hasMenu,
            ],
            [
                'key' => 'has_available_item',
                'label' => 'At least one available item',
                'passed' => $hasAvailableItem,
            ],
            [
                'key' => 'store_active_and_open',
                'label' => 'Store active and open',
                'passed' => $this->is_active && $status === self::OPERATING_STATUS_OPEN,
            ],
        ];

        $isReady = collect($checks)->every(fn ($check) => $check['passed']);

        return [
            'is_ready' => $isReady,
            'status' => $isReady ? 'ready' : 'incomplete',
            'checks' => $checks,
        ];
    }

    /** Shape returned by partner overview and PATCH /partner/restaurants/{id}. */
    public function toPartnerApiArray(): array
    {
        $this->loadMissing([
            'businessType:id,name,slug',
            'businessCategory:id,name',
            'cuisine:id,name',
            'locationImages',
            'menus.items',
        ]);

        $readiness = $this->readinessStatus();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'phone' => $this->phone,
            'address' => $this->address,
            'is_active' => (bool) $this->is_active,
            'operating_status' => $this->operating_status ?? self::OPERATING_STATUS_OPEN,
            'operating_note' => $this->operating_note,
            'paused_until' => $this->paused_until?->toIso8601String(),
            'opening_hours' => $this->opening_hours,
            'profile_image_path' => $this->profile_image_path,
            'profile_image_url' => $this->profile_image_path
                ? Storage::disk('public')->url($this->profile_image_path)
                : null,
            'publicly_orderable' => $this->isOperationallyAvailable(),
            'force_publicly_orderable' => (bool) $this->force_publicly_orderable,
            'readiness_status' => $readiness['status'],
            'readiness_checks' => $readiness['checks'],
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
