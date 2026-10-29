<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single write path for money.
 *
 * Every money movement is a balanced double-entry transaction: the sum of debits must equal the
 * sum of credits. Transactions are keyed by a unique event_key, so a retried webhook or replayed
 * job resolves to the same transaction instead of posting a second time. Entries are append-only;
 * corrections are new compensating transactions, never edits.
 */
class LedgerService
{
    /**
     * @param  array<int, array{account: int|string, direction: string, amount: int, currency?: string}>  $entries
     */
    public function post(
        string $eventKey,
        array $entries,
        ?string $description = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?\DateTimeInterface $occurredAt = null,
        ?int $reversesTransactionId = null,
    ): LedgerTransaction {
        if ($entries === []) {
            throw new InvalidArgumentException('A ledger transaction needs at least one entry.');
        }

        $normalized = [];
        $debits = 0;
        $credits = 0;

        foreach ($entries as $entry) {
            $direction = $entry['direction'];
            if (! in_array($direction, [LedgerEntry::DIRECTION_DEBIT, LedgerEntry::DIRECTION_CREDIT], true)) {
                throw new InvalidArgumentException("Unknown ledger direction [{$direction}].");
            }

            $amount = (int) $entry['amount'];
            if ($amount <= 0) {
                throw new InvalidArgumentException('Ledger amounts must be positive integers.');
            }

            $direction === LedgerEntry::DIRECTION_DEBIT ? $debits += $amount : $credits += $amount;

            $normalized[] = [
                'account' => $entry['account'],
                'direction' => $direction,
                'amount' => $amount,
                'currency' => $entry['currency'] ?? 'IDR',
            ];
        }

        if ($debits !== $credits) {
            throw new InvalidArgumentException("Unbalanced ledger transaction: debits [{$debits}] vs credits [{$credits}].");
        }

        return DB::transaction(function () use ($eventKey, $normalized, $description, $referenceType, $referenceId, $occurredAt, $reversesTransactionId): LedgerTransaction {
            $existing = LedgerTransaction::query()->where('event_key', $eventKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            $transaction = LedgerTransaction::query()->create([
                'event_key' => $eventKey,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reverses_transaction_id' => $reversesTransactionId,
                'occurred_at' => $occurredAt ?? now(),
            ]);

            $timestamp = now();
            $rows = [];
            foreach ($normalized as $entry) {
                $rows[] = [
                    'ledger_transaction_id' => $transaction->id,
                    'ledger_account_id' => $this->resolveAccountId($entry['account']),
                    'direction' => $entry['direction'],
                    'amount' => $entry['amount'],
                    'currency' => $entry['currency'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            LedgerEntry::query()->insert($rows);

            return $transaction;
        }, 3);
    }

    /**
     * Net balance of an account in the account's natural direction: debits minus credits for
     * assets/expenses, credits minus debits for liabilities/revenue.
     */
    public function balance(int|string $account): int
    {
        $accountId = $this->resolveAccountId($account);
        $type = LedgerAccount::query()->whereKey($accountId)->value('type');

        $debits = (int) LedgerEntry::query()
            ->where('ledger_account_id', $accountId)
            ->where('direction', LedgerEntry::DIRECTION_DEBIT)
            ->sum('amount');
        $credits = (int) LedgerEntry::query()
            ->where('ledger_account_id', $accountId)
            ->where('direction', LedgerEntry::DIRECTION_CREDIT)
            ->sum('amount');

        return in_array($type, [LedgerAccount::TYPE_ASSET, LedgerAccount::TYPE_EXPENSE], true)
            ? $debits - $credits
            : $credits - $debits;
    }

    public function hasPosted(string $eventKey): bool
    {
        return LedgerTransaction::query()->where('event_key', $eventKey)->exists();
    }

    private function resolveAccountId(int|string $account): int
    {
        if (is_int($account)) {
            return $account;
        }

        $existing = LedgerAccount::query()->where('code', $account)->value('id');
        if ($existing !== null) {
            return $existing;
        }

        [$baseCode, $ownerId] = array_pad(explode(':', $account, 2), 2, null);
        $ownerType = $baseCode === LedgerAccount::SELLER_PAYABLE ? 'seller' : null;

        return LedgerAccount::query()->firstOrCreate(
            ['code' => $account],
            [
                'name' => $account,
                'type' => LedgerAccount::TYPE_LIABILITY,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId !== null ? (int) $ownerId : null,
            ],
        )->id;
    }
}
