<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable): MailMessage
    {
        $url = url('/?reset_token='.rawurlencode($this->token).'&email='.rawurlencode($notifiable->getEmailForPasswordReset()));

        return (new MailMessage)
            ->subject('Reset kata sandi CosplayNesia')
            ->line('Kami menerima permintaan reset kata sandi akunmu.')
            ->action('Reset kata sandi', $url)
            ->line('Abaikan email ini jika kamu tidak meminta reset.');
    }
}
