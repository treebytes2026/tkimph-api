<?php

namespace App\Notifications;

use App\Models\PartnerApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewPartnerApplicationNotification extends Notification
{
    use Queueable;

    public function __construct(public PartnerApplication $application) {}

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
            'type' => 'partner_application',
            'id' => $this->application->id,
            'business_name' => $this->application->business_name,
            'owner_name' => $this->application->ownerFullName(),
            'email' => $this->application->email,
        ];
    }
}
