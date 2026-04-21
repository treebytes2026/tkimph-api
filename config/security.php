<?php

return [
    'rate_limits' => [
        'use_redis' => (bool) env('RATE_LIMIT_USE_REDIS', false),
        'api_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),
        'api_ip_per_minute' => (int) env('API_IP_RATE_LIMIT_PER_MINUTE', 300),
        'public_api_per_minute' => (int) env('PUBLIC_API_RATE_LIMIT_PER_MINUTE', 60),
        'applications_per_10_minutes' => (int) env('APPLICATION_RATE_LIMIT_PER_10_MINUTES', 10),
        'login_per_minute' => (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 5),
        'login_ip_per_15_minutes' => (int) env('LOGIN_IP_RATE_LIMIT_PER_15_MINUTES', 30),
        'uploads_per_10_minutes' => (int) env('UPLOAD_RATE_LIMIT_PER_10_MINUTES', 20),
        'passwords_per_10_minutes' => (int) env('PASSWORD_RATE_LIMIT_PER_10_MINUTES', 5),
    ],
];
