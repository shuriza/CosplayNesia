<?php

namespace App\Mail;

use App\Notifications\NotificationCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One mailable for every transactional event.
 *
 * A mailable per type would multiply nine near-identical classes; the only real differences are
 * the subject, the projected lines, and the destination link, all of which the catalog owns. The
 * body is deliberately plain: no payload dump, no buyer email, no order id, no internal status.
 */
class TransactionalEventMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public readonly string $eventType,
        public readonly array $lines,
        public readonly string $destination,
        public readonly ?string $unsubscribeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: NotificationCatalog::subject($this->eventType));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.transactional-event',
            with: [
                'lines' => $this->lines,
                'destination' => $this->destination,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
