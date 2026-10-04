<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    public const CASH = '1000';

    public const SELLER_PAYABLE = '2000';

    public const RENTAL_DEPOSIT_HELD = '2100';

    public const PLATFORM_FEE_REVENUE = '4000';

    public const SHIPPING_REVENUE = '4100';

    public const DISCOUNT_SUBSIDY = '5000';

    public const PAYMENT_GATEWAY_FEE = '5100';

    protected $fillable = ['code', 'name', 'type', 'owner_type', 'owner_id'];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public static function sellerPayableCode(int $sellerId): string
    {
        return self::SELLER_PAYABLE.':'.$sellerId;
    }
}
