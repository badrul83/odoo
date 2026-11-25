<?php
declare(strict_types=1);

namespace Erp\Modules\Todo;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class TodoReminderWorker
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{created:int,companies:int} */
    public function run(?DateTimeImmutable $instant = null): array
    {
        $instant ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $companies = $this->pdo->query('SELECT id,timezone FROM companies WHERE active=1 ORDER BY id')->fetchAll();
        $created = 0;
        $processed = 0;
        foreach ($companies as $company) {
            try {
                $timezone = new DateTimeZone((string)$company['timezone']);
                $today = $instant->setTimezone($timezone)->format('Y-m-d');
            } catch (Throwable $error) {
                error_log('To-do reminder skipped company ' . (int)$company['id'] . ' because its time zone is invalid: ' . $error->getMessage());
                continue;
            }
            $processed++;
            $created += $this->notifyAssignees((int)$company['id'], $today);
            $created += $this->notifyUnassignedCreators((int)$company['id'], $today);
        }
        return ['created' => $created, 'companies' => $processed];
    }

    private function notifyAssignees(int $companyId, string $today): int
    {
        $sql = "INSERT IGNORE INTO user_notifications (company_id,user_id,type_key,title,body,resource_model,resource_record_id,dedupe_key,created_at) " .
            "SELECT t.company_id,a.user_id,'todo.deadline','To-do deadline',CONCAT('Due ',t.deadline,': ',t.title),'todo.task',t.id, " .
            "CONCAT('todo.deadline:',t.company_id,':',t.id,':',a.user_id,':',t.deadline),UTC_TIMESTAMP() " .
            'FROM todo_tasks t JOIN todo_stages s ON s.id=t.stage_id AND s.company_id=t.company_id ' .
            'JOIN todo_task_assignees a ON a.task_id=t.id AND a.company_id=t.company_id ' .
            "JOIN users u ON u.id=a.user_id AND u.status IN ('active','confirmed') " .
            "WHERE t.company_id=:company AND t.reminder=1 AND t.deadline IS NOT NULL AND t.deadline<=:today AND t.archived_at IS NULL AND s.stage_type='open' " .
            "AND (u.is_superuser=1 OR EXISTS (SELECT 1 FROM user_role_links ur JOIN access_roles r ON r.id=ur.role_id AND r.active=1 AND (r.company_id IS NULL OR r.company_id=t.company_id) JOIN role_permission_links rp ON rp.role_id=r.id JOIN access_permissions p ON p.id=rp.permission_id AND p.permission_key='todo.read' WHERE ur.user_id=u.id))";
        $query = $this->pdo->prepare($sql);
        $query->execute(['company' => $companyId, 'today' => $today]);
        return $query->rowCount();
    }

    private function notifyUnassignedCreators(int $companyId, string $today): int
    {
        $sql = "INSERT IGNORE INTO user_notifications (company_id,user_id,type_key,title,body,resource_model,resource_record_id,dedupe_key,created_at) " .
            "SELECT t.company_id,t.created_by,'todo.deadline','To-do deadline',CONCAT('Due ',t.deadline,': ',t.title),'todo.task',t.id, " .
            "CONCAT('todo.deadline:',t.company_id,':',t.id,':',t.created_by,':',t.deadline),UTC_TIMESTAMP() " .
            'FROM todo_tasks t JOIN todo_stages s ON s.id=t.stage_id AND s.company_id=t.company_id ' .
            "JOIN users u ON u.id=t.created_by AND u.status IN ('active','confirmed') " .
            "WHERE t.company_id=:company AND t.reminder=1 AND t.deadline IS NOT NULL AND t.deadline<=:today AND t.archived_at IS NULL AND s.stage_type='open' " .
            'AND NOT EXISTS (SELECT 1 FROM todo_task_assignees a WHERE a.task_id=t.id AND a.company_id=t.company_id) ' .
            "AND (u.is_superuser=1 OR EXISTS (SELECT 1 FROM user_role_links ur JOIN access_roles r ON r.id=ur.role_id AND r.active=1 AND (r.company_id IS NULL OR r.company_id=t.company_id) JOIN role_permission_links rp ON rp.role_id=r.id JOIN access_permissions p ON p.id=rp.permission_id AND p.permission_key='todo.read' WHERE ur.user_id=u.id))";
        $query = $this->pdo->prepare($sql);
        $query->execute(['company' => $companyId, 'today' => $today]);
        return $query->rowCount();
    }
}
