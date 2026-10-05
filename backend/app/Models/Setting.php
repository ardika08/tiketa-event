<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Ambil nilai setting (dengan cache singkat per request).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        if (! $row || $row->value === null) {
            return $default;
        }

        $decoded = json_decode($row->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $row->value;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_string($value) ? $value : json_encode($value)],
        );
    }

    /**
     * Daftar gateway pembayaran aktif yang dipilih admin (urutan = fallback).
     *
     * @return array<int, string>|null  null = belum diatur (pakai default config)
     */
    public static function paymentGateways(): ?array
    {
        $value = static::get('payment_gateways');

        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter(array_map('strval', $value)));
    }
}
