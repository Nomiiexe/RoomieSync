<?php
declare(strict_types=1);

function chores_pdo(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $settings = require dirname(__DIR__) . '/database.config.php';
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $settings['host'],
        $settings['database']
    );

    try {
        $connection = new PDO($dsn, $settings['username'], $settings['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $connection;
    } catch (Throwable $error) {
        error_log('RoomieSync PDO connection failed: ' . $error->getMessage());
        throw new RuntimeException('The database is temporarily unavailable.');
    }
}
