<?php

/**
 * NSBM SportsHub - Database Connection
 * Shared PDO connection for all modules.
 */

date_default_timezone_set('Asia/Colombo');

function db(): PDO
{
    static $connection = null;

    if ($connection === null) {
        $host = getenv('DB_HOST');
        $port = getenv('DB_PORT');
        $database = getenv('DB_NAME');
        $username = getenv('DB_USER');
        $password = getenv('DB_PASSWORD');

        if (!$host || !$port || !$database || !$username || $password === false) {
            throw new RuntimeException('Database configuration is missing.');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        $connection = new PDO(
            $dsn,
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        $connection->exec("SET SESSION time_zone = '+05:30'");
    }

    return $connection;
}