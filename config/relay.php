<?php

declare(strict_types=1);

return [
    'telegram_base' => rtrim((string) env('TELEGRAM_API_BASE', 'https://api.telegram.org'), '/'),
    'path_prefix' => trim((string) env('RELAY_PATH_PREFIX', ''), '/'),
    'allowed_bot_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('RELAY_ALLOWED_BOT_IDS', ''))), fn ($id) => $id !== '')),
    'relay_key' => env('RELAY_KEY') ?: null,
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('RELAY_ALLOWED_IPS', ''))), fn ($ip) => $ip !== '')),
    'rate_limit' => (int) env('RELAY_RATE_LIMIT_PER_MIN', 120),
    'connect_timeout' => (int) env('RELAY_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('RELAY_TIMEOUT', 60),
    'file_timeout' => (int) env('RELAY_FILE_TIMEOUT', 120),
    'connect_retries' => (int) env('RELAY_CONNECT_RETRIES', 2),
    'webhook_rewrite' => (bool) env('RELAY_WEBHOOK_REWRITE', false),
    'allow_private_targets' => (bool) env('RELAY_ALLOW_PRIVATE_TARGETS', false),
    'webhook_forward_timeout' => (int) env('RELAY_WEBHOOK_FORWARD_TIMEOUT', 15),
    'db_logging' => (bool) env('RELAY_DB_LOGGING', true),
    'log_retention_days' => (int) env('RELAY_LOG_RETENTION_DAYS', 14),
];
