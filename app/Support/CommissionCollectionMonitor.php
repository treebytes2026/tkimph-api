<?php

namespace App\Support;

use App\Models\CommissionCollection;
use App\Models\User;
use App\Notifications\AdminSystemNotification;
use App\Notifications\PartnerSystemNotification;

class CommissionCollectionMonitor
{
    public static function processOverdueCollections(): int
    {
        $rows = CommissionCollection::query()
            ->with(['restaurant.owner:id,name,email'])
            ->where('status', CommissionCollection::STATUS_PENDING)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->get();

        $processed = 0;

        foreach ($rows as $row) {
            $alreadyNotifiedToday = $row->last_overdue_notified_at !== null
                && $row->last_overdue_notified_at->isSameDay(now());

            if ($alreadyNotifiedToday) {
                continue;
            }

            $processed++;

            if ($row->restaurant?->owner) {
                $row->restaurant->owner->notify(new PartnerSystemNotification(
                    'commission_collection_overdue_partner',
                    'Your restaurant has overdue commission payment. Admin has been notified and will decide the next action.',
                    [
                        'collection_id' => $row->id,
                        'restaurant_id' => $row->restaurant_id,
                        'period_from' => $row->period_from?->toDateString(),
                        'period_to' => $row->period_to?->toDateString(),
                        'due_date' => $row->due_date?->toDateString(),
                        'commission_amount' => (float) $row->commission_amount,
                        'send_email' => true,
                        'mail_subject' => config('app.name').': overdue commission payment',
                        'mail_lines' => [
                            'Your platform commission payment is overdue.',
                            'Admin has been notified and will review your account before deciding whether to restrict new orders.',
                            'Due date: '.$row->due_date?->toDateString(),
                            'Amount due: PHP '.number_format((float) $row->commission_amount, 2),
                        ],
                    ]
                ));
            }

            User::query()
                ->admins()
                ->each(fn (User $admin) => $admin->notify(new AdminSystemNotification(
                    'commission_collection_overdue_admin',
                    ($row->restaurant?->name ?? 'A restaurant').' has overdue commission payment and needs admin review.',
                    [
                        'collection_id' => $row->id,
                        'restaurant_id' => $row->restaurant_id,
                        'restaurant_name' => $row->restaurant?->name,
                        'due_date' => $row->due_date?->toDateString(),
                        'commission_amount' => (float) $row->commission_amount,
                    ]
                )));

            $row->update([
                'last_overdue_notified_at' => now(),
            ]);
        }

        return $processed;
    }
}
