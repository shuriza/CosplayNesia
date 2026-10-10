<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\OrderFulfillment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only projection of a seller's money.
 *
 * The authoritative total is always the `seller_payable:{id}` liability account in the ledger
 * (credit = what the platform owes the seller). Settlement status on each fulfillment is only a
 * classification of that total into pending / available / reserved / paid buckets; it is never a
 * stored balance that gets UPDATEd. `unclassified` is the difference between the ledger liability
 * and the sum of the buckets: it is zero when every posted rupiah is backed by a fulfillment, and
 * it exposes money that reached the ledger without a matching settlement record (or the reverse).
 *
 * See docs/adr/0002-seller-balance-projection.md.
 */
class SellerBalance
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @return array{seller_id:int, pending:int, available:int, reserved:int, paid:int, classified:int, unclassified:int, liability:int, balanced:bool, currency:string}
     */
    public function for(int $sellerId): array
    {
        $buckets = [
            OrderFulfillment::SETTLEMENT_PENDING => 0,
            OrderFulfillment::SETTLEMENT_AVAILABLE => 0,
            OrderFulfillment::SETTLEMENT_RESERVED => 0,
            OrderFulfillment::SETTLEMENT_PAID => 0,
        ];

        $totals = $this->settlementTotals($sellerId);
        foreach ($buckets as $status => $_) {
            $buckets[$status] = (int) ($totals[$status] ?? 0);
        }

        $liability = max(0, $this->ledger->balance(LedgerAccount::sellerPayableCode($sellerId)));
        $classified = array_sum($buckets);

        return [
            'seller_id' => $sellerId,
            'pending' => $buckets[OrderFulfillment::SETTLEMENT_PENDING],
            'available' => $buckets[OrderFulfillment::SETTLEMENT_AVAILABLE],
            'reserved' => $buckets[OrderFulfillment::SETTLEMENT_RESERVED],
            'paid' => $buckets[OrderFulfillment::SETTLEMENT_PAID],
            'classified' => $classified,
            'unclassified' => $liability - $classified,
            'liability' => $liability,
            'balanced' => $liability === $classified,
            'currency' => 'IDR',
        ];
    }

    /**
     * Sum of seller net owed per settlement bucket for terminal fulfillments.
     *
     * Net is the sum of the seller's line totals (unit_price * quantity). Shipping, deposit, and
     * platform fee are excluded here; they are separate ledger accounts and are applied when the
     * seller payable credit is posted in Batch 6. This method classifies what is already posted.
     *
     * @return array<string, int>
     */
    private function settlementTotals(int $sellerId): array
    {
        return OrderFulfillment::query()
            ->where('order_fulfillments.seller_id', $sellerId)
            ->whereIn('order_fulfillments.status', [
                OrderFulfillment::STATUS_COMPLETED,
                OrderFulfillment::STATUS_CANCELLED,
            ])
            ->join('order_items', 'order_items.fulfillment_id', '=', 'order_fulfillments.id')
            ->groupBy('order_fulfillments.settlement_status')
            ->selectRaw('order_fulfillments.settlement_status AS settlement_status, SUM(order_items.unit_price * order_items.quantity) AS net')
            ->pluck('net', 'settlement_status')
            ->map(static fn ($net): int => (int) $net)
            ->all();
    }

    /**
     * Every seller that has either a ledger account or a fulfillment, so reconciliation can spot
     * money that exists in only one of the two sources.
     *
     * @return array<int, int>
     */
    public function knownSellerIds(): array
    {
        $fromLedger = LedgerAccount::query()
            ->where('owner_type', 'seller')
            ->whereNotNull('owner_id')
            ->pluck('owner_id');

        $fromFulfillments = OrderFulfillment::query()
            ->whereNotNull('seller_id')
            ->distinct()
            ->pluck('seller_id');

        return $fromLedger->merge($fromFulfillments)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return Builder<OrderFulfillment>
     */
    public function pendingSettlementQuery(?int $sellerId = null): Builder
    {
        return OrderFulfillment::query()
            ->where('settlement_status', OrderFulfillment::SETTLEMENT_PENDING)
            ->whereIn('status', [
                OrderFulfillment::STATUS_COMPLETED,
                OrderFulfillment::STATUS_CANCELLED,
            ])
            ->when($sellerId !== null, fn (Builder $query): Builder => $query->where('seller_id', $sellerId));
    }
}
