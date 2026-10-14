<?php

namespace App\Jobs;

use App\Mail\TransactionalEventMail;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Sends one outbox row.
 *
 * Retry safety comes from the claim, not from the queue: a row is moved pending -> processing with
 * a conditional UPDATE, so a redelivered job (or a second worker that grabbed the same row) finds
 * nothing to claim and exits. Only after a successful send does the row become `sent`, and a sent
 * row is never claimed again. That is what makes "retry does not send twice" hold even though the
 * queue itself offers at-least-once delivery.
 *
 * On the sync driver (local dev, tests) a retry cannot happen, so a failure is recorded and
 * swallowed: rethrowing would surface an email problem as a failed checkout, which is worse than
 * a delayed message. On a real queue the exception is rethrown so the worker retries with backoff.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $claimed = NotificationDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('channel', NotificationCatalog::CHANNEL_EMAIL)
            ->where('status', NotificationDelivery::STATUS_PENDING)
            ->update([
                'status' => NotificationDelivery::STATUS_PROCESSING,
                // Counted by the database, not by the queue: on the sync driver there is no job
                // instance to ask, and a worker retry must not under-report.
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed === 0) {
            return;
        }

        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }

        $recipient = User::query()->find($delivery->recipient_id);
        if ($recipient === null || $recipient->deactivated_at !== null) {
            $delivery->update([
                'status' => NotificationDelivery::STATUS_SUPPRESSED,
                'last_error' => 'Penerima tidak lagi aktif.',
            ]);

            return;
        }

        try {
            $sent = Mail::to($recipient->email)->send(new TransactionalEventMail(
                eventType: $delivery->type,
                lines: NotificationCatalog::lines($delivery->payload ?? []),
                destination: url(NotificationCatalog::actionPath($delivery->type) ?? '/'),
                unsubscribeUrl: $this->unsubscribeUrl($delivery),
            ));

            $delivery->update([
                'status' => NotificationDelivery::STATUS_SENT,
                'provider_message_id' => $sent?->getMessageId(),
                'sent_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $this->recordFailure($delivery, $exception);

            if (config('queue.default') !== 'sync') {
                throw $exception;
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        if ($delivery === null || $delivery->isTerminal()) {
            return;
        }

        $delivery->update([
            'status' => NotificationDelivery::STATUS_FAILED,
            'failed_at' => now(),
            'last_error' => mb_substr($exception?->getMessage() ?? 'Pengiriman gagal.', 0, 500),
        ]);
    }

    private function recordFailure(NotificationDelivery $delivery, Throwable $exception): void
    {
        $delivery->update([
            'status' => NotificationDelivery::STATUS_FAILED,
            'failed_at' => now(),
            'last_error' => mb_substr($exception->getMessage(), 0, 500),
        ]);
    }

    /**
     * Signed, expiring, category-scoped: the link can only turn off the category that produced the
     * email, and it cannot be edited into a request for a different one.
     */
    private function unsubscribeUrl(NotificationDelivery $delivery): ?string
    {
        if (NotificationCatalog::isMandatoryCategory($delivery->category)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'notifications.unsubscribe',
            now()->addDays(30),
            ['user' => $delivery->recipient_id, 'category' => $delivery->category],
        );
    }
}
