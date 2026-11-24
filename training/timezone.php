<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

function trainingDb(): PDO
{
    return db();
}
