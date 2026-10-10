<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\User;
use App\Services\LedgerReconciliation;
use App\Services\LedgerService;
use App\Services\SellerBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function balances(): SellerBalance
    {
        return app(SellerBalance::class);
    }

    private function seller(): User
    {
        return User::factory()->create();
    }

    private function order(User $buyer): Order
    {
        return $buyer->orders()->create([
            'total_amount' => 0,
            'merchandise_subtotal' => 0,
            'platform_fee' => 0,
            'rental_deposit' => 0,
            'fee_currency' => 'IDR',
            'status' => Order::STATUS_PROCESSING,
        ]);
    }

    private function fulfillment(Order $order, int $sellerId, string $status, string $settlement): OrderFulfillment
    {
        return $order->fulfillments()->create([
            'seller_id' => $sellerId,
            'seller_name' => 'Seller '.$sellerId,
            'status' => $status,
            'settlement_status' => $settlement,
        ]);
    }

    private function line(Order $order, OrderFulfillment $fulfillment, int $unitPrice, int $quantity): void
    {
        $order->items()->create([
            'fulfillment_id' => $fulfillment->id,
            'product_name' => 'Costume',
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
        ]);
    }

    public function test_settled_fulfillment_classifies_ledger_liability_without_stored_balance(): void
    {
        $buyer = User::factory()->create();
        $order = $this->order($buyer);
        $seller = $this->seller();
        $fulfillment = $this->fulfillment($order, $seller->id, OrderFulfillment::STATUS_COMPLETED, OrderFulfillment::SETTLEMENT_AVAILABLE);
        $this->line($order, $fulfillment, 140_000, 1);

        $this->ledger()->post('checkout:'.$order->id.':paid', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 150_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 150_000],
        ], referenceType: 'order', referenceId: $order->id);

        $balance = $this->balances()->for($seller->id);

        $this->assertSame(0, $balance['pending']);
        $this->assertSame(140_000, $balance['available']);
        $this->assertSame(0, $balance['reserved']);
        $this->assertSame(0, $balance['paid']);
        $this->assertSame(150_000, $balance['liability']);
        // Ledger credit (150k) exceeds classified line total (140k): surfaced, never hidden.
        $this->assertSame(10_000, $balance['unclassified']);
        $this->assertFalse($balance['balanced']);
    }

    public function test_pending_fulfillment_is_classified_but_not_available(): void
    {
        $buyer = User::factory()->create();
        $order = $this->order($buyer);
        $seller = $this->seller();
        $fulfillment = $this->fulfillment($order, $seller->id, OrderFulfillment::STATUS_COMPLETED, OrderFulfillment::SETTLEMENT_PENDING);
        $this->line($order, $fulfillment, 90_000, 2);

        $this->ledger()->post('checkout:'.$order->id.':paid', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 180_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 180_000],
        ]);

        $balance = $this->balances()->for($seller->id);

        $this->assertSame(180_000, $balance['pending']);
        $this->assertSame(0, $balance['available']);
        $this->assertTrue($balance['balanced']);
    }

    public function test_non_terminal_fulfillment_is_not_classified(): void
    {
        $buyer = User::factory()->create();
        $order = $this->order($buyer);
        $seller = $this->seller();
        $fulfillment = $this->fulfillment($order, $seller->id, OrderFulfillment::STATUS_READY, OrderFulfillment::SETTLEMENT_AVAILABLE);
        $this->line($order, $fulfillment, 50_000, 1);

        $balance = $this->balances()->for($seller->id);

        $this->assertSame(0, $balance['classified']);
        $this->assertSame(0, $balance['available']);
        $this->assertTrue($balance['balanced']);
    }

    public function test_money_posted_without_a_settlement_record_is_reported_as_exception(): void
    {
        $buyer = User::factory()->create();
        $order = $this->order($buyer);
        $seller = $this->seller();

        $this->ledger()->post('orphan:'.$order->id, [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 42_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 42_000],
        ]);

        $report = app(LedgerReconciliation::class)->report();

        $this->assertFalse($report['clean']);
        $types = array_column($report['exceptions'], 'type');
        $this->assertContains('unclassified_seller_balance', $types);
        $this->assertTrue($report['balanced']);
        $this->assertSame('unavailable_until_payment_provider', $report['provider_reconciliation']);
    }

    public function test_reconciliation_is_clean_when_ledger_matches_settlements(): void
    {
        $buyer = User::factory()->create();
        $order = $this->order($buyer);
        $seller = $this->seller();
        $fulfillment = $this->fulfillment($order, $seller->id, OrderFulfillment::STATUS_COMPLETED, OrderFulfillment::SETTLEMENT_AVAILABLE);
        $this->line($order, $fulfillment, 75_000, 1);

        $this->ledger()->post('checkout:'.$order->id.':paid', [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 75_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 75_000],
        ]);

        $report = app(LedgerReconciliation::class)->report();

        $this->assertTrue($report['clean']);
        $this->assertTrue($report['balanced']);
        $this->assertSame(75_000, $report['total_debit']);
        $this->assertSame(75_000, $report['total_credit']);
        $this->assertSame([], $report['exceptions']);

        $this->artisan('ledger:reconcile')->assertExitCode(0);
    }

    public function test_reconcile_command_fails_when_exceptions_exist(): void
    {
        $buyer = User::factory()->create();
        $order = $this->order($buyer);
        $seller = $this->seller();

        $this->ledger()->post('orphan:'.$order->id, [
            ['account' => LedgerAccount::CASH, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 1_000],
            ['account' => LedgerAccount::sellerPayableCode($seller->id), 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 1_000],
        ]);

        $this->artisan('ledger:reconcile')->assertExitCode(1);
    }
}
