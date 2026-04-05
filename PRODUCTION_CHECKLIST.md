# Production Checklist

## Required

- Run `php artisan migrate`
- Run `php artisan storage:link`
- Configure mail in `.env` and verify outbound delivery
- Ensure the scheduler runs:
  - `php artisan schedule:work`
  - or a cron/task runner that calls `php artisan schedule:run`

## Commission Workflow

- Set `GCash account name` and `GCash number` in admin settings
- Generate commission records from the admin commissions page
- Confirm partner proof uploads open correctly in the admin modal preview
- Confirm overdue reminders are being sent by the scheduler

## Recommended

- Verify `APP_URL` and `NEXT_PUBLIC_API_URL` use the correct production hosts
- Verify uploaded files persist across deploys
- Back up the database before first production rollout
- Test one full partner payment flow in staging before enabling for live restaurants
