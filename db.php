<?php
declare(strict_types=1);

function database_connection(): mysqli
{
    static $connection = null;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    $settings = require __DIR__ . '/database.config.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $connection = new mysqli(
            $settings['host'],
            $settings['username'],
            $settings['password'],
            $settings['database']
        );
        $connection->set_charset('utf8mb4');
        return $connection;
    } catch (Throwable $error) {
        error_log('RoomieSync database connection failed: ' . $error->getMessage());
        http_response_code(500);
        exit(
            'RoomieSync could not connect to MariaDB. Check database.config.php and make sure ' .
            'the roomiesync database has been imported from database.sql.'
        );
    }
}
