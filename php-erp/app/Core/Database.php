<?php
declare(strict_types=1);

namespace Erp\Core;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(Config $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }
        if (!$config->hasDatabaseCredentials()) {
            throw new RuntimeException('Database environment variables are not configured.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->dbHost(),
            $config->dbPort(),
            $config->dbName(),
        );

        self::$connection = new PDO($dsn, $config->dbUser(), $config->dbPassword(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        return self::$connection;
    }
}
