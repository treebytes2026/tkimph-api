<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PartnerApplicationApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $token,
        public string $businessName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $query = http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
        $url = $frontend.'/reset-password?'.$query;

        return (new MailMessage)
            ->subject(config('app.name').': Your restaurant partner account is ready')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your restaurant partner application has been approved.')
            ->line('Your business "'.$this->businessName.'" can now be managed on '.config('app.name').'.')
            ->action('Set your password and get started', $url)
            ->line('This link expires in '.(int) config('auth.passwords.users.expire', 60).' minutes.')
            ->line('If you did not apply for a partner account, you can ignore this email.');
    }
}
