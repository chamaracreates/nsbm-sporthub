<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

/* Use Sri Lanka local time for training schedules. */
date_default_timezone_set('Asia/Colombo');

/* Configure the database connection for training operations. */
function trainingDb(): PDO
{
    $pdo = db();

    /* Apply Sri Lanka time to this MySQL session only. */
    $pdo->exec("SET SESSION time_zone = '+05:30'");

    return $pdo;
}
