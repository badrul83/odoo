<?php
declare(strict_types=1);

namespace Erp\Modules\Discuss;

use Erp\Core\Security\Authorization;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;

final class DiscussRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function options(UserContext $user, Authorization $auth, mixed $companyValue): array
    {
        $companies = $this->pdo->prepare('SELECT c.id,c.name FROM companies c JOIN user_company_access a ON a.company_id=c.id WHERE a.user_id=:user AND c.active=1 ORDER BY c.name,c.id');
        $companies->execute(['user' => $user->id]);
        if ($user->superuser) {
            $companies = $this->pdo->query('SELECT id,name FROM companies WHERE active=1 ORDER BY name,id');
        }
        $companyRows = array_values(array_filter(
            $companies->fetchAll(),
            static fn(array $row): bool => $auth->allowsForCompany('discuss.read', (int)$row['id'])
        ));
        $allowedCompanyIds = array_map(static fn(array $row): int => (int)$row['id'], $companyRows);
        $selectedCompany = $companyValue;
        if ($selectedCompany === null) {
            $defaultCompanyId = $user->defaultCompanyId;
            $selectedCompany = $defaultCompanyId !== null && in_array($defaultCompanyId, $allowedCompanyIds, true)
                ? $defaultCompanyId
                : ($companyRows[0]['id'] ?? null);
        }
        if ($selectedCompany === null) throw new AccessDeniedException('You do not have Discuss access in an active company.');
        $companyId = $this->companyId($selectedCompany, $user);
        $this->requireCompanyPermission($auth, $companyId, 'discuss.read');
        $users = $this->pdo->prepare("SELECT u.id,c.name FROM users u JOIN contacts c ON c.id=u.contact_id JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company WHERE u.status IN ('active','confirmed') AND u.id<>:current_user AND (u.is_superuser=1 OR EXISTS (SELECT 1 FROM user_role_links ur JOIN access_roles r ON r.id=ur.role_id AND r.active=1 JOIN role_permission_links rp ON rp.role_id=r.id JOIN access_permissions p ON p.id=rp.permission_id WHERE ur.user_id=u.id AND p.permission_key='discuss.read' AND (r.company_id IS NULL OR r.company_id=:permission_company))) ORDER BY c.name,u.id");
        $users->execute(['company' => $companyId, 'current_user' => $user->id, 'permission_company' => $companyId]);
        return ['company_id' => $companyId, 'companies' => $companyRows, 'users' => $users->fetchAll()];
    }

    public function workspace(UserContext $user, Authorization $auth, mixed $companyValue, string $filter): array
    {
        $companyId = $this->companyId($companyValue, $user);
        $this->requireCompanyPermission($auth, $companyId, 'discuss.read');
        if (!in_array($filter, ['inbox', 'starred', 'history'], true)) throw new InvalidArgumentException('Choose Inbox, Starred, or History.');
        $conditions = ["c.company_id=:company", 'c.active=1', "((c.conversation_type='direct' AND m.user_id IS NOT NULL) OR (c.conversation_type='channel' AND (c.privacy='public' OR m.user_id IS NOT NULL)))"];
        if ($filter === 'starred') $conditions[] = 'm.starred=1 AND m.archived_at IS NULL';
        elseif ($filter === 'history') $conditions[] = 'm.archived_at IS NOT NULL';
        else $conditions[] = '(m.user_id IS NULL OR m.archived_at IS NULL)';
        $sql = "SELECT c.id,c.company_id,c.conversation_type,c.name,c.description,c.privacy,
                       COALESCE(NULLIF(c.name,''), GROUP_CONCAT(DISTINCT pc.name ORDER BY pc.name SEPARATOR ', '), 'Direct message') AS display_name,
                       COALESCE(m.starred,0) AS starred, (m.archived_at IS NOT NULL) AS archived,
                       (SELECT COUNT(*) FROM discuss_messages um WHERE um.conversation_id=c.id AND um.company_id=c.company_id AND um.author_user_id<>:current_user AND (m.last_read_at IS NULL OR um.created_at>m.last_read_at)) AS unread_count,
                       (SELECT MAX(lm.id) FROM discuss_messages lm WHERE lm.conversation_id=c.id AND lm.company_id=c.company_id) AS last_message_id,
                       (SELECT lm.body FROM discuss_messages lm WHERE lm.conversation_id=c.id AND lm.company_id=c.company_id ORDER BY lm.id DESC LIMIT 1) AS last_message,
                       GROUP_CONCAT(DISTINCT pc.name ORDER BY pc.name SEPARATOR ', ') AS participant_names
                FROM discuss_conversations c
                LEFT JOIN discuss_members m ON m.conversation_id=c.id AND m.company_id=c.company_id AND m.user_id=:member_user
                LEFT JOIN discuss_members dm ON dm.conversation_id=c.id AND dm.company_id=c.company_id
                LEFT JOIN users pu ON pu.id=dm.user_id AND c.conversation_type='direct'
                LEFT JOIN contacts pc ON pc.id=pu.contact_id
                WHERE " . implode(' AND ', $conditions) . "
                GROUP BY c.id,c.company_id,c.conversation_type,c.name,c.description,c.privacy,m.starred,m.archived_at,m.last_read_at
                ORDER BY COALESCE((SELECT MAX(om.id) FROM discuss_messages om WHERE om.conversation_id=c.id AND om.company_id=c.company_id),0) DESC,c.name,c.id";
        $query = $this->pdo->prepare($sql);
        $query->execute(['company' => $companyId, 'current_user' => $user->id, 'member_user' => $user->id]);
        return ['company_id' => $companyId, 'filter' => $filter, 'conversations' => $query->fetchAll()];
    }

    public function conversation(int $id, UserContext $user, Authorization $auth, int $beforeId = 0): array
    {
        $conversation = $this->findAccessibleConversation($id, $user, $auth);
        if (!$conversation) throw new InvalidArgumentException('Conversation was not found or is outside your allowed company or channel access.');
        $membership = $this->membership($id, $user->id);
        $messages = $this->pdo->prepare('SELECT m.id,m.body,m.created_at,m.author_user_id,c.name AS author_name FROM discuss_messages m JOIN users u ON u.id=m.author_user_id JOIN contacts c ON c.id=u.contact_id WHERE m.conversation_id=:conversation AND m.company_id=:company AND (:before_id=0 OR m.id<:before_id2) ORDER BY m.id DESC LIMIT 50');
        $messages->execute(['conversation' => $id, 'company' => $conversation['company_id'], 'before_id' => max(0, $beforeId), 'before_id2' => max(0, $beforeId)]);
        $rows = array_reverse($messages->fetchAll());
        $members = $this->pdo->prepare("SELECT u.id,c.name FROM discuss_members m JOIN users u ON u.id=m.user_id JOIN contacts c ON c.id=u.contact_id WHERE m.conversation_id=:conversation AND m.company_id=:company ORDER BY c.name,u.id");
        $members->execute(['conversation' => $id, 'company' => $conversation['company_id']]);
        return ['conversation' => $conversation + ['is_member' => $membership !== null, 'starred' => (int)($membership['starred'] ?? 0), 'archived' => $membership !== null && $membership['archived_at'] !== null], 'messages' => $rows, 'members' => $members->fetchAll(), 'has_older' => count($rows) === 50];
    }

    public function createChannel(array $input, UserContext $user, Authorization $auth, string $ip): array
    {
        $companyId = $this->companyId($input['company_id'] ?? null, $user);
        $this->requireCompanyPermission($auth, $companyId, 'discuss.write');
        $this->requireCompanyPermission($auth, $companyId, 'discuss.read');
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $privacy = (string)($input['privacy'] ?? 'public');
        if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Channel name is required and must be at most 120 characters.');
        if (mb_strlen($description) > 1000) throw new InvalidArgumentException('Channel description must be at most 1,000 characters.');
        if (!in_array($privacy, ['public', 'private'], true)) throw new InvalidArgumentException('Choose Public or Private channel visibility.');
        $memberIds = $this->userIds($input['member_ids'] ?? [], $user->id);
        $this->assertCompanyUsers($memberIds, $companyId);
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare("INSERT INTO discuss_conversations (company_id,conversation_type,name,description,privacy,created_by) VALUES (:company,'channel',:name,:description,:privacy,:creator)");
            $insert->execute(['company' => $companyId, 'name' => $name, 'description' => $description, 'privacy' => $privacy, 'creator' => $user->id]);
            $id = (int)$this->pdo->lastInsertId();
            $ids = array_values(array_unique(array_merge([$user->id], $memberIds)));
            $member = $this->pdo->prepare('INSERT INTO discuss_members (conversation_id,company_id,user_id,created_by,last_read_at) VALUES (:conversation,:company,:user,:creator,CURRENT_TIMESTAMP)');
            foreach ($ids as $memberId) $member->execute(['conversation' => $id, 'company' => $companyId, 'user' => $memberId, 'creator' => $user->id]);
            $this->audit($companyId, $user->id, $id, 'discuss.channel', 'created', null, ['name' => $name, 'privacy' => $privacy, 'member_ids' => $ids], $ip);
            $this->pdo->commit();
            return $this->findAccessibleConversation($id, $user, $auth) ?? throw new InvalidArgumentException('The channel was created but could not be loaded.');
        } catch (\Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    public function createDirect(array $input, UserContext $user, Authorization $auth, string $ip): array
    {
        $companyId = $this->companyId($input['company_id'] ?? null, $user);
        $this->requireCompanyPermission($auth, $companyId, 'discuss.write');
        $this->requireCompanyPermission($auth, $companyId, 'discuss.read');
        $memberIds = $this->userIds($input['participant_ids'] ?? [], $user->id);
        if (!$memberIds) throw new InvalidArgumentException('Choose at least one person for the direct message.');
        $this->assertCompanyUsers($memberIds, $companyId);
        $allIds = array_values(array_unique(array_merge([$user->id], $memberIds)));
        sort($allIds, SORT_NUMERIC);
        $key = hash('sha256', implode(':', $allIds));
        $this->pdo->beginTransaction();
        try {
            $create = $this->pdo->prepare("INSERT INTO discuss_conversations (company_id,conversation_type,name,privacy,direct_key,created_by) VALUES (:company,'direct',NULL,'private',:direct_key,:creator) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),active=1");
            $create->execute(['company' => $companyId, 'direct_key' => $key, 'creator' => $user->id]);
            $id = (int)$this->pdo->lastInsertId();
            $member = $this->pdo->prepare('INSERT INTO discuss_members (conversation_id,company_id,user_id,created_by,last_read_at,archived_at) VALUES (:conversation,:company,:user,:creator,CURRENT_TIMESTAMP,NULL) ON DUPLICATE KEY UPDATE archived_at=IF(user_id=:current_user,NULL,archived_at),last_read_at=IF(user_id=:current_user2,CURRENT_TIMESTAMP,last_read_at)');
            foreach ($allIds as $memberId) $member->execute(['conversation' => $id, 'company' => $companyId, 'user' => $memberId, 'creator' => $user->id, 'current_user' => $user->id, 'current_user2' => $user->id]);
            $this->audit($companyId, $user->id, $id, 'discuss.direct', 'opened', null, ['participant_ids' => $allIds], $ip);
            $this->pdo->commit();
            return $this->findAccessibleConversation($id, $user, $auth) ?? throw new InvalidArgumentException('The direct message could not be loaded.');
        } catch (\Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    public function join(int $id, UserContext $user, Authorization $auth, string $ip): void
    {
        $conversation = $this->findAccessibleConversation($id, $user, $auth);
        if (!$conversation || $conversation['conversation_type'] !== 'channel' || $conversation['privacy'] !== 'public') throw new InvalidArgumentException('Only a public channel in an allowed company can be joined.');
        $this->requireCompanyPermission($auth, (int)$conversation['company_id'], 'discuss.write');
        $join = $this->pdo->prepare('INSERT INTO discuss_members (conversation_id,company_id,user_id,created_by,last_read_at) VALUES (:conversation,:company,:user,:creator,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE archived_at=NULL,last_read_at=COALESCE(last_read_at,CURRENT_TIMESTAMP)');
        $join->execute(['conversation' => $id, 'company' => $conversation['company_id'], 'user' => $user->id, 'creator' => $user->id]);
        $this->audit((int)$conversation['company_id'], $user->id, $id, 'discuss.channel', 'joined', null, ['user_id' => $user->id], $ip);
    }

    public function send(int $id, string $body, UserContext $user, Authorization $auth, string $ip): array
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4000) throw new InvalidArgumentException('Message text is required and must be at most 4,000 characters.');
        $conversation = $this->findAccessibleConversation($id, $user, $auth);
        if (!$conversation) throw new InvalidArgumentException('Conversation was not found or is outside your allowed company or channel access.');
        $this->requireCompanyPermission($auth, (int)$conversation['company_id'], 'discuss.write');
        if (!$this->membership($id, $user->id)) throw new InvalidArgumentException('Join the channel or reopen the direct message before sending.');
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT INTO discuss_messages (conversation_id,company_id,author_user_id,body) VALUES (:conversation,:company,:author,:body)');
            $insert->execute(['conversation' => $id, 'company' => $conversation['company_id'], 'author' => $user->id, 'body' => $body]);
            $messageId = (int)$this->pdo->lastInsertId();
            $created = $this->pdo->prepare('SELECT created_at FROM discuss_messages WHERE id=:id AND company_id=:company');
            $created->execute(['id' => $messageId, 'company' => $conversation['company_id']]);
            $createdAt = (string)$created->fetchColumn();
            $read = $this->pdo->prepare('UPDATE discuss_members SET last_read_at=CURRENT_TIMESTAMP,archived_at=NULL WHERE conversation_id=:conversation AND user_id=:user');
            $read->execute(['conversation' => $id, 'user' => $user->id]);
            $this->audit((int)$conversation['company_id'], $user->id, $messageId, 'discuss.message', 'sent', null, ['conversation_id' => $id, 'body_length' => mb_strlen($body)], $ip);
            $this->pdo->commit();
            return ['id' => $messageId, 'body' => $body, 'author_user_id' => $user->id, 'author_name' => $user->name, 'created_at' => $createdAt];
        } catch (\Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    public function markRead(int $id, UserContext $user, Authorization $auth): void
    {
        if (!$this->membership($id, $user->id) || !$this->findAccessibleConversation($id, $user, $auth)) throw new InvalidArgumentException('Join the conversation before marking it read.');
        $update = $this->pdo->prepare('UPDATE discuss_members SET last_read_at=CURRENT_TIMESTAMP WHERE conversation_id=:conversation AND user_id=:user');
        $update->execute(['conversation' => $id, 'user' => $user->id]);
    }

    public function preference(int $id, string $key, bool $value, UserContext $user, Authorization $auth, string $ip): void
    {
        if (!in_array($key, ['starred', 'archived_at'], true)) throw new InvalidArgumentException('Unsupported conversation preference.');
        $membership = $this->membership($id, $user->id);
        if (!$membership || !$this->findAccessibleConversation($id, $user, $auth)) throw new InvalidArgumentException('Conversation was not found in your allowed access.');
        $field = $key === 'starred' ? 'starred' : 'archived_at';
        $valueSql = $key === 'starred' ? ':value' : ($value ? 'UTC_TIMESTAMP()' : 'NULL');
        $sql = "UPDATE discuss_members SET {$field}={$valueSql} WHERE conversation_id=:conversation AND user_id=:user";
        $update = $this->pdo->prepare($sql);
        $params = ['conversation' => $id, 'user' => $user->id];
        if ($key === 'starred') $params['value'] = $value ? 1 : 0;
        $update->execute($params);
        $this->audit((int)$membership['company_id'], $user->id, $id, 'discuss.conversation', $key . ($value ? '_enabled' : '_disabled'), null, [$key => $value], $ip);
    }

    private function findAccessibleConversation(int $id, UserContext $user, Authorization $auth): ?array
    {
        if (!$user->superuser && !$user->companyIds) return null;
        $companyClause = '1=1';
        $params = ['id' => $id, 'channel_member' => $user->id, 'direct_member' => $user->id];
        if (!$user->superuser) {
            $companyPlaceholders = [];
            foreach ($user->companyIds as $index => $companyId) { $name = 'company_' . $index; $companyPlaceholders[] = ':' . $name; $params[$name] = $companyId; }
            $companyClause = 'c.company_id IN (' . implode(',', $companyPlaceholders) . ')';
        }
        $sql = "SELECT c.id,c.company_id,c.conversation_type,c.name,c.description,c.privacy,
                       COALESCE(NULLIF(c.name,''), GROUP_CONCAT(DISTINCT pc.name ORDER BY pc.name SEPARATOR ', '), 'Direct message') AS display_name
                FROM discuss_conversations c
                LEFT JOIN discuss_members dm ON dm.conversation_id=c.id AND dm.company_id=c.company_id AND c.conversation_type='direct'
                LEFT JOIN users pu ON pu.id=dm.user_id
                LEFT JOIN contacts pc ON pc.id=pu.contact_id
                WHERE c.id=:id AND c.active=1 AND {$companyClause}
                  AND (c.conversation_type='channel' AND (c.privacy='public' OR EXISTS (SELECT 1 FROM discuss_members x WHERE x.conversation_id=c.id AND x.user_id=:channel_member))
                       OR c.conversation_type='direct' AND EXISTS (SELECT 1 FROM discuss_members x WHERE x.conversation_id=c.id AND x.user_id=:direct_member))
                GROUP BY c.id,c.company_id,c.conversation_type,c.name,c.description,c.privacy";
        $query = $this->pdo->prepare($sql); $query->execute($params); $row = $query->fetch();
        if ($row && !$auth->allowsForCompany('discuss.read', (int)$row['company_id'])) {
            throw new AccessDeniedException('You do not have permission to read Discuss conversations in this company.');
        }
        return $row ?: null;
    }

    private function requireCompanyPermission(Authorization $auth, int $companyId, string $permission): void
    {
        if (!$auth->allowsForCompany($permission, $companyId)) {
            $action = $permission === 'discuss.write' ? 'write' : 'read';
            throw new AccessDeniedException('You do not have permission to ' . $action . ' Discuss conversations in this company.');
        }
    }

    private function membership(int $conversationId, int $userId): ?array
    {
        $query = $this->pdo->prepare('SELECT company_id,starred,archived_at,last_read_at FROM discuss_members WHERE conversation_id=:conversation AND user_id=:user');
        $query->execute(['conversation' => $conversationId, 'user' => $userId]);
        return $query->fetch() ?: null;
    }

    private function companyId(mixed $value, UserContext $user): int
    {
        $id = filter_var($value ?? $user->defaultCompanyId, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1 || (!$user->superuser && !in_array((int)$id, $user->companyIds, true))) throw new InvalidArgumentException('Choose a company you are authorized to access.');
        $query = $this->pdo->prepare('SELECT id FROM companies WHERE id=:id AND active=1'); $query->execute(['id' => $id]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('The selected company is inactive or unavailable.');
        return (int)$id;
    }

    private function userIds(mixed $value, int $currentUser): array
    {
        if (!is_array($value)) throw new InvalidArgumentException('Choose participants from the user list.');
        $ids = [];
        foreach ($value as $item) { $id = filter_var($item, FILTER_VALIDATE_INT); if ($id === false || $id < 1) throw new InvalidArgumentException('Participant IDs must be positive integers.'); if ($id !== $currentUser) $ids[] = (int)$id; }
        return array_values(array_unique($ids));
    }

    private function assertCompanyUsers(array $ids, int $companyId): void
    {
        if (!$ids) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->pdo->prepare("SELECT u.id FROM users u JOIN user_company_access a ON a.user_id=u.id AND a.company_id=? WHERE u.status IN ('active','confirmed') AND u.id IN ({$placeholders}) AND (u.is_superuser=1 OR EXISTS (SELECT 1 FROM user_role_links ur JOIN access_roles r ON r.id=ur.role_id AND r.active=1 JOIN role_permission_links rp ON rp.role_id=r.id JOIN access_permissions p ON p.id=rp.permission_id WHERE ur.user_id=u.id AND p.permission_key='discuss.read' AND (r.company_id IS NULL OR r.company_id=?)))");
        $query->execute(array_merge([$companyId], $ids, [$companyId]));
        if (count($query->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) throw new InvalidArgumentException('Every participant must be an active user with access to this company.');
    }

    private function audit(int $company, int $actor, int $record, string $model, string $event, ?array $old, array $new, string $remote): void
    {
        $ip = filter_var($remote, FILTER_VALIDATE_IP) ? inet_pton($remote) : null;
        $query = $this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,:record,:old_values,:new_values,:ip)');
        $query->bindValue(':company', $company, PDO::PARAM_INT); $query->bindValue(':user', $actor, PDO::PARAM_INT);
        $query->bindValue(':event', $event); $query->bindValue(':model', $model); $query->bindValue(':record', $record, PDO::PARAM_INT);
        $query->bindValue(':old_values', $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $old === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $query->bindValue(':new_values', json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $query->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB); $query->execute();
    }
}
