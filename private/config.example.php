<?php
// Copy to config.php OUTSIDE public_html, then fill in your hosting details.
return [
    'environment' => 'production',
    'site_url' => 'https://your-domain.co.ke',
    'dsn' => 'mysql:host=localhost;dbname=CPANEL_DATABASE;charset=utf8mb4',
    'db_user' => 'CPANEL_DATABASE_USER',
    'db_password' => 'YOUR_DATABASE_PASSWORD',
    'app_key' => 'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',
    'mail_from' => 'accounts@your-domain.co.ke',
    'mail_transport' => 'mail',
    // Used only in local development. Never put this inside public_html.
    'mail_log' => __DIR__ . '/mail-outbox.jsonl',
];
