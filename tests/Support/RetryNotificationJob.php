<?php

namespace Tests\Support;

use App\Services\NotificationRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class RetryNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $recipientId,
        public int $actorId,
        public string $eventKey,
    ) {}

    public function handle(NotificationRecorder $notifications): void
    {
        $notifications->record([
            'recipient_id' => $this->recipientId,
            'actor_id' => $this->actorId,
            'type' => 'runtime.retry_probe',
            'event_key' => $this->eventKey,
        ]);

        if ($this->attempts() === 1) {
            throw new RuntimeException('Synthetic failure after the durable side effect.');
        }
    }
}
