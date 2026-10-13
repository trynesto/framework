<?php

declare(strict_types=1);

/*
 * acme/mail configuration, available as $config->get('mail.*').
 * Copy it into your project with `php nesto config:publish mail` to change it.
 */

return [
    // log: append every message to log_path. array: keep messages in memory (tests).
    'driver' => env('MAIL_DRIVER', 'log'),

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    // Relative paths are resolved from the project root.
    'log_path' => env('MAIL_LOG_PATH', 'storage/logs/mail.log'),
];
