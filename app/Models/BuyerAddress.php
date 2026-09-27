<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerAddress extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'recipient_phone',
        'address_line1',
        'address_line2',
        'city',
        'province',
        'postal_code',
        'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
