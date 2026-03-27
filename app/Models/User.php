<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    const ROLE_ADMIN = 'admin';
    const ROLE_RESTAURANT_OWNER = 'restaurant_owner';
    const ROLE_RIDER = 'rider';
    const ROLE_CUSTOMER = 'customer';

    const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_RESTAURANT_OWNER,
        self::ROLE_RIDER,
        self::ROLE_CUSTOMER,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'address',
        'is_active',
        'phone_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
        'phone_verification_code',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'email_verification_code_expires_at' => 'datetime',
            'phone_verification_code_expires_at' => 'datetime',
        ];
    }

    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class, 'user_id');
    }

    public function assignedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'rider_id');
    }

    public function orderAdminNotes(): HasMany
    {
        return $this->hasMany(OrderAdminNote::class, 'admin_id');
    }

    public function orderEvents(): HasMany
    {
        return $this->hasMany(OrderEvent::class, 'actor_user_id');
    }

    public function supportNotes(): HasMany
    {
        return $this->hasMany(SupportNote::class, 'admin_id');
    }

    public function promotionRedemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function orderIssues(): HasMany
    {
        return $this->hasMany(OrderIssue::class, 'customer_id');
    }

    public function orderReviews(): HasMany
    {
        return $this->hasMany(OrderReview::class, 'customer_id');
    }

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_ADMIN)->where('is_active', true);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isRestaurantOwner(): bool
    {
        return $this->role === self::ROLE_RESTAURANT_OWNER;
    }

    public function isRider(): bool
    {
        return $this->role === self::ROLE_RIDER;
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }
}
