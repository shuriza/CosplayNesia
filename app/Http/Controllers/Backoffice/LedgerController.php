<?php

namespace App\Http\Controllers\Backoffice;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\LedgerReconciliation;
use App\Services\LedgerService;
use App\Services\SellerBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only finance console: transactions, account balances, seller balances, and the
 * reconciliation report. There is no mutation path here by design; money is only moved by
 * LedgerService inside domain flows (Batch 6+).
 */
class LedgerController extends BackofficeController
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SellerBalance $balances,
        private readonly LedgerReconciliation $reconciliation,
    ) {}

    private function authorizeFinance(Request $request): void
    {
        abort_unless($request->user()->canViewBackofficeFinance(), 403);
    }

    public function transactions(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $filters = $request->validate([
            'reference_type' => ['nullable', 'string', 'max:60'],
            'reference_id' => ['nullable', 'integer'],
            'event_key' => ['nullable', 'string', 'max:191'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $transactions = LedgerTransaction::query()
            ->with('entries.account:id,code,name,type')
            ->when($filters['reference_type'] ?? null, fn ($query, string $type) => $query->where('reference_type', $type))
            ->when($filters['reference_id'] ?? null, fn ($query, int $id) => $query->where('reference_id', $id))
            ->when($filters['event_key'] ?? null, fn ($query, string $key) => $query->where('event_key', $key))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 20));

        return response()->json($this->paged($transactions, fn (LedgerTransaction $transaction): array => [
            'id' => $transaction->id,
            'event_key' => $transaction->event_key,
            'description' => $transaction->description,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            // Without this the console would offer to "correct" a correction, which restores the
            // mistake it was meant to fix.
            'reverses_transaction_id' => $transaction->reverses_transaction_id,
            'reversed_by_id' => $transaction->reversedBy?->id,
            'occurred_at' => $transaction->occurred_at,
            'entries' => $transaction->entries->map(fn (LedgerEntry $entry): array => [
                'account_code' => $entry->account?->code,
                'account_name' => $entry->account?->name,
                'direction' => $entry->direction,
                'amount' => $entry->amount,
                'currency' => $entry->currency,
            ])->values(),
        ]));
    }

    public function accounts(): JsonResponse
    {
        $this->authorizeFinance(request());
        $accounts = LedgerAccount::query()->orderBy('code')->get();

        return response()->json([
            'data' => $accounts->map(fn (LedgerAccount $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'owner_type' => $account->owner_type,
                'owner_id' => $account->owner_id,
                'balance' => $this->ledger->balance($account->id),
            ])->values(),
        ]);
    }

    public function sellers(): JsonResponse
    {
        $this->authorizeFinance(request());

        return response()->json([
            'data' => collect($this->balances->knownSellerIds())
                ->map(fn (int $sellerId): array => $this->balances->for($sellerId))
                ->values(),
        ]);
    }

    public function seller(int $sellerId): JsonResponse
    {
        $this->authorizeFinance(request());

        return response()->json($this->balances->for($sellerId));
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json($this->reconciliation->report(
            isset($filters['from']) ? Carbon::parse($filters['from']) : null,
            isset($filters['to']) ? Carbon::parse($filters['to']) : null,
        ));
    }
}
