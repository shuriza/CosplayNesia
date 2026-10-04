<?php

namespace Tests\Feature;

use App\Models\FeePolicy;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LedgerService
    {
        return app(LedgerService::class);
    }

    public function test_migration_seeds_system_accounts_and_base_fee_policy(): void
    {
        foreach ([
            LedgerAccount::CASH,
            LedgerAccount::SELLER_PAYABLE,
            LedgerAccount::RENTAL_DEPOSIT_HELD,
            LedgerAccount::PLATFORM_FEE_REVENUE,
            LedgerAccount::SHIPPING_REVENUE,
            LedgerAccount::DISCOUNT_SUBSIDY,
            LedgerAccount::PAYMENT_GATEWAY_FEE,
        ] as $code) {
            $this->assertDatabaseHas('ledger_accounts', ['code' => $code, 'owner_type' => 'platform']);
        }

        $policy = FeePolicy::activeFor();
        $this->assertNotNull($policy);
        $this->assertSame('v1', $policy->version);
    }

    public function test_balanced_transaction_posts_and_derives_account_balances(): void
    {
        $transaction = $this->service()->post(
            eventKey: 'checkout:1:paid',
            entries: [
                ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 150_000],
                ['account' => LedgerAccount::sellerPayableCode(7), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 140_000],
                ['account' => LedgerAccount::PLATFORM_FEE_REVENUE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 10_000],
            ],
            referenceType: 'order',
            referenceId: 1,
        );

        $this->assertSame(3, $transaction->entries()->count());
        $this->assertSame(150_000, $this->service()->balance(LedgerAccount::CASH));
        $this->assertSame(140_000, $this->service()->balance(LedgerAccount::sellerPayableCode(7)));
        $this->assertSame(10_000, $this->service()->balance(LedgerAccount::PLATFORM_FEE_REVENUE));

        $this->assertDatabaseHas('ledger_accounts', [
            'code' => LedgerAccount::sellerPayableCode(7),
            'owner_type' => 'seller',
            'owner_id' => 7,
        ]);
    }

    public function test_unbalanced_transaction_is_rejected_and_posts_nothing(): void
    {
        try {
            $this->service()->post('bad', [
                ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 100],
                ['account' => LedgerAccount::PLATFORM_FEE_REVENUE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 90],
            ]);
            $this->fail('Expected unbalanced transaction to be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, LedgerTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_replayed_event_key_posts_exactly_once(): void
    {
        $entries = [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 50_000],
            ['account' => LedgerAccount::PLATFORM_FEE_REVENUE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 50_000],
        ];

        $first = $this->service()->post('webhook:abc', $entries);
        $second = $this->service()->post('webhook:abc', $entries);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, LedgerTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertSame(50_000, $this->service()->balance(LedgerAccount::PLATFORM_FEE_REVENUE));
    }

    public function test_entries_are_append_only(): void
    {
        $this->service()->post('immutable', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 1_000],
            ['account' => LedgerAccount::PLATFORM_FEE_REVENUE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 1_000],
        ]);

        $entry = LedgerEntry::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $entry->update(['amount' => 999_999]);
    }

    public function test_negative_amounts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->post('negative', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => -5],
            ['account' => LedgerAccount::PLATFORM_FEE_REVENUE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => -5],
        ]);
    }
}
