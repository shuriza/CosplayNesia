<?php

namespace App\Http\Controllers\Backoffice;

use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\LedgerCorrection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correcting money that was already posted.
 *
 * This never edits a row. A correction is a second, mirrored transaction that nets the mistake to
 * zero, linked back to the original. Both remain readable forever, so an auditor sees the error,
 * the fix, and who made each — which an UPDATE could never provide.
 */
class CorrectionController extends BackofficeController
{
    public function reverseTransaction(
        Request $request,
        LedgerTransaction $transaction,
        LedgerCorrection $corrections,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $result = $corrections->reverse($request->user(), $transaction, $validated['reason'], $request);

        return response()->json([
            'transaction' => $this->payload($result['transaction']),
            'correction' => $this->payload($result['correction']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(LedgerTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'event_key' => $transaction->event_key,
            'description' => $transaction->description,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'reverses_transaction_id' => $transaction->reverses_transaction_id,
            'occurred_at' => $transaction->occurred_at,
            'entries' => $transaction->entries->map(fn (LedgerEntry $entry): array => [
                'account_code' => $entry->account?->code,
                'direction' => $entry->direction,
                'amount' => $entry->amount,
                'currency' => $entry->currency,
            ])->values(),
        ];
    }
}
