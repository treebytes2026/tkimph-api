<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
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
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_merge([
            'category' => $this->category,
            'message' => $this->message,
        ], $this->extra);
    }
}
