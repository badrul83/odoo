<?php
declare(strict_types=1);

use Erp\Core\Config;
use Erp\Core\Database;
use Erp\Modules\Todo\TodoReminderWorker;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
$config = Config::fromEnvironment(dirname(__DIR__));
try {
    $result = (new TodoReminderWorker(Database::connection($config)))->run();
    fwrite(STDOUT, sprintf("Processed %d companies; created %d To-do deadline notification(s).\n", $result['companies'], $result['created']));
} catch (Throwable $error) {
    error_log('To-do reminder worker failed: ' . $error->getMessage());
    fwrite(STDERR, "To-do deadline notifications could not be processed.\n");
    exit(1);
}
