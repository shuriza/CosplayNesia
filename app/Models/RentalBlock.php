<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalBlock extends Model
{
    protected $fillable = [
        'product_id', 'start_date', 'end_date', 'quantity', 'reason', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
        ];
    }
}
