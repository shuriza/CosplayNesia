<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LedgerTransaction extends Model
{
    protected $fillable = [
        'event_key', 'description', 'reference_type', 'reference_id',
        'reverses_transaction_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Ledger transactions are immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Ledger transactions are immutable.');
        });
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * The transaction this one corrects. Null for every ordinary post.
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /**
     * The correction that cancelled this transaction, if an operator has reversed it.
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }
}
