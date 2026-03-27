<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AdminSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function read(string $key, string $default = ''): string
    {
        if (! Schema::hasTable('admin_settings')) {
            return $default;
        }
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function write(string $key, string $value): void
    {
        if (! Schema::hasTable('admin_settings')) {
            return;
        }
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    public static function readBool(string $key, bool $default): bool
    {
        return static::read($key, $default ? '1' : '0') === '1';
    }

    public static function readInt(string $key, int $default): int
    {
        return (int) static::read($key, (string) $default);
    }
}
