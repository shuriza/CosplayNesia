<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's opt-out for one channel/category pair.
 *
 * Absence of a row means "default enabled", so a category added later is on for everyone until
 * someone turns it off. Mandatory categories never consult this table.
 */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'channel', 'category', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
