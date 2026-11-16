<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class MobileVerifyEmail extends Notification
{
    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verifikasi email AD Com')
            ->line('Konfirmasi email ini untuk mulai memesan dan menggunakan aplikasi AD Com.')
            ->action('Verifikasi email', URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addMinutes(60), [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]))
            ->line('Tautan berlaku selama 60 menit. Setelah berhasil, kembali ke aplikasi dan muat ulang profil.')
            ->line('Jika Anda tidak membuat akun ini, abaikan email ini.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
