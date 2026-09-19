<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\RetryNotificationJob;
use Tests\TestCase;
use Throwable;

class PostgresRuntimeInvariantTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as runPostgresDatabaseMigrations;
    }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->runPostgresDatabaseMigrations();
        }
    }

    public function test_parallel_checkout_writers_cannot_oversell_one_unit(): void
    {
        $this->requirePostgres();
        if (! function_exists('pcntl_fork')) {
            $this->fail('The PostgreSQL concurrency gate requires the PCNTL extension.');
        }

        $seller = User::factory()->create();
        $buyers = User::factory()->count(2)->create();
        $product = Product::factory()->for($seller, 'owner')->create(['stock' => 1, 'type' => Product::TYPE_SALE]);
        $readyFile = tempnam(sys_get_temp_dir(), 'checkout-ready-');
        $firstResult = tempnam(sys_get_temp_dir(), 'checkout-first-');
        $secondResult = tempnam(sys_get_temp_dir(), 'checkout-second-');

        try {
            $firstPid = pcntl_fork();
            if ($firstPid === -1) {
                throw new RuntimeException('Unable to fork the first checkout writer.');
            }
            if ($firstPid === 0) {
                $this->runLockHoldingCheckout($buyers[0]->id, $product->id, $readyFile, $firstResult);
            }

            $this->waitForFileContent($readyFile, 'locked');
            $secondPid = pcntl_fork();
            if ($secondPid === -1) {
                throw new RuntimeException('Unable to fork the second checkout writer.');
            }
            if ($secondPid === 0) {
                $this->runCheckout($buyers[1]->id, $product->id, $secondResult);
            }

            pcntl_waitpid($firstPid, $firstStatus);
            pcntl_waitpid($secondPid, $secondStatus);
            $this->assertTrue(pcntl_wifexited($firstStatus));
            $this->assertTrue(pcntl_wifexited($secondStatus));

            $results = [trim((string) file_get_contents($firstResult)), trim((string) file_get_contents($secondResult))];
            sort($results);
            $this->assertSame(['insufficient_stock', 'success'], $results);

            DB::purge();
            $this->assertSame(0, Product::query()->findOrFail($product->id)->stock);
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('order_items', 1);
        } finally {
            @unlink($readyFile);
            @unlink($firstResult);
            @unlink($secondResult);
        }
    }

    public function test_database_worker_retry_does_not_duplicate_durable_side_effect(): void
    {
        $this->requirePostgres();
        $recipient = User::factory()->create();
        $actor = User::factory()->create();
        $eventKey = 'runtime:retry:'.str()->uuid();

        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.after_commit', true);
        Queue::connection('database')->push(new RetryNotificationJob($recipient->id, $actor->id, $eventKey));

        $exitCode = Artisan::call('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
            '--tries' => 2,
            '--backoff' => 0,
            '--sleep' => 0,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseHas('user_notifications', ['event_key' => $eventKey]);
    }

    private function runLockHoldingCheckout(int $buyerId, int $productId, string $readyFile, string $resultFile): never
    {
        DB::purge();

        try {
            DB::beginTransaction();
            Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            file_put_contents($readyFile, 'locked');
            sleep(1);
            app(CheckoutService::class)->create(User::query()->findOrFail($buyerId), [
                ['id' => $productId, 'quantity' => 1],
            ], 'parallel-first');
            DB::commit();
            file_put_contents($resultFile, 'success');
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            file_put_contents($resultFile, $exception instanceof InsufficientStockException ? 'insufficient_stock' : $exception::class);
        }

        exit(0);
    }

    private function runCheckout(int $buyerId, int $productId, string $resultFile): never
    {
        DB::purge();

        try {
            app(CheckoutService::class)->create(User::query()->findOrFail($buyerId), [
                ['id' => $productId, 'quantity' => 1],
            ], 'parallel-second');
            file_put_contents($resultFile, 'success');
        } catch (Throwable $exception) {
            file_put_contents($resultFile, $exception instanceof InsufficientStockException ? 'insufficient_stock' : $exception::class);
        }

        exit(0);
    }

    private function waitForFileContent(string $path, string $expected): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (trim((string) @file_get_contents($path)) === $expected) {
                return;
            }
            usleep(10_000);
        }

        throw new RuntimeException('Timed out waiting for the first checkout writer to acquire its row lock.');
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL runtime invariant.');
        }
    }
}
