<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmEmailChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Konfirmasi email baru CosplayNesia')
            ->line('Konfirmasi alamat ini sebelum menjadi email aktif akunmu.')
            ->action('Konfirmasi email baru', $this->url)
            ->line('Tautan ini berlaku selama 60 menit.');
    }
}
