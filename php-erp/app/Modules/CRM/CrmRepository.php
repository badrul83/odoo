<?php
declare(strict_types=1);

namespace Erp\Modules\CRM;

use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class CrmRepository
{
    private const LEAD_FIELDS = [
        'company_id', 'partner_contact_id', 'name', 'contact_type', 'email', 'phone', 'mobile', 'website',
        'record_type', 'stage_id', 'team_id', 'salesperson_user_id', 'expected_revenue', 'currency_code',
        'probability', 'priority', 'expected_close_date', 'source', 'medium', 'campaign', 'description',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function options(UserContext $user, Authorization $authorization, mixed $rawCompanyId): array
    {
        $companyId = $this->resolveCompany($user, $authorization, $rawCompanyId);
        $this->ensureDefaultPipeline($companyId);

        $stages = $this->pdo->prepare(
            'SELECT id, name, sequence, probability, is_won FROM crm_stages ' .
            'WHERE company_id = :company_id AND active = 1 ORDER BY sequence, id'
        );
        $stages->execute(['company_id' => $companyId]);

        $teams = $this->pdo->prepare(
            'SELECT id, name, leader_user_id, email_alias FROM crm_teams ' .
            'WHERE company_id = :company_id AND active = 1 ORDER BY name, id'
        );
        $teams->execute(['company_id' => $companyId]);

        $contacts = $this->pdo->prepare(
            'SELECT id, name, contact_type, email, phone, customer, supplier FROM contacts ' .
            'WHERE active = 1 AND (company_id IS NULL OR company_id = :company_id) ORDER BY name, id LIMIT 500'
        );
        $contacts->execute(['company_id' => $companyId]);

        if ($user->superuser) {
            $salespeople = $this->pdo->query(
                "SELECT u.id, c.name FROM users u JOIN contacts c ON c.id = u.contact_id " .
                "WHERE u.status IN ('confirmed','active') ORDER BY c.name, u.id"
            )->fetchAll();
        } else {
            $salespeopleQuery = $this->pdo->prepare(
                "SELECT u.id, c.name FROM users u JOIN contacts c ON c.id = u.contact_id " .
                "JOIN user_company_access uca ON uca.user_id = u.id AND uca.company_id = :company_id " .
                "WHERE u.status IN ('confirmed','active') ORDER BY c.name, u.id"
            );
            $salespeopleQuery->execute(['company_id' => $companyId]);
            $salespeople = $salespeopleQuery->fetchAll();
        }

        $currencies = $this->pdo->query('SELECT currency_code, name, symbol FROM currencies WHERE active = 1 ORDER BY name')->fetchAll();
        if ($user->superuser) {
            $companies = $this->pdo->query('SELECT id, name, currency_code FROM companies WHERE active = 1 ORDER BY name, id')->fetchAll();
        } else {
            $companyQuery = $this->pdo->prepare('SELECT c.id, c.name, c.currency_code FROM companies c JOIN user_company_access a ON a.company_id=c.id WHERE a.user_id=:user_id AND c.active=1 ORDER BY c.name, c.id');
            $companyQuery->execute(['user_id' => $user->id]);
            $companies = $companyQuery->fetchAll();
        }
        return [
            'company_id' => $companyId,
            'companies' => $companies,
            'stages' => $stages->fetchAll(),
            'teams' => $teams->fetchAll(),
            'contacts' => $contacts->fetchAll(),
            'salespeople' => $salespeople,
            'currencies' => $currencies,
            'record_types' => [ ['value' => 'lead', 'name' => 'Lead'], ['value' => 'opportunity', 'name' => 'Opportunity'] ],
            'contact_types' => [ ['value' => 'person', 'name' => 'Individual'], ['value' => 'company', 'name' => 'Company'] ],
            'priorities' => [ ['value' => 0, 'name' => 'Normal'], ['value' => 1, 'name' => 'Low'], ['value' => 2, 'name' => 'High'], ['value' => 3, 'name' => 'Very High'] ],
        ];
    }

    public function configuration(UserContext $actor, Authorization $authorization, mixed $rawCompanyId): array
    {
        $companyId = $this->resolveCompany($actor, $authorization, $rawCompanyId);
        $this->requireSettingsCompany($authorization, $companyId);
        $companyQuery = $this->pdo->prepare('SELECT id,name,currency_code FROM companies WHERE id=:company AND active=1');
        $companyQuery->execute(['company' => $companyId]);
        $company = $companyQuery->fetch();
        if (!$company) throw new InvalidArgumentException('The selected company is inactive or does not exist.');
        $stages = $this->pdo->prepare(
            'SELECT s.id,s.name,s.sequence,s.probability,s.is_won,s.active,COUNT(l.id) AS linked_lead_count ' .
            'FROM crm_stages s LEFT JOIN crm_leads l ON l.stage_id=s.id AND l.company_id=s.company_id ' .
            'WHERE s.company_id=:company GROUP BY s.id ORDER BY s.sequence,s.id'
        );
        $stages->execute(['company' => $companyId]);
        $teams = $this->pdo->prepare(
            'SELECT t.id,t.name,t.leader_user_id,t.email_alias,t.active,leader.name AS leader_name,COUNT(l.id) AS linked_lead_count ' .
            'FROM crm_teams t LEFT JOIN users u ON u.id=t.leader_user_id LEFT JOIN contacts leader ON leader.id=u.contact_id ' .
            'LEFT JOIN crm_leads l ON l.team_id=t.id AND l.company_id=t.company_id ' .
            'WHERE t.company_id=:company GROUP BY t.id ORDER BY t.name,t.id'
        );
        $teams->execute(['company' => $companyId]);
        $salespeople = $this->pdo->prepare(
            "SELECT u.id,c.name,u.login FROM users u JOIN contacts c ON c.id=u.contact_id " .
            "JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company " .
            "WHERE u.status IN ('confirmed','active') ORDER BY c.name,u.id"
        );
        $salespeople->execute(['company' => $companyId]);
        $records = $stages->fetchAll();
        foreach ($records as &$stage) {
            $stage['id'] = (int)$stage['id'];
            $stage['sequence'] = (int)$stage['sequence'];
            $stage['probability'] = (string)$stage['probability'];
            $stage['is_won'] = (bool)$stage['is_won'];
            $stage['active'] = (bool)$stage['active'];
            $stage['linked_lead_count'] = (int)$stage['linked_lead_count'];
        }
        unset($stage);
        $teamRecords = $teams->fetchAll();
        foreach ($teamRecords as &$team) {
            $team['id'] = (int)$team['id'];
            $team['leader_user_id'] = $team['leader_user_id'] === null ? null : (int)$team['leader_user_id'];
            $team['active'] = (bool)$team['active'];
            $team['linked_lead_count'] = (int)$team['linked_lead_count'];
        }
        unset($team);
        $users = array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'name' => $row['name'], 'login' => $row['login']], $salespeople->fetchAll());
        $company['id'] = (int)$company['id'];
        return ['company' => $company, 'stages' => $records, 'teams' => $teamRecords, 'salespeople' => $users];
    }

    public function saveStage(?int $id, array $input, UserContext $actor, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id','name','sequence','probability','is_won','active']);
        if ($unknown) throw new InvalidArgumentException('Unsupported CRM stage fields: ' . implode(', ', $unknown));
        $companyId = $this->positiveId($input['company_id'] ?? null, 'Company');
        $this->requireSettingsCompany($authorization, $companyId);
        $name = $this->text('Stage name', $input['name'] ?? null, 120);
        if ($name === null) throw new InvalidArgumentException('Stage name is required.');
        $sequence = filter_var($input['sequence'] ?? null, FILTER_VALIDATE_INT);
        if ($sequence === false || $sequence < 0 || $sequence > 65535) throw new InvalidArgumentException('Stage sequence must be between 0 and 65,535.');
        $probability = $this->decimal($input['probability'] ?? null, 'Stage probability', 3, 2, true);
        if ((float)$probability > 100) throw new InvalidArgumentException('Stage probability must be between 0 and 100.');
        $isWon = filter_var($input['is_won'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $active = filter_var($input['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isWon === null || $active === null) throw new InvalidArgumentException('Won Stage and Active must each be true or false.');
        if ($isWon && (float)$probability !== 100.0) throw new InvalidArgumentException('A Won stage must have a 100% probability.');

        $this->pdo->beginTransaction();
        try {
            $companyLock = $this->pdo->prepare('SELECT id FROM companies WHERE id=:company AND active=1 FOR UPDATE');
            $companyLock->execute(['company' => $companyId]);
            if (!$companyLock->fetchColumn()) throw new InvalidArgumentException('The selected company is inactive or does not exist.');
            $existing = null;
            if ($id !== null) {
                $lock = $this->pdo->prepare('SELECT id,name,sequence,probability,is_won,active FROM crm_stages WHERE id=:id AND company_id=:company FOR UPDATE');
                $lock->execute(['id' => $id, 'company' => $companyId]);
                $existing = $lock->fetch() ?: null;
                if (!$existing) throw new InvalidArgumentException('CRM stage was not found for this company.');
                $linked = $this->pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE company_id=:company AND stage_id=:id');
                $linked->execute(['company' => $companyId, 'id' => $id]);
                $linkedCount = (int)$linked->fetchColumn();
                if ($linkedCount > 0 && (bool)$existing['is_won'] !== $isWon) throw new InvalidArgumentException('Move linked leads to another stage before changing whether this stage is Won.');
                if ($linkedCount > 0 && (bool)$existing['active'] && !$active) throw new InvalidArgumentException('Move linked leads to another stage before archiving this stage.');
                $this->pdo->prepare('UPDATE crm_stages SET name=:name,sequence=:sequence,probability=:probability,is_won=:is_won,active=:active WHERE id=:id AND company_id=:company')
                    ->execute(['name' => $name, 'sequence' => $sequence, 'probability' => $probability, 'is_won' => (int)$isWon, 'active' => (int)$active, 'id' => $id, 'company' => $companyId]);
                $savedId = $id;
                $event = 'updated';
            } else {
                $this->pdo->prepare('INSERT INTO crm_stages (company_id,name,sequence,probability,is_won,active) VALUES (:company,:name,:sequence,:probability,:is_won,:active)')
                    ->execute(['company' => $companyId, 'name' => $name, 'sequence' => $sequence, 'probability' => $probability, 'is_won' => (int)$isWon, 'active' => (int)$active]);
                $savedId = (int)$this->pdo->lastInsertId();
                $event = 'created';
            }
            $available = $this->pdo->prepare('SELECT SUM(active=1 AND is_won=0) AS open_stages,SUM(active=1 AND is_won=1) AS won_stages FROM crm_stages WHERE company_id=:company');
            $available->execute(['company' => $companyId]);
            $counts = $available->fetch();
            if ((int)$counts['open_stages'] < 1 || (int)$counts['won_stages'] < 1) throw new InvalidArgumentException('Keep at least one active non-Won stage and one active Won stage in the pipeline.');
            $this->audit($companyId, $actorId, $savedId, 'crm.stage', $event, $existing, ['company_id' => $companyId, 'name' => $name, 'sequence' => $sequence, 'probability' => $probability, 'is_won' => $isWon, 'active' => $active], $remoteAddress);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return ['id' => $savedId, 'company_id' => $companyId];
    }

    public function saveTeam(?int $id, array $input, UserContext $actor, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['company_id','name','leader_user_id','email_alias','active']);
        if ($unknown) throw new InvalidArgumentException('Unsupported CRM sales-team fields: ' . implode(', ', $unknown));
        $companyId = $this->positiveId($input['company_id'] ?? null, 'Company');
        $this->requireSettingsCompany($authorization, $companyId);
        $name = $this->text('Sales team name', $input['name'] ?? null, 120);
        if ($name === null) throw new InvalidArgumentException('Sales team name is required.');
        $leaderId = $this->nullableId($input['leader_user_id'] ?? null, 'Team leader');
        if ($leaderId !== null) $this->assertUserCompany($leaderId, $companyId, $actor, $authorization);
        $emailAlias = $this->text('Email alias', $input['email_alias'] ?? null, 190);
        if ($emailAlias !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $emailAlias) !== 1) throw new InvalidArgumentException('Email alias must be a local mailbox name without spaces or an @domain.');
        $active = filter_var($input['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) throw new InvalidArgumentException('Active must be true or false.');

        $this->pdo->beginTransaction();
        try {
            $companyLock = $this->pdo->prepare('SELECT id FROM companies WHERE id=:company AND active=1 FOR UPDATE');
            $companyLock->execute(['company' => $companyId]);
            if (!$companyLock->fetchColumn()) throw new InvalidArgumentException('The selected company is inactive or does not exist.');
            $existing = null;
            if ($id !== null) {
                $lock = $this->pdo->prepare('SELECT id,name,leader_user_id,email_alias,active FROM crm_teams WHERE id=:id AND company_id=:company FOR UPDATE');
                $lock->execute(['id' => $id, 'company' => $companyId]);
                $existing = $lock->fetch() ?: null;
                if (!$existing) throw new InvalidArgumentException('CRM sales team was not found for this company.');
                if ((bool)$existing['active'] && !$active) {
                    $linked = $this->pdo->prepare('SELECT COUNT(*) FROM crm_leads WHERE company_id=:company AND team_id=:id');
                    $linked->execute(['company' => $companyId, 'id' => $id]);
                    if ((int)$linked->fetchColumn() > 0) throw new InvalidArgumentException('Move linked leads to another sales team before archiving this team.');
                }
                $this->pdo->prepare('UPDATE crm_teams SET name=:name,leader_user_id=:leader,email_alias=:alias,active=:active WHERE id=:id AND company_id=:company')
                    ->execute(['name' => $name, 'leader' => $leaderId, 'alias' => $emailAlias, 'active' => (int)$active, 'id' => $id, 'company' => $companyId]);
                $savedId = $id;
                $event = 'updated';
            } else {
                $this->pdo->prepare('INSERT INTO crm_teams (company_id,name,leader_user_id,email_alias,active) VALUES (:company,:name,:leader,:alias,:active)')
                    ->execute(['company' => $companyId, 'name' => $name, 'leader' => $leaderId, 'alias' => $emailAlias, 'active' => (int)$active]);
                $savedId = (int)$this->pdo->lastInsertId();
                $event = 'created';
            }
            $this->audit($companyId, $actorId, $savedId, 'crm.team', $event, $existing, ['company_id' => $companyId, 'name' => $name, 'leader_user_id' => $leaderId, 'email_alias' => $emailAlias, 'active' => $active], $remoteAddress);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return ['id' => $savedId, 'company_id' => $companyId];
    }

    public function search(UserContext $user, Authorization $authorization, array $filters): array
    {
        $companyId = $this->resolveCompany($user, $authorization, $filters['company_id'] ?? null);
        $limit = max(1, min(200, (int)($filters['limit'] ?? 100)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $where = ['l.company_id = :company_id'];
        $params = ['company_id' => $companyId];
        $type = (string)($filters['type'] ?? 'all');
        if (!in_array($type, ['all', 'lead', 'opportunity'], true)) throw new InvalidArgumentException('CRM type filter is invalid.');
        if ($type !== 'all') { $where[] = 'l.record_type = :record_type'; $params['record_type'] = $type; }
        $status = (string)($filters['state'] ?? 'open');
        if (!in_array($status, ['all', 'open', 'won', 'lost'], true)) throw new InvalidArgumentException('CRM status filter is invalid.');
        if ($status !== 'all') { $where[] = 'l.state = :state'; $params['state'] = $status; }
        if (!empty($filters['stage_id'])) {
            $where[] = 'l.stage_id = :stage_id';
            $params['stage_id'] = $this->positiveId($filters['stage_id'], 'Stage');
        }
        if (!empty($filters['partner_contact_id'])) {
            $where[] = 'l.partner_contact_id = :partner_contact_id';
            $params['partner_contact_id'] = $this->positiveId($filters['partner_contact_id'], 'Contact');
        }
        $queryText = trim((string)($filters['q'] ?? ''));
        if (mb_strlen($queryText) > 190) throw new InvalidArgumentException('CRM search text must be 190 characters or fewer.');
        if ($queryText !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $queryText) . '%';
            $where[] = '(l.name LIKE :q_name ESCAPE \'!\' OR l.email LIKE :q_email ESCAPE \'!\' OR l.phone LIKE :q_phone ESCAPE \'!\' OR l.campaign LIKE :q_campaign ESCAPE \'!\' OR l.source LIKE :q_source ESCAPE \'!\' OR l.medium LIKE :q_medium ESCAPE \'!\' OR l.partner_contact_id IN (SELECT c.id FROM contacts c WHERE c.name LIKE :q_contact ESCAPE \'!\'))';
            $params += ['q_name' => $like, 'q_email' => $like, 'q_phone' => $like, 'q_campaign' => $like, 'q_source' => $like, 'q_medium' => $like, 'q_contact' => $like];
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM crm_leads l WHERE ' . $whereSql);
        $count->execute($params);
        $query = $this->pdo->prepare(
            'SELECT l.id, l.company_id, l.partner_contact_id, p.name AS partner_name, l.name, l.record_type, ' .
            'l.email, l.phone, l.expected_revenue, l.currency_code, l.probability, l.priority, l.expected_close_date, ' .
            'l.stage_id, s.name AS stage_name, s.is_won, l.team_id, t.name AS team_name, l.salesperson_user_id, ' .
            'owner.name AS salesperson_name, l.state, l.lost_reason, l.updated_at ' .
            'FROM crm_leads l JOIN crm_stages s ON s.id = l.stage_id ' .
            'LEFT JOIN contacts p ON p.id = l.partner_contact_id LEFT JOIN crm_teams t ON t.id = l.team_id ' .
            'LEFT JOIN users u ON u.id = l.salesperson_user_id LEFT JOIN contacts owner ON owner.id = u.contact_id ' .
            'WHERE ' . $whereSql . ' ORDER BY FIELD(l.state, \'open\',\'won\',\'lost\'), l.priority DESC, l.updated_at DESC, l.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) $query->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        return ['records' => $query->fetchAll(), 'total' => (int)$count->fetchColumn(), 'limit' => $limit, 'offset' => $offset, 'company_id' => $companyId];
    }

    public function find(int $id, UserContext $user, Authorization $authorization): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT l.*, p.name AS partner_name, s.name AS stage_name, s.is_won, t.name AS team_name, ' .
            'owner.name AS salesperson_name FROM crm_leads l JOIN crm_stages s ON s.id = l.stage_id ' .
            'LEFT JOIN contacts p ON p.id = l.partner_contact_id LEFT JOIN crm_teams t ON t.id = l.team_id ' .
            'LEFT JOIN users u ON u.id = l.salesperson_user_id LEFT JOIN contacts owner ON owner.id = u.contact_id ' .
            'WHERE l.id = :id LIMIT 1'
        );
        $query->execute(['id' => $id]);
        $lead = $query->fetch();
        if (!$lead || !$authorization->canAccessCompany((int)$lead['company_id'])) return null;
        $activities = $this->pdo->prepare(
            'SELECT a.id, a.activity_type, a.summary, a.due_at, a.assigned_user_id, assigned.name AS assigned_name, ' .
            'a.state, a.completed_at, a.created_by, creator.name AS creator_name, a.created_at ' .
            'FROM crm_activities a LEFT JOIN users au ON au.id = a.assigned_user_id LEFT JOIN contacts assigned ON assigned.id = au.contact_id ' .
            'JOIN users cu ON cu.id = a.created_by JOIN contacts creator ON creator.id = cu.contact_id ' .
            'WHERE a.company_id = :company_id AND a.lead_id = :lead_id ORDER BY a.due_at IS NULL, a.due_at, a.id DESC'
        );
        $activities->execute(['company_id' => (int)$lead['company_id'], 'lead_id' => $id]);
        $lead['activities'] = $activities->fetchAll();
        return $lead;
    }

    public function save(?int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), self::LEAD_FIELDS);
        if ($unknown) throw new InvalidArgumentException('Unsupported CRM fields: ' . implode(', ', $unknown));
        $existing = $id === null ? null : $this->find($id, $user, $authorization);
        if ($id !== null && !$existing) throw new InvalidArgumentException('CRM record was not found or is outside the allowed companies.');
        if ($existing && $existing['state'] !== 'open') throw new InvalidArgumentException('Won or lost CRM records cannot be edited. Reopen workflow is not available yet.');
        $companyId = $existing ? (int)$existing['company_id'] : $this->resolveCompany($user, $authorization, $input['company_id'] ?? null);
        if (isset($input['company_id']) && $this->positiveId($input['company_id'], 'Company') !== $companyId) throw new InvalidArgumentException('A CRM record cannot be moved to another company after creation.');
        $this->ensureDefaultPipeline($companyId);

        $values = [];
        foreach (['name' => 190, 'email' => 190, 'phone' => 80, 'mobile' => 80, 'website' => 255, 'source' => 120, 'medium' => 120, 'campaign' => 190, 'description' => 65535] as $field => $length) {
            $value = array_key_exists($field, $input) ? $input[$field] : ($existing[$field] ?? null);
            $values[$field] = $this->text($field, $value, $length);
        }
        if ($values['name'] === null) throw new InvalidArgumentException('Opportunity or lead name is required.');
        if ($values['email'] !== null && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Enter a valid email address.');
        if ($values['website'] !== null && filter_var($values['website'], FILTER_VALIDATE_URL) === false) throw new InvalidArgumentException('Website must be a complete URL.');

        $recordType = (string)($input['record_type'] ?? $existing['record_type'] ?? 'lead');
        if (!in_array($recordType, ['lead', 'opportunity'], true)) throw new InvalidArgumentException('Record type must be Lead or Opportunity.');
        if ($existing && $recordType !== $existing['record_type']) throw new InvalidArgumentException('Use Convert to Opportunity to change a lead into an opportunity.');
        $contactType = (string)($input['contact_type'] ?? $existing['contact_type'] ?? 'person');
        if (!in_array($contactType, ['person', 'company'], true)) throw new InvalidArgumentException('Contact type must be Individual or Company.');
        $partnerId = $this->nullableId($input['partner_contact_id'] ?? $existing['partner_contact_id'] ?? null, 'Contact');
        if ($partnerId !== null) $this->assertContact($partnerId, $companyId);
        $stageId = isset($input['stage_id']) ? $this->positiveId($input['stage_id'], 'Stage') : (int)($existing['stage_id'] ?? $this->defaultStageId($companyId));
        $stage = $this->stage($stageId, $companyId);
        if (!$stage) throw new InvalidArgumentException('Choose an active CRM stage for this company.');
        $teamId = $this->nullableId($input['team_id'] ?? $existing['team_id'] ?? null, 'Sales team');
        if ($teamId !== null) $this->assertTeam($teamId, $companyId);
        $salespersonId = $this->nullableId($input['salesperson_user_id'] ?? $existing['salesperson_user_id'] ?? $user->id, 'Salesperson');
        if ($salespersonId !== null) $this->assertUserCompany($salespersonId, $companyId, $user, $authorization);
        $currency = strtoupper((string)($input['currency_code'] ?? $existing['currency_code'] ?? 'BDT'));
        $currencyCheck = $this->pdo->prepare('SELECT 1 FROM currencies WHERE currency_code = :code AND active = 1');
        $currencyCheck->execute(['code' => $currency]);
        if (!$currencyCheck->fetchColumn()) throw new InvalidArgumentException('Choose an active currency.');
        $revenue = $this->decimal($input['expected_revenue'] ?? $existing['expected_revenue'] ?? '0', 'Expected revenue', 14, 6, false);
        $probability = array_key_exists('probability', $input)
            ? $this->decimal($input['probability'], 'Probability', 3, 2, true)
            : (string)($existing && (int)$existing['stage_id'] === $stageId ? $existing['probability'] : $stage['probability']);
        if ((float)$probability > 100) throw new InvalidArgumentException('Probability must be between 0 and 100.');
        $priorityRaw = $input['priority'] ?? $existing['priority'] ?? 0;
        $priority = filter_var($priorityRaw, FILTER_VALIDATE_INT);
        if ($priority === false || $priority < 0 || $priority > 3) throw new InvalidArgumentException('Priority must be from 0 to 3.');
        $expectedClose = array_key_exists('expected_close_date', $input) ? $input['expected_close_date'] : ($existing['expected_close_date'] ?? null);
        $expectedClose = $this->date($expectedClose, 'Expected close date');

        $before = $existing ? $this->auditValues($existing) : null;
        $this->pdo->beginTransaction();
        try {
            if ($existing) {
                $lock = $this->pdo->prepare('SELECT state FROM crm_leads WHERE id=:id AND company_id=:company_id FOR UPDATE');
                $lock->execute(['id' => $id, 'company_id' => $companyId]);
                if ($lock->fetchColumn() !== 'open') throw new InvalidArgumentException('Only open CRM records can be edited. Reload and try again.');
            }
            if ($existing) {
                $query = $this->pdo->prepare(
                    'UPDATE crm_leads SET partner_contact_id=:partner_contact_id, name=:name, contact_type=:contact_type, email=:email, phone=:phone, mobile=:mobile, website=:website, record_type=:record_type, stage_id=:stage_id, team_id=:team_id, salesperson_user_id=:salesperson_user_id, expected_revenue=:expected_revenue, currency_code=:currency_code, probability=:probability, priority=:priority, expected_close_date=:expected_close_date, source=:source, medium=:medium, campaign=:campaign, description=:description, updated_by=:updated_by WHERE id=:id AND company_id=:company_id'
                );
                $values['id'] = $id; $values['company_id'] = $companyId; $values['updated_by'] = $actorId;
                $query->execute($this->leadParams($values, $partnerId, $contactType, $recordType, $stageId, $teamId, $salespersonId, $revenue, $currency, $probability, $priority, $expectedClose));
                $savedId = $id;
                $event = 'updated';
            } else {
                $query = $this->pdo->prepare(
                    'INSERT INTO crm_leads (company_id, partner_contact_id, name, contact_type, email, phone, mobile, website, record_type, stage_id, team_id, salesperson_user_id, expected_revenue, currency_code, probability, priority, expected_close_date, source, medium, campaign, description, created_by, updated_by) ' .
                    'VALUES (:company_id,:partner_contact_id,:name,:contact_type,:email,:phone,:mobile,:website,:record_type,:stage_id,:team_id,:salesperson_user_id,:expected_revenue,:currency_code,:probability,:priority,:expected_close_date,:source,:medium,:campaign,:description,:created_by,:updated_by)'
                );
                $params = $this->leadParams($values, $partnerId, $contactType, $recordType, $stageId, $teamId, $salespersonId, $revenue, $currency, $probability, $priority, $expectedClose);
                $params['company_id'] = $companyId; $params['created_by'] = $actorId; $params['updated_by'] = $actorId;
                $query->execute($params);
                $savedId = (int)$this->pdo->lastInsertId();
                $event = 'created';
            }
            $this->audit($companyId, $actorId, $savedId, 'crm.lead', $event, $before, $this->auditValues($values + [
                'id' => $savedId, 'company_id' => $companyId, 'partner_contact_id' => $partnerId, 'contact_type' => $contactType,
                'record_type' => $recordType, 'stage_id' => $stageId, 'team_id' => $teamId, 'salesperson_user_id' => $salespersonId,
                'expected_revenue' => $revenue, 'currency_code' => $currency, 'probability' => $probability, 'priority' => $priority,
                'expected_close_date' => $expectedClose,
            ]), $remoteAddress);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($savedId, $user, $authorization) ?? throw new InvalidArgumentException('CRM record could not be reloaded after saving.');
    }

    public function convert(int $id, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['partner_contact_id']);
        if ($unknown) throw new InvalidArgumentException('Unsupported conversion fields: ' . implode(', ', $unknown));
        $lead = $this->find($id, $user, $authorization);
        if (!$lead) throw new InvalidArgumentException('CRM record was not found or is outside the allowed companies.');
        if ($lead['state'] !== 'open') throw new InvalidArgumentException('Only open leads can be converted.');
        if ($lead['record_type'] === 'opportunity') throw new InvalidArgumentException('This record is already an opportunity.');
        $companyId = (int)$lead['company_id'];
        $partnerId = $this->nullableId($input['partner_contact_id'] ?? $lead['partner_contact_id'], 'Contact');
        if ($partnerId !== null) $this->assertContact($partnerId, $companyId);
        $stageId = $this->defaultOpportunityStageId($companyId);
        $stage = $this->stage($stageId, $companyId);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id, record_type, state FROM crm_leads WHERE id=:id AND company_id=:company_id FOR UPDATE');
            $lock->execute(['id' => $id, 'company_id' => $companyId]);
            $current = $lock->fetch();
            if (!$current || $current['state'] !== 'open' || $current['record_type'] !== 'lead') throw new InvalidArgumentException('This lead was changed by another user. Reload and try again.');

            if ($partnerId === null) {
                $insertContact = $this->pdo->prepare(
                    'INSERT INTO contacts (company_id, contact_type, name, email, phone, mobile, website, customer, active) ' .
                    'VALUES (:company_id,:contact_type,:name,:email,:phone,:mobile,:website,1,1)'
                );
                $insertContact->execute([
                    'company_id' => $companyId, 'contact_type' => $lead['contact_type'], 'name' => $lead['name'],
                    'email' => $lead['email'], 'phone' => $lead['phone'], 'mobile' => $lead['mobile'], 'website' => $lead['website'],
                ]);
                $partnerId = (int)$this->pdo->lastInsertId();
                $this->audit($companyId, $actorId, $partnerId, 'contacts', 'created-from-crm', null, [
                    'name' => $lead['name'], 'contact_type' => $lead['contact_type'], 'email' => $lead['email'],
                    'phone' => $lead['phone'], 'mobile' => $lead['mobile'], 'website' => $lead['website'], 'customer' => 1,
                    'crm_lead_id' => $id,
                ], $remoteAddress);
            } else {
                $contactCustomer = $this->pdo->prepare('SELECT customer FROM contacts WHERE id=:id FOR UPDATE');
                $contactCustomer->execute(['id' => $partnerId]);
                $oldCustomer = $contactCustomer->fetchColumn();
                if ($oldCustomer === false) throw new InvalidArgumentException('The selected Contact no longer exists.');
                if ((int)$oldCustomer !== 1) {
                    $this->pdo->prepare('UPDATE contacts SET customer=1 WHERE id=:id')->execute(['id' => $partnerId]);
                    $this->audit($companyId, $actorId, $partnerId, 'contacts', 'updated-from-crm', ['customer' => (int)$oldCustomer], ['customer' => 1, 'crm_lead_id' => $id], $remoteAddress);
                }
            }
            $update = $this->pdo->prepare(
                'UPDATE crm_leads SET partner_contact_id=:partner_contact_id, record_type=\'opportunity\', stage_id=:stage_id, probability=:probability, converted_at=UTC_TIMESTAMP(), updated_by=:updated_by WHERE id=:id AND company_id=:company_id'
            );
            $update->execute(['partner_contact_id' => $partnerId, 'stage_id' => $stageId, 'probability' => $stage['probability'], 'updated_by' => $actorId, 'id' => $id, 'company_id' => $companyId]);
            $this->audit($companyId, $actorId, $id, 'crm.lead', 'converted', $this->auditValues($lead), [
                'record_type' => 'opportunity', 'partner_contact_id' => $partnerId, 'stage_id' => $stageId, 'probability' => $stage['probability'],
            ], $remoteAddress);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($id, $user, $authorization) ?? throw new InvalidArgumentException('Converted opportunity could not be reloaded.');
    }

    public function close(int $id, string $state, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        if (!in_array($state, ['won', 'lost'], true)) throw new InvalidArgumentException('CRM close state must be Won or Lost.');
        $unknown = array_diff(array_keys($input), ['lost_reason']);
        if ($unknown) throw new InvalidArgumentException('Unsupported CRM close fields: ' . implode(', ', $unknown));
        $lead = $this->find($id, $user, $authorization);
        if (!$lead) throw new InvalidArgumentException('CRM record was not found or is outside the allowed companies.');
        if ($lead['record_type'] !== 'opportunity') throw new InvalidArgumentException('Convert the lead to an opportunity before closing it.');
        if ($lead['state'] !== 'open') throw new InvalidArgumentException('This opportunity is already closed.');
        $lostReason = $state === 'lost' ? $this->text('Lost reason', $input['lost_reason'] ?? null, 500) : null;
        if ($state === 'lost' && $lostReason === null) throw new InvalidArgumentException('Enter a lost reason before marking this opportunity Lost.');
        $companyId = (int)$lead['company_id'];
        $this->pdo->beginTransaction();
        try {
            $wonStageId = $state === 'won' ? $this->activeWonStageId($companyId) : (int)$lead['stage_id'];
            $query = $this->pdo->prepare('UPDATE crm_leads SET state=:state, lost_reason=:lost_reason, stage_id=:stage_id, probability=:probability, updated_by=:updated_by WHERE id=:id AND company_id=:company_id AND state=\'open\'');
            $query->execute(['state' => $state, 'lost_reason' => $lostReason, 'stage_id' => $wonStageId, 'probability' => $state === 'won' ? '100' : $lead['probability'], 'updated_by' => $actorId, 'id' => $id, 'company_id' => $companyId]);
            if ($query->rowCount() !== 1) throw new InvalidArgumentException('This opportunity was changed by another user. Reload and try again.');
            $this->audit($companyId, $actorId, $id, 'crm.lead', $state, $this->auditValues($lead), [
                'state' => $state, 'lost_reason' => $lostReason, 'stage_id' => $wonStageId,
                'probability' => $state === 'won' ? '100' : $lead['probability'],
            ], $remoteAddress);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($id, $user, $authorization) ?? throw new InvalidArgumentException('Closed opportunity could not be reloaded.');
    }

    public function addActivity(int $leadId, array $input, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['activity_type', 'summary', 'due_at', 'assigned_user_id']);
        if ($unknown) throw new InvalidArgumentException('Unsupported activity fields: ' . implode(', ', $unknown));
        $lead = $this->find($leadId, $user, $authorization);
        if (!$lead) throw new InvalidArgumentException('CRM record was not found or is outside the allowed companies.');
        $type = (string)($input['activity_type'] ?? 'todo');
        if (!in_array($type, ['todo', 'call', 'meeting', 'email', 'upload', 'other'], true)) throw new InvalidArgumentException('Choose a valid activity type.');
        $summary = $this->text('Activity summary', $input['summary'] ?? null, 190);
        if ($summary === null) throw new InvalidArgumentException('Activity summary is required.');
        $dueAt = $this->datetime($input['due_at'] ?? null);
        $assignee = $this->nullableId($input['assigned_user_id'] ?? $user->id, 'Activity owner');
        if ($assignee !== null) $this->assertUserCompany($assignee, (int)$lead['company_id'], $user, $authorization);
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO crm_activities (company_id,lead_id,activity_type,summary,due_at,assigned_user_id,created_by) VALUES (:company_id,:lead_id,:activity_type,:summary,:due_at,:assigned_user_id,:created_by)'
            );
            $insert->execute(['company_id' => (int)$lead['company_id'], 'lead_id' => $leadId, 'activity_type' => $type, 'summary' => $summary, 'due_at' => $dueAt, 'assigned_user_id' => $assignee, 'created_by' => $actorId]);
            $activityId = (int)$this->pdo->lastInsertId();
            $this->audit((int)$lead['company_id'], $actorId, $activityId, 'crm.activity', 'created', null, [
                'lead_id' => $leadId, 'activity_type' => $type, 'summary' => $summary, 'due_at' => $dueAt, 'assigned_user_id' => $assignee,
            ], $remoteAddress);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($leadId, $user, $authorization) ?? throw new InvalidArgumentException('CRM record could not be reloaded.');
    }

    public function completeActivity(int $activityId, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $query = $this->pdo->prepare('SELECT * FROM crm_activities WHERE id=:id LIMIT 1');
        $query->execute(['id' => $activityId]);
        $activity = $query->fetch();
        if (!$activity || !$authorization->canAccessCompany((int)$activity['company_id'])) throw new InvalidArgumentException('Activity was not found or is outside the allowed companies.');
        if ($activity['state'] !== 'planned') throw new InvalidArgumentException('Only planned activities can be marked done.');
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE crm_activities SET state=\'done\', completed_at=UTC_TIMESTAMP() WHERE id=:id AND company_id=:company_id AND state=\'planned\'');
            $update->execute(['id' => $activityId, 'company_id' => (int)$activity['company_id']]);
            if ($update->rowCount() !== 1) throw new InvalidArgumentException('This activity was already updated. Reload and try again.');
            $this->audit((int)$activity['company_id'], $actorId, $activityId, 'crm.activity', 'completed', ['state' => 'planned'], ['state' => 'done'], $remoteAddress);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find((int)$activity['lead_id'], $user, $authorization) ?? throw new InvalidArgumentException('CRM record was not found.');
    }

    private function leadParams(array $values, ?int $partnerId, string $contactType, string $recordType, int $stageId, ?int $teamId, ?int $salespersonId, string $revenue, string $currency, string $probability, int $priority, ?string $expectedClose): array
    {
        return $values + [
            'partner_contact_id' => $partnerId, 'contact_type' => $contactType, 'record_type' => $recordType,
            'stage_id' => $stageId, 'team_id' => $teamId, 'salesperson_user_id' => $salespersonId,
            'expected_revenue' => $revenue, 'currency_code' => $currency, 'probability' => $probability,
            'priority' => $priority, 'expected_close_date' => $expectedClose,
        ];
    }

    private function ensureDefaultPipeline(int $companyId): void
    {
        $stageCount = $this->pdo->prepare('SELECT COUNT(*) FROM crm_stages WHERE company_id=:company');
        $stageCount->execute(['company' => $companyId]);
        if ((int)$stageCount->fetchColumn() === 0) {
            $stages = [
                ['New', 10, '0', 0], ['Qualified', 20, '20', 0], ['Proposition', 30, '50', 0], ['Won', 40, '100', 1],
            ];
            $insert = $this->pdo->prepare('INSERT IGNORE INTO crm_stages (company_id,name,sequence,probability,is_won) VALUES (:company_id,:name,:sequence,:probability,:is_won)');
            foreach ($stages as [$name, $sequence, $probability, $isWon]) {
                $insert->execute(['company_id' => $companyId, 'name' => $name, 'sequence' => $sequence, 'probability' => $probability, 'is_won' => $isWon]);
            }
        }
        $teamCount = $this->pdo->prepare('SELECT COUNT(*) FROM crm_teams WHERE company_id=:company');
        $teamCount->execute(['company' => $companyId]);
        if ((int)$teamCount->fetchColumn() === 0) {
            $this->pdo->prepare('INSERT IGNORE INTO crm_teams (company_id,name) VALUES (:company,\'Sales\')')->execute(['company' => $companyId]);
        }
    }

    private function defaultStageId(int $companyId): int
    {
        $query = $this->pdo->prepare('SELECT id FROM crm_stages WHERE company_id=:company AND active=1 AND is_won=0 ORDER BY sequence,id LIMIT 1');
        $query->execute(['company' => $companyId]);
        $id = (int)$query->fetchColumn();
        if ($id < 1) throw new InvalidArgumentException('Configure at least one active non-Won CRM stage before creating a lead.');
        return $id;
    }

    private function defaultOpportunityStageId(int $companyId): int
    {
        $query = $this->pdo->prepare('SELECT id FROM crm_stages WHERE company_id=:company AND active=1 AND is_won=0 AND probability>0 ORDER BY sequence,id LIMIT 1');
        $query->execute(['company' => $companyId]);
        $id = (int)$query->fetchColumn();
        return $id > 0 ? $id : $this->defaultStageId($companyId);
    }

    private function activeWonStageId(int $companyId): int
    {
        $query = $this->pdo->prepare('SELECT id FROM crm_stages WHERE company_id=:company AND active=1 AND is_won=1 ORDER BY sequence,id LIMIT 1');
        $query->execute(['company' => $companyId]);
        $id = (int)$query->fetchColumn();
        if ($id < 1) throw new InvalidArgumentException('Configure an active Won stage before closing an opportunity as Won.');
        return $id;
    }

    private function stage(int $id, int $companyId): ?array
    {
        $query = $this->pdo->prepare('SELECT id,name,probability,is_won FROM crm_stages WHERE id=:id AND company_id=:company_id AND active=1 LIMIT 1');
        $query->execute(['id' => $id, 'company_id' => $companyId]);
        return $query->fetch() ?: null;
    }

    private function assertContact(int $contactId, int $companyId): void
    {
        $query = $this->pdo->prepare('SELECT 1 FROM contacts WHERE id=:id AND active=1 AND (company_id IS NULL OR company_id=:company_id) LIMIT 1');
        $query->execute(['id' => $contactId, 'company_id' => $companyId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Choose a Contact available to this company.');
    }

    private function assertTeam(int $teamId, int $companyId): void
    {
        $query = $this->pdo->prepare('SELECT 1 FROM crm_teams WHERE id=:id AND company_id=:company_id AND active=1 LIMIT 1');
        $query->execute(['id' => $teamId, 'company_id' => $companyId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Choose an active Sales Team for this company.');
    }

    private function assertUserCompany(int $userId, int $companyId, UserContext $user, Authorization $authorization): void
    {
        if ($userId === $user->id && $authorization->canAccessCompany($companyId)) return;
        $query = $this->pdo->prepare(
            "SELECT 1 FROM users u JOIN user_company_access a ON a.user_id=u.id AND a.company_id=:company_id " .
            "WHERE u.id=:user_id AND u.status IN ('confirmed','active') LIMIT 1"
        );
        $query->execute(['company_id' => $companyId, 'user_id' => $userId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('The selected user does not have access to this company.');
    }

    private function resolveCompany(UserContext $user, Authorization $authorization, mixed $raw): int
    {
        $companyId = $raw === null || $raw === '' ? null : $this->positiveId($raw, 'Company');
        if ($companyId === null && $user->superuser) {
            $companyId = (int)$this->pdo->query('SELECT id FROM companies WHERE active=1 ORDER BY id LIMIT 1')->fetchColumn() ?: null;
        }
        $companyId ??= $user->defaultCompanyId ?? (count($user->companyIds) === 1 ? $user->companyIds[0] : null);
        if ($companyId === null) throw new InvalidArgumentException('Choose an allowed company.');
        if (!$authorization->canAccessCompany($companyId)) throw new InvalidArgumentException('The selected company is not available to this user.');
        $check = $this->pdo->prepare('SELECT 1 FROM companies WHERE id=:id AND active=1 LIMIT 1');
        $check->execute(['id' => $companyId]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('The selected company is inactive or does not exist.');
        return $companyId;
    }

    private function requireSettingsCompany(Authorization $authorization, int $companyId): void
    {
        if (!$authorization->canAccessCompany($companyId) || !$authorization->allowsForCompany('settings.write', $companyId)) {
            throw new \Erp\Core\Security\AccessDeniedException('You need Settings access for this company to configure CRM stages and sales teams.');
        }
    }

    private function text(string $field, mixed $value, int $maxLength): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) && !is_numeric($value)) throw new InvalidArgumentException($field . ' must be text.');
        $value = trim((string)$value);
        if ($value === '') return null;
        if (mb_strlen($value) > $maxLength) throw new InvalidArgumentException($field . ' must be ' . $maxLength . ' characters or fewer.');
        return $value;
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException($label . ' must be a positive integer.');
        return $id;
    }

    private function nullableId(mixed $value, string $label): ?int
    {
        return $value === null || $value === '' ? null : $this->positiveId($value, $label);
    }

    private function decimal(mixed $value, string $label, int $integerDigits, int $fractionDigits, bool $zeroAllowed): string
    {
        if (!is_string($value) && !is_numeric($value)) throw new InvalidArgumentException($label . ' must be a number.');
        $text = (string)$value;
        $pattern = '/^(?:0|[1-9][0-9]{0,' . ($integerDigits - 1) . '})(?:\.[0-9]{1,' . $fractionDigits . '})?$/D';
        if (preg_match($pattern, $text) !== 1 || (!$zeroAllowed && (float)$text < 0)) throw new InvalidArgumentException($label . ' has an invalid decimal value.');
        return $text;
    }

    private function date(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) throw new InvalidArgumentException($label . ' must use YYYY-MM-DD.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException($label . ' is not a valid calendar date.');
        return $value;
    }

    private function datetime(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) throw new InvalidArgumentException('Activity due date must be a date and time.');
        $normalized = str_replace('T', ' ', trim($value));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/D', $normalized) !== 1) throw new InvalidArgumentException('Activity due date must use YYYY-MM-DD HH:MM.');
        $normalized = strlen($normalized) === 16 ? $normalized . ':00' : $normalized;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized);
        if (!$date || $date->format('Y-m-d H:i:s') !== $normalized) throw new InvalidArgumentException('Activity due date is not valid.');
        return $normalized;
    }

    private function auditValues(array $values): array
    {
        return array_intersect_key($values, array_flip([
            'id', 'company_id', 'partner_contact_id', 'name', 'contact_type', 'email', 'phone', 'mobile', 'website',
            'record_type', 'stage_id', 'team_id', 'salesperson_user_id', 'expected_revenue', 'currency_code', 'probability',
            'priority', 'expected_close_date', 'source', 'medium', 'campaign', 'state', 'lost_reason',
        ]));
    }

    private function audit(int $companyId, int $actorId, int $recordId, string $model, string $event, ?array $old, array $new, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $insert = $this->pdo->prepare(
            'INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) ' .
            'VALUES (:company_id,:user_id,:event_key,:model_key,:record_id,:old_values,:new_values,:ip_address)'
        );
        $insert->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $insert->bindValue(':user_id', $actorId, PDO::PARAM_INT);
        $insert->bindValue(':event_key', $event);
        $insert->bindValue(':model_key', $model);
        $insert->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $insert->bindValue(':old_values', $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $old === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insert->bindValue(':new_values', json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $insert->bindValue(':ip_address', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $insert->execute();
    }
}
