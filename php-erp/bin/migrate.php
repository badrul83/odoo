<?php
declare(strict_types=1);

use Erp\Core\Config;
use Erp\Core\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

$config = Config::fromEnvironment(dirname(__DIR__));
$pdo = Database::connection($config);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (' .
    'version VARCHAR(190) NOT NULL PRIMARY KEY, ' .
    'checksum CHAR(64) NOT NULL, ' .
    'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' .
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$files = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
$applied = $pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll();
$appliedByVersion = [];
foreach ($applied as $migration) {
    $appliedByVersion[$migration['version']] = $migration['checksum'];
}

foreach ($files as $file) {
    $version = basename($file, '.sql');
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Could not read migration ' . $version);
    }
    $checksum = hash('sha256', $sql);
    if (isset($appliedByVersion[$version])) {
        if (!hash_equals($appliedByVersion[$version], $checksum)) {
            throw new RuntimeException('Applied migration was edited: ' . $version . '. Add a new migration instead.');
        }
        continue;
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sql), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    $insert = $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (:version, :checksum)');
    $insert->execute(['version' => $version, 'checksum' => $checksum]);
    fwrite(STDOUT, "Applied {$version}\n");
}

fwrite(STDOUT, "Database migrations are up to date.\n");
