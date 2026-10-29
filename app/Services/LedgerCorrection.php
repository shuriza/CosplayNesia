<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operator corrections to money that has already been posted.
 *
 * A correction is never an edit. Entries in the ledger are append-only, so the only way to fix a
 * wrong post is to post a new transaction that reverses it. The original keeps its own event_key
 * and stays visible forever; the correction points back at it through `reverses_transaction_id`
 * and carries its own reason. An auditor reading six months later sees both the mistake and the
 * fix, in order, with the actor who made each.
 *
 * The correction mirrors the original with directions swapped, so the pair nets to zero. Idempotent
 * by the reversal link: a retried request returns the existing correction instead of reversing
 * twice — which would put the balance back where the mistake left it.
 */
class LedgerCorrection
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AdminAuditRecorder $audit,
    ) {}

    /**
     * @return array{transaction: LedgerTransaction, correction: LedgerTransaction}
     */
    public function reverse(
        User $operator,
        LedgerTransaction $transaction,
        string $reason,
        ?Request $request = null,
    ): array {
        abort_unless($operator->hasCapability(User::CAPABILITY_FINANCE), 403);

        $existing = LedgerTransaction::query()
            ->where('reverses_transaction_id', $transaction->id)
            ->first();

        if ($existing !== null) {
            return ['transaction' => $transaction->refresh(), 'correction' => $existing];
        }

        $entries = $transaction->entries()->orderBy('id')->get();
        if ($entries->isEmpty()) {
            throw ValidationException::withMessages([
                'transaction' => 'Transaksi ini tidak memiliki entri yang dapat dikoreksi.',
            ]);
        }

        $mirrored = $entries->map(static fn (LedgerEntry $entry): array => [
            'account' => $entry->ledger_account_id,
            'direction' => $entry->direction === LedgerEntry::DIRECTION_DEBIT
                ? LedgerEntry::DIRECTION_CREDIT
                : LedgerEntry::DIRECTION_DEBIT,
            'amount' => (int) $entry->amount,
            'currency' => $entry->currency,
        ])->all();

        return DB::transaction(function () use ($operator, $transaction, $mirrored, $reason, $request): array {
            $correction = $this->ledger->post(
                eventKey: 'correction:'.$transaction->id,
                entries: $mirrored,
                description: 'Koreksi transaksi #'.$transaction->id,
                referenceType: $transaction->reference_type,
                referenceId: $transaction->reference_id,
                reversesTransactionId: $transaction->id,
            );

            $this->audit->record(
                actor: $operator,
                action: 'ledger.corrected',
                targetType: 'ledger_transaction',
                targetId: $transaction->id,
                reason: $reason,
                context: [
                    'correction_transaction_id' => $correction->id,
                    'original_event_key' => $transaction->event_key,
                    'reversed_amount' => array_sum(array_column($mirrored, 'amount')),
                ],
                request: $request,
            );

            return ['transaction' => $transaction->refresh(), 'correction' => $correction];
        }, 3);
    }
}
