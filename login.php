<?php
declare(strict_types=1);

$projectPath = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/login.php')));
if ($projectPath === '.' || $projectPath === '/') {
    $projectPath = '';
}

header('Location: ' . $projectPath . '/php-erp/public/', true, 302);
exit;
