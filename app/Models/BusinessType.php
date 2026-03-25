<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'sort_order',
        'is_active',
        'requires_category',
        'requires_cuisine',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_category' => 'boolean',
            'requires_cuisine' => 'boolean',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(BusinessCategory::class)->orderBy('sort_order');
    }

    public function partnerApplications(): HasMany
    {
        return $this->hasMany(PartnerApplication::class);
    }
}
