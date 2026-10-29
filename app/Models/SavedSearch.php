<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedSearch extends Model
{
    public const MAX_PER_USER = 20;

    protected $fillable = [
        'user_id', 'name', 'filters', 'alert_enabled', 'last_scanned_at', 'last_matched_product_id',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'alert_enabled' => 'boolean',
            'last_scanned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
