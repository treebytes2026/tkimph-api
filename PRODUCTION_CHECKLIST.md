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
- Set `APP_DEBUG=false`
- Set `CORS_ALLOWED_ORIGINS` to the deployed frontend origins. Include Expo web preview origins such as `http://localhost:8082` only while testing locally, then remove them for strict production.
- Set `TRUSTED_HOSTS` to only the production API/frontend hostnames
- Use Redis-backed throttling in production: `CACHE_STORE=redis` and `RATE_LIMIT_USE_REDIS=true`
- Put the API behind an edge provider or load balancer with DDoS protection, WAF rules, TLS termination, and request-size limits
- Configure web server limits for request body size, slow clients, headers, and timeouts before PHP/Laravel receives traffic
- Verify uploaded files persist across deploys
- Back up the database before first production rollout
- Test one full partner payment flow in staging before enabling for live restaurants
