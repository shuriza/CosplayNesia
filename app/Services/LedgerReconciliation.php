<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\OrderFulfillment;
use Illuminate\Support\Carbon;

/**
 * Read-only integrity report for the ledger.
 *
 * This does not "fix" anything: it surfaces exceptions explicitly so finance can act. It proves the
 * invariants the ledger is supposed to hold (every transaction balances; every posted seller
 * liability is classified into a settlement bucket) and reports the settlement backlog. Provider /
 * bank reconciliation is intentionally out of scope until a payment provider exists (Batch 6);
 * the report says so instead of pretending the numbers are externally verified.
 */
class LedgerReconciliation
{
    public function __construct(private readonly SellerBalance $balances) {}

    /**
     * @return array{
     *     generated_at:string,
     *     scope:array{from:?string,to:?string},
     *     transactions:int,
     *     total_debit:int,
     *     total_credit:int,
     *     balanced:bool,
     *     pending_settlement_count:int,
     *     pending_settlement_amount:int,
     *     exceptions:array<int, array{type:string, detail:array<string, mixed>}>,
     *     clean:bool,
     *     provider_reconciliation:string
     * }
     */
    public function report(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $from = $from !== null ? Carbon::instance($from) : null;
        $to = $to !== null ? Carbon::instance($to) : null;

        $transactions = LedgerTransaction::query()
            ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('occurred_at', '<=', $to))
            ->count();

        $totals = LedgerEntry::query()
            ->when($from !== null || $to !== null, fn ($query) => $query->whereIn(
                'ledger_transaction_id',
                LedgerTransaction::query()
                    ->when($from !== null, fn ($q) => $q->where('occurred_at', '>=', $from))
                    ->when($to !== null, fn ($q) => $q->where('occurred_at', '<=', $to))
                    ->select('id'),
            ))
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END), 0) AS debits', [LedgerEntry::DIRECTION_DEBIT])
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END), 0) AS credits', [LedgerEntry::DIRECTION_CREDIT])
            ->first();

        $totalDebit = (int) ($totals->debits ?? 0);
        $totalCredit = (int) ($totals->credits ?? 0);

        $exceptions = [];

        if ($totalDebit !== $totalCredit) {
            $exceptions[] = [
                'type' => 'unbalanced_ledger',
                'detail' => ['total_debit' => $totalDebit, 'total_credit' => $totalCredit],
            ];
        }

        foreach ($this->unbalancedTransactionIds() as $id) {
            $exceptions[] = ['type' => 'unbalanced_transaction', 'detail' => ['ledger_transaction_id' => $id]];
        }

        foreach ($this->balances->knownSellerIds() as $sellerId) {
            $balance = $this->balances->for($sellerId);
            if (! $balance['balanced']) {
                $exceptions[] = [
                    'type' => 'unclassified_seller_balance',
                    'detail' => [
                        'seller_id' => $sellerId,
                        'liability' => $balance['liability'],
                        'classified' => $balance['classified'],
                        'unclassified' => $balance['unclassified'],
                    ],
                ];
            }
        }

        $pending = OrderFulfillment::query()
            ->where('settlement_status', OrderFulfillment::SETTLEMENT_PENDING)
            ->whereIn('status', [
                OrderFulfillment::STATUS_COMPLETED,
                OrderFulfillment::STATUS_CANCELLED,
            ])
            ->join('order_items', 'order_items.fulfillment_id', '=', 'order_fulfillments.id')
            ->selectRaw('COUNT(DISTINCT order_fulfillments.id) AS fulfillments, COALESCE(SUM(order_items.unit_price * order_items.quantity), 0) AS amount')
            ->first();

        return [
            'generated_at' => now()->toIso8601String(),
            'scope' => [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ],
            'transactions' => $transactions,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced' => $totalDebit === $totalCredit,
            'pending_settlement_count' => (int) ($pending->fulfillments ?? 0),
            'pending_settlement_amount' => (int) ($pending->amount ?? 0),
            'exceptions' => $exceptions,
            'clean' => $exceptions === [],
            'provider_reconciliation' => 'unavailable_until_payment_provider',
        ];
    }

    /**
     * Transactions whose entries do not balance. Should always be empty; kept as an explicit,
     * queryable exception rather than an assumption.
     *
     * @return array<int, int>
     */
    private function unbalancedTransactionIds(): array
    {
        return LedgerEntry::query()
            ->groupBy('ledger_transaction_id')
            ->havingRaw(
                'SUM(CASE WHEN direction = ? THEN amount ELSE 0 END) <> SUM(CASE WHEN direction = ? THEN amount ELSE 0 END)',
                [LedgerEntry::DIRECTION_DEBIT, LedgerEntry::DIRECTION_CREDIT],
            )
            ->pluck('ledger_transaction_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
