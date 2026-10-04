<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    public const DIRECTION_DEBIT = 'debit';

    public const DIRECTION_CREDIT = 'credit';

    protected $fillable = [
        'ledger_transaction_id', 'ledger_account_id', 'direction', 'amount', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Ledger entries are immutable.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Ledger entries are immutable.');
        });
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
