<?php
declare(strict_types=1);

// XAMPP's usual local MariaDB defaults. Edit these values if yours differ.
$settings = [
    'host' => 'localhost',
    'database' => 'roomiesync',
    'username' => 'root',
    'password' => '',
];

// Keep personal credentials out of version control. Copy
// database.config.local.php.example to database.config.local.php to override.
$localConfig = __DIR__ . '/database.config.local.php';
if (is_file($localConfig)) {
    $localSettings = require $localConfig;
    if (is_array($localSettings)) {
        $settings = array_merge($settings, $localSettings);
    }
}

return $settings;
