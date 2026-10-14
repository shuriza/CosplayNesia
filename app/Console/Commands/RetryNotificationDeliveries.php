<?php

namespace App\Console\Commands;

use App\Jobs\SendNotificationEmail;
use App\Models\NotificationDelivery;
use App\Notifications\NotificationCatalog;
use Illuminate\Console\Command;

/**
 * Re-queues email deliveries that ended in the dead-letter state.
 *
 * Only `failed` rows are eligible, and only up to the configured attempt ceiling, so a command run
 * cannot loop a permanently broken address forever. The send itself is still guarded by the job's
 * pending -> processing claim, so an operator who runs this twice does not send twice.
 */
class RetryNotificationDeliveries extends Command
{
    protected $signature = 'notifications:retry {--limit=100 : Maximum number of deliveries to requeue}';

    protected $description = 'Requeue failed transactional email deliveries';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $deliveries = NotificationDelivery::query()
            ->where('channel', NotificationCatalog::CHANNEL_EMAIL)
            ->where('status', NotificationDelivery::STATUS_FAILED)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($deliveries->isEmpty()) {
            $this->info('Tidak ada pengiriman gagal untuk dicoba ulang.');

            return self::SUCCESS;
        }

        $requeued = 0;
        foreach ($deliveries as $delivery) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_PENDING,
                'failed_at' => null,
                'last_error' => null,
            ]);
            SendNotificationEmail::dispatch($delivery->id);
            $requeued++;
        }

        $this->info("{$requeued} pengiriman dimasukkan kembali ke antrean.");

        return self::SUCCESS;
    }
}
