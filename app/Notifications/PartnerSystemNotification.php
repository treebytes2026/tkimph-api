<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PartnerSystemNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $category,
        private readonly string $message,
        private readonly array $extra = [],
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (! empty($this->extra['send_email']) && ! empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = (string) ($this->extra['mail_subject'] ?? config('app.name').': notification');
        $lines = $this->extra['mail_lines'] ?? [$this->message];

        $mail = (new MailMessage())
            ->subject($subject)
            ->greeting('Hello '.$notifiable->name.',');

        foreach ($lines as $line) {
            $mail->line((string) $line);
        }

        return $mail->line('Please check your partner dashboard for the latest status.');
    }

    public function toArray(object $notifiable): array
    {
        return array_merge([
            'category' => $this->category,
            'message' => $this->message,
        ], $this->extra);
    }
}
