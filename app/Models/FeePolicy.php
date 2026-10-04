<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeePolicy extends Model
{
    protected $fillable = [
        'version', 'platform_fee_bps', 'platform_fee_flat', 'effective_from', 'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'platform_fee_bps' => 'integer',
            'platform_fee_flat' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public static function activeFor(?\DateTimeInterface $at = null): ?self
    {
        $at ??= now();

        return self::query()
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', $at))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
