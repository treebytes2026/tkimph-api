<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportNote extends Model
{
    public const TYPE_INTERNAL_NOTE = 'internal_note';
    public const TYPE_CONTACT_LOG = 'contact_log';
    public const TYPE_ISSUE_TAG = 'issue_tag';

    public const TYPES = [
        self::TYPE_INTERNAL_NOTE,
        self::TYPE_CONTACT_LOG,
        self::TYPE_ISSUE_TAG,
    ];

    protected $fillable = [
        'restaurant_id',
        'order_id',
        'admin_id',
        'note_type',
        'body',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
