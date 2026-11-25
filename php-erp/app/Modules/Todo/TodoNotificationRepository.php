<?php
declare(strict_types=1);

namespace Erp\Modules\Todo;

use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;

final class TodoNotificationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function inbox(UserContext $user, Authorization $auth, array $filters = []): array
    {
        if (!$auth->allows('todo.read')) throw new AccessDeniedException('You do not have permission to read To-do notifications.');
        [$scope, $scopeParams] = $this->scope($user, $auth);
        if ($scope === '') return ['records' => [], 'total' => 0, 'unread' => 0, 'limit' => 50, 'offset' => 0];
        $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $where = "n.user_id=:recipient AND n.type_key='todo.deadline' AND n.resource_model='todo.task' AND " . $scope;
        $params = ['recipient' => $user->id] + $scopeParams;
        $count = $this->pdo->prepare('SELECT COUNT(*) AS total,COALESCE(SUM(n.read_at IS NULL),0) AS unread FROM user_notifications n JOIN todo_tasks t ON t.id=n.resource_record_id AND t.company_id=n.company_id WHERE ' . $where);
        $count->execute($params);
        $totals = $count->fetch() ?: ['total' => 0, 'unread' => 0];
        $query = $this->pdo->prepare('SELECT n.id,n.company_id,n.title,n.body,n.resource_record_id,n.created_at,n.read_at,t.title AS task_title,t.deadline FROM user_notifications n JOIN todo_tasks t ON t.id=n.resource_record_id AND t.company_id=n.company_id WHERE ' . $where . ' ORDER BY n.created_at DESC,n.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) $query->bindValue(':' . $key, $value, PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        $records = $query->fetchAll();
        foreach ($records as &$record) {
            foreach (['id','company_id','resource_record_id'] as $key) $record[$key] = (int)$record[$key];
            $record['unread'] = $record['read_at'] === null;
        }
        unset($record);
        return ['records' => $records, 'total' => (int)$totals['total'], 'unread' => (int)$totals['unread'], 'limit' => $limit, 'offset' => $offset];
    }

    public function markRead(mixed $rawId, UserContext $user, Authorization $auth): void
    {
        $id = $this->positiveId($rawId);
        if (!$auth->allows('todo.read')) throw new AccessDeniedException('You do not have permission to update To-do notifications.');
        [$scope, $scopeParams] = $this->scope($user, $auth);
        if ($scope === '') throw new InvalidArgumentException('Notification was not found.');
        $find = $this->pdo->prepare("SELECT n.id FROM user_notifications n JOIN todo_tasks t ON t.id=n.resource_record_id AND t.company_id=n.company_id WHERE n.id=:id AND n.user_id=:recipient AND n.type_key='todo.deadline' AND " . $scope . ' LIMIT 1');
        $find->execute(['id' => $id, 'recipient' => $user->id] + $scopeParams);
        if (!$find->fetchColumn()) throw new InvalidArgumentException('Notification was not found.');
        $this->pdo->prepare('UPDATE user_notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=:id AND user_id=:recipient')->execute(['id' => $id, 'recipient' => $user->id]);
    }

    public function markAllRead(UserContext $user, Authorization $auth): int
    {
        if (!$auth->allows('todo.read')) throw new AccessDeniedException('You do not have permission to update To-do notifications.');
        [$scope, $scopeParams] = $this->scope($user, $auth);
        if ($scope === '') return 0;
        $sql = "UPDATE user_notifications n JOIN todo_tasks t ON t.id=n.resource_record_id AND t.company_id=n.company_id SET n.read_at=UTC_TIMESTAMP() WHERE n.user_id=:recipient AND n.type_key='todo.deadline' AND n.read_at IS NULL AND " . $scope;
        $query = $this->pdo->prepare($sql);
        $query->execute(['recipient' => $user->id] + $scopeParams);
        return $query->rowCount();
    }

    /** @return array{0:string,1:array<string,int>} */
    private function scope(UserContext $user, Authorization $auth): array
    {
        $ids = $user->companyIds;
        if ($user->superuser) $ids = array_map('intval', $this->pdo->query('SELECT id FROM companies WHERE active=1')->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $auth->allowsForCompany('todo.read', $id))));
        $parts = [];
        $params = [];
        foreach ($ids as $index => $companyId) {
            $companyKey = 'scope_company_' . $index;
            $parts[] = 'n.company_id=:' . $companyKey;
            $params[$companyKey] = $companyId;
            if (!$auth->allowsForCompany('settings.write', $companyId)) {
                $creatorKey = 'scope_creator_' . $index;
                $assigneeKey = 'scope_assignee_' . $index;
                $parts[count($parts) - 1] = '(n.company_id=:' . $companyKey . ' AND (t.created_by=:' . $creatorKey . ' OR EXISTS (SELECT 1 FROM todo_task_assignees ta WHERE ta.task_id=t.id AND ta.company_id=t.company_id AND ta.user_id=:' . $assigneeKey . ')))';
                $params[$creatorKey] = $user->id;
                $params[$assigneeKey] = $user->id;
            }
        }
        return [$parts ? '(' . implode(' OR ', $parts) . ')' : '', $params];
    }

    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException('Notification ID must be a positive integer.');
        return $id;
    }
}
