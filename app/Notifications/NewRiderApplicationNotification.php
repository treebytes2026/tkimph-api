<?php

namespace App\Notifications;

use App\Models\RiderApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewRiderApplicationNotification extends Notification
{
    use Queueable;

    public function __construct(public RiderApplication $application) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'rider_application',
            'id' => $this->application->id,
            'name' => $this->application->name,
            'email' => $this->application->email,
        ];
    }
}
