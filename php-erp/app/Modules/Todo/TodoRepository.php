<?php
declare(strict_types=1);

namespace Erp\Modules\Todo;

use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final class TodoRepository
{
    private const DEFAULT_STAGES = [
        ['inbox', 'Inbox', 10, 0, 0, 'open', 1],
        ['today', 'Today', 20, 0, 0, 'open', 0],
        ['week', 'This Week', 30, 7, 0, 'open', 0],
        ['month', 'This Month', 40, 30, 0, 'open', 0],
        ['later', 'Later', 50, 90, 0, 'open', 0],
        ['done', 'Done', 60, 0, 1, 'done', 1],
        ['cancelled', 'Cancelled', 70, 0, 1, 'cancelled', 1],
    ];

    private const SOURCE_MODELS = [
        'contacts.contact' => ['permission' => 'contacts.read', 'title' => 'Contact'],
        'crm.lead' => ['permission' => 'crm.read', 'title' => 'CRM'],
        'sales.order' => ['permission' => 'sales.read', 'title' => 'Sales'],
        'purchase.order' => ['permission' => 'purchase.read', 'title' => 'Purchase'],
        'inventory.stock_move' => ['permission' => 'inventory.read', 'title' => 'Inventory'],
        'invoicing.customer_invoice' => ['permission' => 'invoicing.read', 'title' => 'Customer Invoice'],
        'purchase.vendor_bill' => ['permission' => 'invoicing.read', 'title' => 'Vendor Bill'],
        'accounting.entry' => ['permission' => 'accounting.read', 'title' => 'Accounting'],
        'contracts.contract' => ['permission' => 'contracts.read', 'title' => 'Contract'],
        'appointments.booking' => ['permission' => 'appointments.read', 'title' => 'Appointment'],
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function options(mixed $rawCompanyId, mixed $rawModel, mixed $rawQuery, UserContext $user, Authorization $auth): array
    {
        $companyId = $this->companyId($rawCompanyId ?? $user->defaultCompanyId, $auth);
        if (!$auth->allowsForCompany('todo.read', $companyId)) throw new AccessDeniedException('You do not have permission to read To-dos in this company.');
        $this->ensureDefaultStages($companyId);
        if ($user->superuser) {
            $companyQuery = $this->pdo->query('SELECT id,name FROM companies WHERE active=1 ORDER BY name,id');
        } elseif ($user->companyIds) {
            $slots = [];
            $parameters = [];
            foreach ($user->companyIds as $index => $allowedCompanyId) { $slot = ':company_' . $index; $slots[] = $slot; $parameters['company_' . $index] = $allowedCompanyId; }
            $companyQuery = $this->pdo->prepare('SELECT id,name FROM companies WHERE active=1 AND id IN (' . implode(',', $slots) . ') ORDER BY name,id');
            $companyQuery->execute($parameters);
        } else {
            $companyQuery = $this->pdo->query('SELECT id,name FROM companies WHERE active=1 AND 1=0');
        }
        $companyRows = array_values(array_filter(
            $companyQuery->fetchAll(),
            static fn(array $row): bool => $auth->allowsForCompany('todo.read', (int)$row['id'])
        ));
        $stages = $this->pdo->prepare('SELECT id,stage_key,name,sequence,rotting_days,folded_by_default,stage_type,is_required FROM todo_stages WHERE company_id=:company AND active=1 ORDER BY sequence,id');
        $stages->execute(['company' => $companyId]);
        $users = $this->pdo->prepare("SELECT u.id,c.name FROM users u JOIN contacts c ON c.id=u.contact_id JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company WHERE u.status IN ('active','confirmed') ORDER BY c.name,u.id");
        $users->execute(['company' => $companyId]);
        $contacts = [];
        if ($auth->allowsForCompany('contacts.read', $companyId)) {
            $contactsQuery = $this->pdo->prepare('SELECT id,name,company_id FROM contacts WHERE active=1 AND (company_id=:company OR company_id IS NULL) ORDER BY name,id LIMIT 500');
            $contactsQuery->execute(['company' => $companyId]);
            $contacts = $contactsQuery->fetchAll();
        }
        $models = [];
        foreach (self::SOURCE_MODELS as $key => $definition) {
            if ($auth->allowsForCompany($definition['permission'], $companyId)) $models[] = ['value' => $key, 'name' => $definition['title']];
        }
        $model = trim((string)($rawModel ?? ''));
        $query = trim((string)($rawQuery ?? ''));
        if (mb_strlen($query) > 100) throw new InvalidArgumentException('Source search text must be 100 characters or fewer.');
        $sources = $model === '' ? [] : $this->searchSources($companyId, $model, $query, $auth);
        return [
            'company_id' => $companyId,
            'companies' => array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'name'=>$row['name']], $companyRows),
            'stages' => array_map(static function (array $row): array {
                foreach (['id','sequence','rotting_days','folded_by_default','is_required'] as $key) $row[$key] = (int)$row[$key];
                return $row;
            }, $stages->fetchAll()),
            'assignees' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => $row['name']], $users->fetchAll()),
            'contacts' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => $row['name'], 'company_id' => $row['company_id'] === null ? null : (int)$row['company_id']], $contacts),
            'source_models' => $models,
            'sources' => $sources,
        ];
    }

    public function search(UserContext $user, Authorization $auth, array $filters): array
    {
        if (!$auth->allows('todo.read')) throw new AccessDeniedException('You do not have permission to read To-dos.');
        $companyId = $this->companyId($filters['company_id'] ?? $user->defaultCompanyId, $auth);
        if (!$auth->allowsForCompany('todo.read', $companyId)) throw new AccessDeniedException('You do not have permission to read To-dos in this company.');
        $this->ensureDefaultStages($companyId);
        $where = ['t.company_id=:company', 't.archived_at IS NULL'];
        $params = ['company' => $companyId];
        if (!$auth->allowsForCompany('settings.write', $companyId)) {
            $where[] = '(t.created_by=:viewer_creator OR EXISTS (SELECT 1 FROM todo_task_assignees va WHERE va.task_id=t.id AND va.user_id=:viewer_assignee))';
            $params['viewer_creator'] = $user->id;
            $params['viewer_assignee'] = $user->id;
        }
        $contactId = $filters['contact_id'] ?? null;
        if ($contactId !== null && $contactId !== '') {
            if (!$auth->allowsForCompany('contacts.read', $companyId)) throw new AccessDeniedException('You do not have permission to read Contact-linked To-dos.');
            $where[] = 't.contact_id=:contact'; $params['contact'] = $this->positiveId($contactId, 'Contact');
        }
        $stageId = $filters['stage_id'] ?? null;
        if ($stageId !== null && $stageId !== '') { $where[] = 't.stage_id=:stage'; $params['stage'] = $this->positiveId($stageId, 'Stage'); }
        $stateFilter = (string)($filters['state'] ?? 'open');
        if (!in_array($stateFilter, ['open','all','done','cancelled'], true)) throw new InvalidArgumentException('Select a valid To-do filter.');
        if ($stateFilter !== 'all') {
            $where[] = $stateFilter === 'open' ? "s.stage_type='open'" : 's.stage_type=:stage_type';
            if ($stateFilter !== 'open') $params['stage_type'] = $stateFilter;
        }
        $query = trim((string)($filters['q'] ?? ''));
        if (mb_strlen($query) > 100) throw new InvalidArgumentException('To-do search text must be 100 characters or fewer.');
        if ($query !== '') {
            $like = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $query) . '%';
            $searchable = ['t.title LIKE :q_title ESCAPE \'!\'', 't.tag LIKE :q_tag ESCAPE \'!\'', 't.description LIKE :q_description ESCAPE \'!\''];
            $params += ['q_title' => $like, 'q_tag' => $like, 'q_description' => $like];
            $sourceIndex = 0;
            foreach (self::SOURCE_MODELS as $model => $definition) {
                if (!$auth->allowsForCompany($definition['permission'], $companyId)) continue;
                $modelParam = 'source_model_' . $sourceIndex; $referenceParam = 'source_reference_' . $sourceIndex;
                $searchable[] = '((t.source_model=:' . $modelParam . ') AND t.source_reference LIKE :' . $referenceParam . ' ESCAPE \'!\')';
                $params[$modelParam] = $model; $params[$referenceParam] = $like; $sourceIndex++;
            }
            if ($auth->allowsForCompany('contacts.read', $companyId)) { $searchable[] = 'c.name LIKE :q_contact ESCAPE \'!\''; $params['q_contact'] = $like; }
            $where[] = '(' . implode(' OR ', $searchable) . ')';
        }
        $clause = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT s.id AS stage_id,COUNT(*) AS stage_total FROM todo_tasks t JOIN todo_stages s ON s.id=t.stage_id AND s.company_id=t.company_id LEFT JOIN contacts c ON c.id=t.contact_id WHERE ' . $clause . ' GROUP BY s.id');
        $count->execute($params); $stageCounts = []; $total = 0;
        foreach ($count->fetchAll() as $row) { $stageCounts[(string)(int)$row['stage_id']] = (int)$row['stage_total']; $total += (int)$row['stage_total']; }
        $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $list = $this->pdo->prepare('SELECT t.id,t.company_id,t.title,t.description,t.stage_id,t.stage_entered_at,s.stage_key,s.name AS stage_name,s.stage_type,s.folded_by_default,s.rotting_days,t.priority,t.tag,t.deadline,t.reminder,t.contact_id,c.name AS contact_name,t.source_model,t.source_record_id,t.source_reference,t.created_by,creator.name AS creator_name,t.completed_at,t.created_at,t.updated_at ' .
            'FROM todo_tasks t JOIN todo_stages s ON s.id=t.stage_id AND s.company_id=t.company_id LEFT JOIN contacts c ON c.id=t.contact_id JOIN users cu ON cu.id=t.created_by JOIN contacts creator ON creator.id=cu.contact_id WHERE ' . $clause . ' ORDER BY s.sequence,t.priority DESC,t.deadline IS NULL,t.deadline,t.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $list->bindValue(':limit', $limit, PDO::PARAM_INT); $list->bindValue(':offset', $offset, PDO::PARAM_INT); $list->execute();
        $records = $list->fetchAll();
        foreach ($records as &$record) $this->hydrateTask($record, $auth);
        unset($record);
        return ['records' => $records, 'total' => $total, 'stage_counts' => $stageCounts, 'limit' => $limit, 'offset' => $offset, 'company_id' => $companyId];
    }

    public function find(int $id, UserContext $user, Authorization $auth): ?array
    {
        if (!$auth->allows('todo.read')) throw new AccessDeniedException('You do not have permission to read To-dos.');
        $task = $this->taskQuery($id);
        if ($task && !$auth->allowsForCompany('todo.read', (int)$task['company_id'])) throw new AccessDeniedException('You do not have permission to read To-dos in this company.');
        if (!$task || !$auth->canAccessCompany((int)$task['company_id']) || !$this->visibleTo($task, $user, $auth)) return null;
        $this->hydrateTask($task, $auth);
        return $task;
    }

    public function create(array $input, UserContext $user, Authorization $auth, int $actorId, string $ip): array
    {
        if (!$auth->allows('todo.write')) throw new AccessDeniedException('You do not have permission to create To-dos.');
        $unknown = array_diff(array_keys($input), $this->taskFields());
        if ($unknown) throw new InvalidArgumentException('Unsupported To-do fields: ' . implode(', ', $unknown));
        $companyId = $this->companyId($input['company_id'] ?? $user->defaultCompanyId, $auth);
        if (!$auth->allowsForCompany('todo.write', $companyId)) throw new AccessDeniedException('You do not have permission to create To-dos in this company.');
        $this->ensureDefaultStages($companyId);
        $data = $this->normalizeTask($input, null, $companyId, $user, $auth);
        $this->pdo->beginTransaction();
        try {
            $this->insertTask($companyId, $data, $actorId);
            $id = (int)$this->pdo->lastInsertId();
            $this->replaceAssignees($id, $companyId, $data['assignee_ids'], $actorId);
            $this->audit($companyId, $actorId, $id, 'todo.task', 'todo_created', null, $data, $ip);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return $this->find($id, $user, $auth) ?? throw new InvalidArgumentException('To-do was saved but could not be reloaded.');
    }

    public function update(int $id, array $input, UserContext $user, Authorization $auth, int $actorId, string $ip): array
    {
        if (!$auth->allows('todo.write')) throw new AccessDeniedException('You do not have permission to update To-dos.');
        $unknown = array_diff(array_keys($input), $this->taskFields());
        if ($unknown) throw new InvalidArgumentException('Unsupported To-do fields: ' . implode(', ', $unknown));
        $existing = $this->taskQuery($id);
        if (!$existing || !$auth->canAccessCompany((int)$existing['company_id']) || !$this->visibleTo($existing, $user, $auth)) throw new InvalidArgumentException('To-do was not found or is outside your allowed records.');
        if (!$auth->allowsForCompany('todo.write', (int)$existing['company_id'])) throw new AccessDeniedException('You do not have permission to update To-dos in this company.');
        $existing['assignee_ids'] = $this->assigneeIds($id, (int)$existing['company_id']);
        if (isset($input['company_id']) && $this->positiveId($input['company_id'], 'Company') !== (int)$existing['company_id']) throw new InvalidArgumentException('A To-do cannot be moved to another company.');
        $data = $this->normalizeTask($input, $existing, (int)$existing['company_id'], $user, $auth);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM todo_tasks WHERE id=:id AND company_id=:company AND archived_at IS NULL FOR UPDATE');
            $lock->execute(['id' => $id, 'company' => $existing['company_id']]); $current = $lock->fetch();
            if (!$current || !$this->visibleTo($current, $user, $auth)) throw new InvalidArgumentException('To-do changed or is no longer available. Reload it before saving.');
            $before = $this->auditValues($existing);
            $this->pdo->prepare('UPDATE todo_tasks SET title=:title,description=:description,stage_entered_at=IF(stage_id<>:stage_check,UTC_TIMESTAMP(),stage_entered_at),stage_id=:stage,priority=:priority,tag=:tag,deadline=:deadline,reminder=:reminder,contact_id=:contact,source_model=:source_model,source_record_id=:source_id,source_reference=:source_reference,updated_by=:actor,completed_at=:completed_at WHERE id=:id AND company_id=:company')
                ->execute(['title'=>$data['title'],'description'=>$data['description'],'stage_check'=>$data['stage_id'],'stage'=>$data['stage_id'],'priority'=>$data['priority'],'tag'=>$data['tag'],'deadline'=>$data['deadline'],'reminder'=>$data['reminder']?1:0,'contact'=>$data['contact_id'],'source_model'=>$data['source_model'],'source_id'=>$data['source_record_id'],'source_reference'=>$data['source_reference'],'actor'=>$actorId,'completed_at'=>$data['stage_type']==='done' ? ($current['completed_at'] ?: gmdate('Y-m-d H:i:s')) : null,'id'=>$id,'company'=>$existing['company_id']]);
            $this->replaceAssignees($id, (int)$existing['company_id'], $data['assignee_ids'], $actorId);
            $this->audit((int)$existing['company_id'], $actorId, $id, 'todo.task', 'todo_updated', $before, $data, $ip);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return $this->find($id, $user, $auth) ?? throw new InvalidArgumentException('Updated To-do could not be reloaded.');
    }

    public function setPriority(int $id, mixed $rawPriority, UserContext $user, Authorization $auth, int $actorId, string $ip): array
    {
        if (!$auth->allows('todo.write')) throw new AccessDeniedException('You do not have permission to update To-dos.');
        $priority = filter_var($rawPriority, FILTER_VALIDATE_INT);
        if ($priority === false || $priority < 0 || $priority > 3) throw new InvalidArgumentException('Priority must be between 0 and 3.');
        $task = $this->find($id, $user, $auth);
        if (!$task) throw new InvalidArgumentException('To-do was not found or is outside your allowed records.');
        if (!$auth->allowsForCompany('todo.write', (int)$task['company_id'])) throw new AccessDeniedException('You do not have permission to update To-dos in this company.');
        $this->pdo->beginTransaction();
        try {
            $this->lockTask($id, (int)$task['company_id'], $user, $auth);
            $this->pdo->prepare('UPDATE todo_tasks SET priority=:priority,updated_by=:actor WHERE id=:id AND company_id=:company AND archived_at IS NULL')->execute(['priority'=>$priority,'actor'=>$actorId,'id'=>$id,'company'=>$task['company_id']]);
            $this->audit((int)$task['company_id'], $actorId, $id, 'todo.task', 'todo_priority_changed', ['priority'=>(int)$task['priority']], ['priority'=>$priority], $ip);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return $this->find($id, $user, $auth) ?? throw new InvalidArgumentException('Updated To-do could not be reloaded.');
    }

    public function complete(int $id, UserContext $user, Authorization $auth, int $actorId, string $ip): array
    {
        if (!$auth->allows('todo.write')) throw new AccessDeniedException('You do not have permission to update To-dos.');
        $task = $this->find($id, $user, $auth);
        if (!$task) throw new InvalidArgumentException('To-do was not found or is outside your allowed records.');
        if (!$auth->allowsForCompany('todo.write', (int)$task['company_id'])) throw new AccessDeniedException('You do not have permission to update To-dos in this company.');
        $this->pdo->beginTransaction();
        try {
            $this->lockTask($id, (int)$task['company_id'], $user, $auth);
            $stageKey = $task['stage_type'] === 'done' ? 'inbox' : 'done';
            $stage = $this->stageByKey((int)$task['company_id'], $stageKey);
            $completedAt = $stageKey === 'done' ? gmdate('Y-m-d H:i:s') : null;
            $this->pdo->prepare('UPDATE todo_tasks SET stage_entered_at=UTC_TIMESTAMP(),stage_id=:stage,completed_at=:completed,updated_by=:actor WHERE id=:id AND company_id=:company AND archived_at IS NULL')
                ->execute(['stage'=>$stage['id'],'completed'=>$completedAt,'actor'=>$actorId,'id'=>$id,'company'=>$task['company_id']]);
            $this->audit((int)$task['company_id'], $actorId, $id, 'todo.task', $stageKey === 'done' ? 'todo_completed' : 'todo_reopened', ['stage_key'=>$task['stage_key']], ['stage_key'=>$stageKey,'completed_at'=>$completedAt], $ip);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        return $this->find($id, $user, $auth) ?? throw new InvalidArgumentException('Updated To-do could not be reloaded.');
    }

    public function archive(int $id, UserContext $user, Authorization $auth, int $actorId, string $ip): void
    {
        if (!$auth->allows('todo.write')) throw new AccessDeniedException('You do not have permission to archive To-dos.');
        $task = $this->find($id, $user, $auth);
        if (!$task) throw new InvalidArgumentException('To-do was not found or is outside your allowed records.');
        if (!$auth->allowsForCompany('todo.write', (int)$task['company_id'])) throw new AccessDeniedException('You do not have permission to archive To-dos in this company.');
        if (!empty($task['has_source'])) throw new InvalidArgumentException('A source-linked To-do must be cancelled or unlinked before it can be archived.');
        $this->pdo->beginTransaction();
        try {
            $this->lockTask($id, (int)$task['company_id'], $user, $auth);
            $this->pdo->prepare('UPDATE todo_tasks SET archived_at=UTC_TIMESTAMP(),archived_by=:archiver,updated_by=:updater WHERE id=:id AND company_id=:company AND archived_at IS NULL')
                ->execute(['archiver'=>$actorId,'updater'=>$actorId,'id'=>$id,'company'=>$task['company_id']]);
            $this->audit((int)$task['company_id'], $actorId, $id, 'todo.task', 'todo_archived', $this->auditValues($task), ['archived_at'=>gmdate('Y-m-d H:i:s')], $ip);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    public function updateStage(int $id, array $input, Authorization $auth, int $actorId, string $ip): array
    {
        $unknown = array_diff(array_keys($input), ['name','rotting_days','folded_by_default']);
        if ($unknown) throw new InvalidArgumentException('Unsupported To-do stage fields: ' . implode(', ', $unknown));
        $query = $this->pdo->prepare('SELECT * FROM todo_stages WHERE id=:id AND active=1'); $query->execute(['id'=>$id]); $stage = $query->fetch();
        if (!$stage || !$auth->canAccessCompany((int)$stage['company_id'])) throw new InvalidArgumentException('To-do stage was not found or is outside your allowed companies.');
        if (!$auth->allowsForCompany('settings.write', (int)$stage['company_id'])) throw new AccessDeniedException('Only a Settings user for this company can configure To-do stages.');
        $name = trim((string)($input['name'] ?? $stage['name']));
        if ($name === '' || mb_strlen($name) > 50) throw new InvalidArgumentException('Stage name is required and must be 50 characters or fewer.');
        $rotting = filter_var($input['rotting_days'] ?? $stage['rotting_days'], FILTER_VALIDATE_INT);
        if ($rotting === false || $rotting < 0 || $rotting > 3650) throw new InvalidArgumentException('Rotting days must be between 0 and 3650.');
        $folded = array_key_exists('folded_by_default',$input) ? $this->boolean($input['folded_by_default'],'Folded by default') : (bool)$stage['folded_by_default'];
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM todo_stages WHERE id=:id AND company_id=:company AND active=1 FOR UPDATE'); $lock->execute(['id'=>$id,'company'=>$stage['company_id']]); $stage=$lock->fetch();
            if (!$stage) throw new InvalidArgumentException('To-do stage changed while it was being edited.');
            $this->pdo->prepare('UPDATE todo_stages SET name=:name,rotting_days=:rotting,folded_by_default=:folded,updated_by=:actor WHERE id=:id')->execute(['name'=>$name,'rotting'=>$rotting,'folded'=>$folded?1:0,'actor'=>$actorId,'id'=>$id]);
            $this->audit((int)$stage['company_id'],$actorId,$id,'todo.stage','todo_stage_updated',['name'=>$stage['name'],'rotting_days'=>(int)$stage['rotting_days'],'folded_by_default'=>(bool)$stage['folded_by_default']],['name'=>$name,'rotting_days'=>$rotting,'folded_by_default'=>$folded],$ip);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        $result=$this->pdo->prepare('SELECT id,company_id,stage_key,name,sequence,rotting_days,folded_by_default,stage_type,is_required FROM todo_stages WHERE id=:id');$result->execute(['id'=>$id]);$row=$result->fetch();
        foreach(['id','company_id','sequence','rotting_days','folded_by_default','is_required']as$key)$row[$key]=(int)$row[$key];
        return $row;
    }

    public function createStage(array $input, Authorization $auth, int $actorId, string $ip): array
    {
        $unknown = array_diff(array_keys($input), ['company_id','name','rotting_days','folded_by_default']);
        if ($unknown) throw new InvalidArgumentException('Unsupported To-do stage fields: ' . implode(', ', $unknown));
        $companyId = $this->companyId($input['company_id'] ?? null, $auth);
        if (!$auth->allowsForCompany('settings.write', $companyId)) throw new AccessDeniedException('Only a Settings user for this company can configure To-do stages.');
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) throw new InvalidArgumentException('Stage name is required and must be 50 characters or fewer.');
        $rotting = filter_var($input['rotting_days'] ?? 0, FILTER_VALIDATE_INT);
        if ($rotting === false || $rotting < 0 || $rotting > 3650) throw new InvalidArgumentException('Rotting days must be between 0 and 3650.');
        $folded = $this->boolean($input['folded_by_default'] ?? false, 'Folded by default');
        $this->ensureDefaultStages($companyId);
        $this->pdo->beginTransaction();
        try {
            $companyLock = $this->pdo->prepare('SELECT id FROM companies WHERE id=:company FOR UPDATE');
            $companyLock->execute(['company'=>$companyId]);
            $max = $this->pdo->prepare('SELECT COALESCE(MAX(sequence),0)+10 FROM todo_stages WHERE company_id=:company AND active=1');
            $max->execute(['company'=>$companyId]); $sequence = (int)$max->fetchColumn();
            $key = 'custom_' . bin2hex(random_bytes(8));
            $insert = $this->pdo->prepare("INSERT INTO todo_stages (company_id,stage_key,name,sequence,rotting_days,folded_by_default,stage_type,is_required,created_by,updated_by) VALUES (:company,:key,:name,:sequence,:rotting,:folded,'open',0,:creator,:updater)");
            $insert->execute(['company'=>$companyId,'key'=>$key,'name'=>$name,'sequence'=>$sequence,'rotting'=>$rotting,'folded'=>$folded?1:0,'creator'=>$actorId,'updater'=>$actorId]);
            $id = (int)$this->pdo->lastInsertId();
            $this->audit($companyId,$actorId,$id,'todo.stage','todo_stage_created',null,['name'=>$name,'sequence'=>$sequence,'rotting_days'=>$rotting,'folded_by_default'=>$folded],$ip);
            $this->pdo->commit();
        } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
        $result = $this->pdo->prepare('SELECT id,company_id,stage_key,name,sequence,rotting_days,folded_by_default,stage_type,is_required FROM todo_stages WHERE id=:id');
        $result->execute(['id'=>$id]); $row=$result->fetch();
        foreach(['id','company_id','sequence','rotting_days','folded_by_default','is_required'] as $field) $row[$field]=(int)$row[$field];
        return $row;
    }

    public function deleteStage(int $id, Authorization $auth, int $actorId, string $ip): void
    {
        $query=$this->pdo->prepare('SELECT * FROM todo_stages WHERE id=:id AND active=1');$query->execute(['id'=>$id]);$stage=$query->fetch();
        if(!$stage||!$auth->canAccessCompany((int)$stage['company_id']))throw new InvalidArgumentException('To-do stage was not found or is outside your allowed companies.');
        if(!$auth->allowsForCompany('settings.write',(int)$stage['company_id']))throw new AccessDeniedException('Only a Settings user for this company can configure To-do stages.');
        if((int)$stage['is_required']===1)throw new InvalidArgumentException('Inbox, Done and Cancelled are required To-do stages.');
        $inbox=$this->stageByKey((int)$stage['company_id'],'inbox');
        $this->pdo->beginTransaction();
        try{$lock=$this->pdo->prepare('SELECT id FROM todo_stages WHERE id=:id AND company_id=:company FOR UPDATE');$lock->execute(['id'=>$id,'company'=>$stage['company_id']]);if(!$lock->fetchColumn())throw new InvalidArgumentException('To-do stage changed while it was being deleted.');
            $this->pdo->prepare('UPDATE todo_tasks SET stage_entered_at=UTC_TIMESTAMP(),stage_id=:inbox,updated_by=:actor WHERE stage_id=:stage AND company_id=:company AND archived_at IS NULL')->execute(['inbox'=>$inbox['id'],'actor'=>$actorId,'stage'=>$id,'company'=>$stage['company_id']]);
            $this->pdo->prepare('UPDATE todo_stages SET active=0,updated_by=:actor WHERE id=:id')->execute(['actor'=>$actorId,'id'=>$id]);
            $this->audit((int)$stage['company_id'],$actorId,$id,'todo.stage','todo_stage_archived',['name'=>$stage['name']],['name'=>$stage['name'],'moved_to_stage'=>'inbox'],$ip);$this->pdo->commit();
        }catch(Throwable$error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    private function taskFields(): array { return ['company_id','title','description','stage_id','priority','tag','deadline','reminder','assignee_ids','contact_id','source_model','source_record_id']; }

    private function normalizeTask(array $input, ?array $existing, int $companyId, UserContext $user, Authorization $auth): array
    {
        $title=trim((string)($input['title']??$existing['title']??''));if($title===''||mb_strlen($title)>120)throw new InvalidArgumentException('To-do title is required and must be 120 characters or fewer.');
        $description=trim((string)($input['description']??$existing['description']??''));if(mb_strlen($description)>2000)throw new InvalidArgumentException('Description cannot exceed 2,000 characters.');
        $stageId=$this->positiveId($input['stage_id']??$existing['stage_id']??null,'Stage');$stage=$this->stage($stageId,$companyId);
        $priority=filter_var($input['priority']??$existing['priority']??0,FILTER_VALIDATE_INT);if($priority===false||$priority<0||$priority>3)throw new InvalidArgumentException('Priority must be between 0 and 3.');
        $tag=trim((string)($input['tag']??$existing['tag']??''));if(mb_strlen($tag)>40)throw new InvalidArgumentException('Tag cannot exceed 40 characters.');
        $deadline=$this->date(array_key_exists('deadline',$input)?$input['deadline']:($existing['deadline']??null));
        $reminder=$this->boolean($input['reminder']??$existing['reminder']??false,'Reminder');
        $contactWasChanged = array_key_exists('contact_id', $input);
        $contactRaw=$contactWasChanged ? $input['contact_id'] : ($existing['contact_id']??null);$contactId=$contactRaw===null||$contactRaw===''?null:$this->positiveId($contactRaw,'Contact');
        if($contactId!==null&&$contactWasChanged){if(!$auth->allowsForCompany('contacts.read',$companyId))throw new AccessDeniedException('You do not have permission to link a Contact in this company.');$this->assertContact($contactId,$companyId);}
        $sourceWasChanged = array_key_exists('source_model', $input) || array_key_exists('source_record_id', $input);
        $sourceModel=trim((string)(array_key_exists('source_model',$input)?($input['source_model']??''):($existing['source_model']??'')));$sourceRaw=array_key_exists('source_record_id',$input)?$input['source_record_id']:($existing['source_record_id']??null);$sourceId=$sourceRaw===null||$sourceRaw===''?null:$this->positiveId($sourceRaw,'Linked record');
        if($sourceModel===''&&$sourceId!==null)throw new InvalidArgumentException('Choose the source application for the linked record.');
        if($sourceModel!==''&&$sourceId===null)throw new InvalidArgumentException('Choose a record from the selected source application.');
        $sourceReference=$sourceWasChanged||$existing===null?null:($existing['source_reference']??null);
        if($sourceModel!==''&&($sourceWasChanged||$existing===null||$contactWasChanged)){
            if(!isset(self::SOURCE_MODELS[$sourceModel]))throw new InvalidArgumentException('Select a supported source application.');
            if(!$auth->allowsForCompany(self::SOURCE_MODELS[$sourceModel]['permission'],$companyId)){
                if($sourceWasChanged||$existing===null)throw new AccessDeniedException('You do not have permission to link records from this application.');
                throw new AccessDeniedException('You cannot change a Contact while its linked source is hidden by your access rights.');
            }
            $source=$this->sourceRecord($sourceModel,$sourceId,$companyId,$auth);$sourceReference=(string)$source['reference'];
            if($source['contact_id']!==null){$sourceContactId=(int)$source['contact_id'];if($contactId!==null&&$contactId!==$sourceContactId)throw new InvalidArgumentException('The linked source record and Contact must match.');$this->assertContact($sourceContactId,$companyId);$contactId=$sourceContactId;}
        }
        if($sourceModel==='contacts.contact'&&$sourceId!==null){if($contactId!==null&&$contactId!==$sourceId)throw new InvalidArgumentException('The linked Contact and source Contact must match.');$contactId=$sourceId;}
        $rawAssignees=$input['assignee_ids']??$existing['assignee_ids']??[];if(!is_array($rawAssignees))throw new InvalidArgumentException('Assignees must be a list of users.');$assignees=[];foreach($rawAssignees as$value){$id=$this->positiveId($value,'Assignee');if(!in_array($id,$assignees,true))$assignees[]=$id;}if(count($assignees)>5)throw new InvalidArgumentException('Assign a To-do to no more than five users.');foreach($assignees as$id)$this->assertCompanyUser($id,$companyId);
        return ['company_id'=>$companyId,'title'=>$title,'description'=>$description,'stage_id'=>$stageId,'stage_key'=>$stage['stage_key'],'stage_type'=>$stage['stage_type'],'priority'=>$priority,'tag'=>$tag,'deadline'=>$deadline,'reminder'=>$reminder,'contact_id'=>$contactId,'source_model'=>$sourceModel!==''?$sourceModel:null,'source_record_id'=>$sourceId,'source_reference'=>$sourceReference,'assignee_ids'=>$assignees];
    }

    private function insertTask(int $companyId,array $data,int $actorId):void{$this->pdo->prepare('INSERT INTO todo_tasks (company_id,title,description,stage_id,stage_entered_at,priority,tag,deadline,reminder,contact_id,source_model,source_record_id,source_reference,created_by,updated_by,completed_at) VALUES (:company,:title,:description,:stage,UTC_TIMESTAMP(),:priority,:tag,:deadline,:reminder,:contact,:source_model,:source_id,:source_reference,:actor,:updater,:completed)')->execute(['company'=>$companyId,'title'=>$data['title'],'description'=>$data['description'],'stage'=>$data['stage_id'],'priority'=>$data['priority'],'tag'=>$data['tag'],'deadline'=>$data['deadline'],'reminder'=>$data['reminder']?1:0,'contact'=>$data['contact_id'],'source_model'=>$data['source_model'],'source_id'=>$data['source_record_id'],'source_reference'=>$data['source_reference'],'actor'=>$actorId,'updater'=>$actorId,'completed'=>$data['stage_type']==='done'?gmdate('Y-m-d H:i:s'):null]);}

    private function replaceAssignees(int $taskId,int $companyId,array $assignees,int $actorId):void{$this->pdo->prepare('DELETE FROM todo_task_assignees WHERE task_id=:task AND company_id=:company')->execute(['task'=>$taskId,'company'=>$companyId]);$insert=$this->pdo->prepare('INSERT INTO todo_task_assignees (company_id,task_id,user_id,assigned_by) VALUES (:company,:task,:user,:actor)');foreach($assignees as$userId)$insert->execute(['company'=>$companyId,'task'=>$taskId,'user'=>$userId,'actor'=>$actorId]);}

    private function taskQuery(int $id):?array{$q=$this->pdo->prepare('SELECT t.id,t.company_id,t.title,t.description,t.stage_id,t.stage_entered_at,s.stage_key,s.name AS stage_name,s.stage_type,s.rotting_days,s.folded_by_default,t.priority,t.tag,t.deadline,t.reminder,t.contact_id,c.name AS contact_name,t.source_model,t.source_record_id,t.source_reference,t.created_by,t.completed_at,t.archived_at,t.created_at,t.updated_at FROM todo_tasks t JOIN todo_stages s ON s.id=t.stage_id AND s.company_id=t.company_id LEFT JOIN contacts c ON c.id=t.contact_id WHERE t.id=:id AND t.archived_at IS NULL LIMIT 1');$q->execute(['id'=>$id]);return$q->fetch()?:null;}

    private function assigneeIds(int $taskId,int $companyId):array{$q=$this->pdo->prepare('SELECT user_id FROM todo_task_assignees WHERE task_id=:task AND company_id=:company ORDER BY user_id');$q->execute(['task'=>$taskId,'company'=>$companyId]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}

    private function hydrateTask(array &$task,Authorization $auth):void{$id=(int)$task['id'];$task['id']=$id;foreach(['company_id','stage_id','priority','contact_id','source_record_id','created_by','rotting_days','folded_by_default']as$key)if(isset($task[$key])&&$task[$key]!==null)$task[$key]=(int)$task[$key];$task['reminder']=(bool)$task['reminder'];$task['has_source']=$task['source_model']!==null&&$task['source_record_id']!==null;$task['is_rotting']=false;if((int)($task['rotting_days']??0)>0&&($entered=strtotime((string)($task['stage_entered_at']??'').' UTC'))!==false)$task['is_rotting']=(time()-$entered)>=(int)$task['rotting_days']*86400;$task['assignee_ids']=[];$task['assignees']=[];$q=$this->pdo->prepare('SELECT a.user_id,c.name FROM todo_task_assignees a JOIN users u ON u.id=a.user_id JOIN contacts c ON c.id=u.contact_id WHERE a.task_id=:task AND a.company_id=:company ORDER BY c.name,a.user_id');$q->execute(['task'=>$id,'company'=>$task['company_id']]);foreach($q->fetchAll()as$row){$task['assignee_ids'][]=(int)$row['user_id'];$task['assignees'][]=['id'=>(int)$row['user_id'],'name'=>$row['name']];}if($task['contact_id']!==null&&!$auth->allowsForCompany('contacts.read',(int)$task['company_id'])){$task['contact_name']=null;$task['contact_id']=null;}if($task['source_model']!==null){$permission=self::SOURCE_MODELS[$task['source_model']]['permission']??null;if(!$permission||!$auth->allowsForCompany($permission,(int)$task['company_id'])){$task['source_model']=null;$task['source_record_id']=null;$task['source_reference']=null;}}}

    private function visibleTo(array $task,UserContext $user,Authorization $auth):bool{if($auth->allowsForCompany('settings.write',(int)$task['company_id']))return true;if((int)$task['created_by']===$user->id)return true;$q=$this->pdo->prepare('SELECT 1 FROM todo_task_assignees WHERE task_id=:task AND company_id=:company AND user_id=:user LIMIT 1');$q->execute(['task'=>$task['id'],'company'=>$task['company_id'],'user'=>$user->id]);return(bool)$q->fetchColumn();}
    private function lockTask(int $id,int $companyId,UserContext $user,Authorization $auth):array{$q=$this->pdo->prepare('SELECT * FROM todo_tasks WHERE id=:id AND company_id=:company AND archived_at IS NULL FOR UPDATE');$q->execute(['id'=>$id,'company'=>$companyId]);$task=$q->fetch();if(!$task||!$this->visibleTo($task,$user,$auth))throw new InvalidArgumentException('To-do changed or is no longer available.');return$task;}

    private function searchSources(int $companyId,string $model,string $query,Authorization $auth):array
    {
        if(!isset(self::SOURCE_MODELS[$model]))throw new InvalidArgumentException('Select a supported source application.');
        if(!$auth->allowsForCompany(self::SOURCE_MODELS[$model]['permission'],$companyId))throw new AccessDeniedException('You do not have permission to link records from this application in this company.');
        $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
        $spec=match($model){
            'contacts.contact'=>['SELECT id,CAST(id AS CHAR) AS reference,name AS label FROM contacts WHERE active=1 AND (company_id=:company OR company_id IS NULL) AND (name LIKE :q ESCAPE \'!\' OR email LIKE :q_email ESCAPE \'!\') ORDER BY name,id LIMIT 50',['q'=>$like,'q_email'=>$like]],
            'crm.lead'=>['SELECT id,CAST(id AS CHAR) AS reference,name AS label FROM crm_leads WHERE company_id=:company AND name LIKE :q ESCAPE \'!\' ORDER BY updated_at DESC,id DESC LIMIT 50',['q'=>$like]],
            'sales.order'=>['SELECT id,name AS reference,CONCAT(name,\' · \',state) AS label FROM sales_orders WHERE company_id=:company AND (name LIKE :q ESCAPE \'!\' OR client_order_ref LIKE :q_ref ESCAPE \'!\') ORDER BY order_date DESC,id DESC LIMIT 50',['q'=>$like,'q_ref'=>$like]],
            'purchase.order'=>['SELECT id,name AS reference,CONCAT(name,\' · \',state) AS label FROM purchase_orders WHERE company_id=:company AND (name LIKE :q ESCAPE \'!\' OR vendor_reference LIKE :q_ref ESCAPE \'!\') ORDER BY order_date DESC,id DESC LIMIT 50',['q'=>$like,'q_ref'=>$like]],
            'inventory.stock_move'=>['SELECT m.id,COALESCE(m.reference,CONCAT(\'Stock Move #\',m.id)) AS reference,CONCAT(COALESCE(m.reference,CONCAT(\'Stock Move #\',m.id)),\' · \',p.name,\' · \',m.state) AS label FROM stock_moves m JOIN products p ON p.id=m.product_id WHERE m.company_id=:company AND (m.reference LIKE :q ESCAPE \'!\' OR m.origin_reference LIKE :q_origin ESCAPE \'!\' OR p.name LIKE :q_product ESCAPE \'!\') ORDER BY m.created_at DESC,m.id DESC LIMIT 50',['q'=>$like,'q_origin'=>$like,'q_product'=>$like]],
            'invoicing.customer_invoice'=>['SELECT id,name AS reference,CONCAT(name,\' · \',state) AS label FROM customer_invoices WHERE company_id=:company AND name LIKE :q ESCAPE \'!\' ORDER BY invoice_date DESC,id DESC LIMIT 50',['q'=>$like]],
            'purchase.vendor_bill'=>['SELECT id,name AS reference,CONCAT(name,\' · \',state) AS label FROM vendor_bills WHERE company_id=:company AND name LIKE :q ESCAPE \'!\' ORDER BY bill_date DESC,id DESC LIMIT 50',['q'=>$like]],
            'accounting.entry'=>['SELECT id,name AS reference,CONCAT(name,\' · \',state) AS label FROM accounting_moves WHERE company_id=:company AND name LIKE :q ESCAPE \'!\' ORDER BY accounting_date DESC,id DESC LIMIT 50',['q'=>$like]],
            'contracts.contract'=>['SELECT id,COALESCE(reference,name) AS reference,CONCAT(name,\' · \',contract_type,\' · \',state) AS label FROM contracts WHERE company_id=:company AND (name LIKE :q ESCAPE \'!\' OR reference LIKE :q_reference ESCAPE \'!\') ORDER BY start_date DESC,id DESC LIMIT 50',['q'=>$like,'q_reference'=>$like]],
            'appointments.booking'=>['SELECT b.id,CAST(b.id AS CHAR) AS reference,CONCAT(t.name,\' · \',DATE_FORMAT(b.starts_at_utc,\'%Y-%m-%d %H:%i\'),\' · \',b.status) AS label FROM appointment_bookings b JOIN appointment_types t ON t.id=b.appointment_type_id AND t.company_id=b.company_id WHERE b.company_id=:company AND (t.name LIKE :q_type ESCAPE \'!\' OR b.booking_name LIKE :q_booking ESCAPE \'!\' OR b.email LIKE :q_email ESCAPE \'!\') ORDER BY b.starts_at_utc DESC,b.id DESC LIMIT 50',['q_type'=>$like,'q_booking'=>$like,'q_email'=>$like]],
        };
        [$sql,$searchParams]=$spec;$stmt=$this->pdo->prepare($sql);$stmt->execute(['company'=>$companyId]+$searchParams);return array_map(static fn(array $row):array=>['id'=>(int)$row['id'],'reference'=>(string)$row['reference'],'label'=>(string)$row['label']],$stmt->fetchAll());
    }

    private function sourceRecord(string $model,?int $id,int $companyId,Authorization $auth):array
    {
        if($id===null||!isset(self::SOURCE_MODELS[$model]))throw new InvalidArgumentException('Choose a supported linked record.');
        if(!$auth->allowsForCompany(self::SOURCE_MODELS[$model]['permission'],$companyId))throw new AccessDeniedException('You do not have permission to link records from this application in this company.');
        $spec=match($model){
            'contacts.contact'=>['SELECT name AS reference,id AS contact_id FROM contacts WHERE id=:id AND active=1 AND (company_id=:company OR company_id IS NULL)'],
            'crm.lead'=>['SELECT name AS reference,partner_contact_id AS contact_id FROM crm_leads WHERE id=:id AND company_id=:company'],
            'sales.order'=>['SELECT name AS reference,partner_contact_id AS contact_id FROM sales_orders WHERE id=:id AND company_id=:company'],
            'purchase.order'=>['SELECT name AS reference,partner_contact_id AS contact_id FROM purchase_orders WHERE id=:id AND company_id=:company'],
            'inventory.stock_move'=>['SELECT COALESCE(m.reference,CONCAT(\'Stock Move #\',m.id)) AS reference,CASE WHEN m.origin_model=\'sale_order\' THEN (SELECT so.partner_contact_id FROM sales_orders so WHERE so.id=m.origin_record_id AND so.company_id=m.company_id) WHEN m.origin_model=\'purchase_order\' THEN (SELECT po.partner_contact_id FROM purchase_orders po WHERE po.id=m.origin_record_id AND po.company_id=m.company_id) ELSE NULL END AS contact_id FROM stock_moves m WHERE m.id=:id AND m.company_id=:company'],
            'invoicing.customer_invoice'=>['SELECT name AS reference,partner_contact_id AS contact_id FROM customer_invoices WHERE id=:id AND company_id=:company'],
            'purchase.vendor_bill'=>['SELECT name AS reference,partner_contact_id AS contact_id FROM vendor_bills WHERE id=:id AND company_id=:company'],
            'accounting.entry'=>['SELECT m.name AS reference,CASE WHEN m.reference_model=\'customer_invoice\' THEN (SELECT i.partner_contact_id FROM customer_invoices i WHERE i.id=m.reference_id AND i.company_id=m.company_id) WHEN m.reference_model=\'vendor_bill\' THEN (SELECT b.partner_contact_id FROM vendor_bills b WHERE b.id=m.reference_id AND b.company_id=m.company_id) WHEN m.reference_model=\'vendor_credit_note\' THEN (SELECT b.partner_contact_id FROM vendor_credit_notes cn JOIN vendor_bills b ON b.id=cn.vendor_bill_id AND b.company_id=cn.company_id WHERE cn.id=m.reference_id AND cn.company_id=m.company_id) WHEN m.reference_model=\'vendor_credit_note_refund\' THEN (SELECT b.partner_contact_id FROM vendor_credit_note_refunds r JOIN vendor_credit_notes cn ON cn.id=r.credit_note_id AND cn.company_id=r.company_id JOIN vendor_bills b ON b.id=cn.vendor_bill_id AND b.company_id=cn.company_id WHERE r.id=m.reference_id AND r.company_id=m.company_id) WHEN m.reference_model=\'vendor_credit_note_refund_match\' THEN (SELECT b.partner_contact_id FROM vendor_credit_note_refund_matches rm JOIN vendor_credit_note_refunds r ON r.id=rm.refund_id AND r.company_id=rm.company_id JOIN vendor_credit_notes cn ON cn.id=r.credit_note_id AND cn.company_id=r.company_id JOIN vendor_bills b ON b.id=cn.vendor_bill_id AND b.company_id=cn.company_id WHERE rm.id=m.reference_id AND rm.company_id=m.company_id) ELSE NULL END AS contact_id FROM accounting_moves m WHERE m.id=:id AND m.company_id=:company'],
            'contracts.contract'=>['SELECT name AS reference,partner_contact_id AS contact_id FROM contracts WHERE id=:id AND company_id=:company'],
            'appointments.booking'=>['SELECT COALESCE(NULLIF(b.booking_name,\'\'),t.name) AS reference,b.contact_id FROM appointment_bookings b JOIN appointment_types t ON t.id=b.appointment_type_id AND t.company_id=b.company_id WHERE b.id=:id AND b.company_id=:company'],
        };
        $q=$this->pdo->prepare($spec[0]);$q->execute(['id'=>$id,'company'=>$companyId]);$record=$q->fetch();if(!$record)throw new InvalidArgumentException('Linked source record was not found in this company or is outside your application access.');return['reference'=>(string)$record['reference'],'contact_id'=>$record['contact_id']===null?null:(int)$record['contact_id']];
    }

    private function assertContact(int $contactId,int $companyId):void{$q=$this->pdo->prepare('SELECT 1 FROM contacts WHERE id=:id AND active=1 AND (company_id=:company OR company_id IS NULL)');$q->execute(['id'=>$contactId,'company'=>$companyId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Contact must be active and shared with or assigned to this company.');}
    private function assertCompanyUser(int $userId,int $companyId):void{$q=$this->pdo->prepare("SELECT 1 FROM users u JOIN user_company_access a ON a.user_id=u.id WHERE u.id=:user AND a.company_id=:company AND u.status IN ('active','confirmed') LIMIT 1");$q->execute(['user'=>$userId,'company'=>$companyId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Assignee must be an active user with access to this company.');}
    private function companyId(mixed $raw,Authorization $auth):int{$id=$this->positiveId($raw,'Company');if(!$auth->canAccessCompany($id))throw new InvalidArgumentException('Company is outside your allowed company scope.');$q=$this->pdo->prepare('SELECT 1 FROM companies WHERE id=:id AND active=1');$q->execute(['id'=>$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Company was not found or is archived.');return$id;}
    private function stage(int $id,int $companyId):array{$q=$this->pdo->prepare('SELECT id,company_id,stage_key,name,stage_type FROM todo_stages WHERE id=:id AND company_id=:company AND active=1');$q->execute(['id'=>$id,'company'=>$companyId]);$stage=$q->fetch();if(!$stage)throw new InvalidArgumentException('Choose an active To-do stage for this company.');return$stage;}
    private function stageByKey(int $companyId,string $key):array{$q=$this->pdo->prepare('SELECT id,stage_key,name,stage_type FROM todo_stages WHERE company_id=:company AND stage_key=:key AND active=1');$q->execute(['company'=>$companyId,'key'=>$key]);$stage=$q->fetch();if(!$stage)throw new InvalidArgumentException('The required '.$key.' stage is unavailable.');return$stage;}
    private function ensureDefaultStages(int $companyId):void{$insert=$this->pdo->prepare('INSERT IGNORE INTO todo_stages (company_id,stage_key,name,sequence,rotting_days,folded_by_default,stage_type,is_required) VALUES (:company,:key,:name,:sequence,:rotting,:folded,:type,:required)');foreach(self::DEFAULT_STAGES as[$key,$name,$sequence,$rotting,$folded,$type,$required])$insert->execute(['company'=>$companyId,'key'=>$key,'name'=>$name,'sequence'=>$sequence,'rotting'=>$rotting,'folded'=>$folded,'type'=>$type,'required'=>$required]);}
    private function date(mixed $raw):?string{if($raw===null||$raw==='')return null;if(!is_string($raw)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$raw))throw new InvalidArgumentException('Deadline must use YYYY-MM-DD.');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw);if(!$date||$date->format('Y-m-d')!==$raw)throw new InvalidArgumentException('Deadline is not a valid date.');return$raw;}
    private function boolean(mixed $raw,string $label):bool{if(is_bool($raw))return$raw;if($raw===0||$raw==='0'||$raw===null||$raw==='')return false;if($raw===1||$raw==='1'||$raw==='on')return true;throw new InvalidArgumentException($label.' must be true or false.');}
    private function positiveId(mixed $raw,string $label):int{$id=filter_var($raw,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException($label.' must be a positive ID.');return$id;}
    private function auditValues(array $task):array{return array_intersect_key($task,array_flip(['title','stage_id','stage_key','priority','tag','deadline','reminder','contact_id','source_model','source_record_id','source_reference','assignee_ids']));}
    private function audit(int $companyId,int $actorId,int $recordId,string $model,string $event,?array $old,array $new,string $remoteAddress):void{$ip=filter_var($remoteAddress,FILTER_VALIDATE_IP)?inet_pton($remoteAddress):null;$q=$this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (:company,:user,:event,:model,:record,:old_values,:new_values,:ip)');$q->bindValue(':company',$companyId,PDO::PARAM_INT);$q->bindValue(':user',$actorId,PDO::PARAM_INT);$q->bindValue(':event',$event);$q->bindValue(':model',$model);$q->bindValue(':record',$recordId,PDO::PARAM_INT);$q->bindValue(':old_values',$old===null?null:json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$old===null?PDO::PARAM_NULL:PDO::PARAM_STR);$q->bindValue(':new_values',json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$q->bindValue(':ip',$ip,$ip===null?PDO::PARAM_NULL:PDO::PARAM_LOB);$q->execute();}
}
