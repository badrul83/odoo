<?php
declare(strict_types=1);

namespace Erp\Modules\Dashboards;

use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\UserContext;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

final class DashboardRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function summary(mixed $rawCompanyId, UserContext $user, Authorization $auth): array
    {
        $companyId = $rawCompanyId === null || $rawCompanyId === ''
            ? ($user->defaultCompanyId ?? (count($user->companyIds) === 1 ? $user->companyIds[0] : ($user->superuser ? $this->firstActiveCompanyId() : null)))
            : filter_var($rawCompanyId, FILTER_VALIDATE_INT);
        if (!$companyId || $companyId < 1 || !$auth->canAccessCompany((int)$companyId)) {
            throw new InvalidArgumentException('Select an active company in your allowed company scope.');
        }

        $companyQuery = $this->pdo->prepare('SELECT c.id,c.name,c.currency_code,c.timezone,cur.decimal_places FROM companies c JOIN currencies cur ON cur.currency_code=c.currency_code WHERE c.id=:id AND c.active=1');
        $companyQuery->execute(['id' => (int)$companyId]);
        $company = $companyQuery->fetch();
        if (!$company) throw new InvalidArgumentException('Company was not found or is archived.');
        if (!$auth->allowsForCompany('dashboards.read', (int)$companyId)) throw new AccessDeniedException('You do not have permission to read Dashboards for this company.');
        $companyNow = new DateTimeImmutable('now', new DateTimeZone((string)$company['timezone']));
        $today = $companyNow->format('Y-m-d');
        $monthStart = $companyNow->modify('first day of this month')->format('Y-m-d');
        $nextMonthStart = $companyNow->modify('first day of next month')->format('Y-m-d');

        $cards = [];
        $add = static function (string $key, string $title, string $value, string $description, string $page, array $details = []) use (&$cards): void {
            $cards[] = ['key' => $key, 'title' => $title, 'value' => $value, 'description' => $description, 'page' => $page, 'details' => $details];
        };
        $one = fn(string $sql, array $params = []): array => $this->one($sql, ['company' => (int)$companyId] + $params);
        $groups = fn(string $sql, array $params = []): array => $this->all($sql, ['company' => (int)$companyId] + $params);

        if ($auth->allowsForCompany('contacts.read', (int)$companyId)) {
            $contactScope = $auth->allowsForSharedRecords('contacts.read') ? '(company_id=:company OR company_id IS NULL)' : 'company_id=:company';
            $row = $one('SELECT COUNT(*) AS total, SUM(customer=1) AS customers, SUM(supplier=1) AS suppliers FROM contacts WHERE active=1 AND ' . $contactScope);
            $add('contacts', 'Contacts', (string)(int)$row['total'], 'active shared and company contacts', 'contacts', [
                ['label' => 'Customers', 'value' => (string)(int)$row['customers']], ['label' => 'Suppliers', 'value' => (string)(int)$row['suppliers']],
            ]);
        }
        if ($auth->allowsForCompany('crm.read', (int)$companyId)) {
            $rows = $groups("SELECT l.currency_code,c.decimal_places,COUNT(*) AS total,COALESCE(SUM(l.expected_revenue * l.probability / 100),0) AS amount FROM crm_leads l JOIN currencies c ON c.currency_code=l.currency_code WHERE l.company_id=:company AND l.record_type='opportunity' AND l.state='open' GROUP BY l.currency_code,c.decimal_places ORDER BY l.currency_code");
            $add('crm', 'Open Opportunities', (string)array_sum(array_map(static fn(array $r): int => (int)$r['total'], $rows)), 'weighted pipeline by opportunity currency', 'crm', $this->currencyLines($rows, 'amount', (int)$company['decimal_places']));
        }
        if ($auth->allowsForCompany('appointments.read', (int)$companyId)) {
            $row = $one("SELECT COUNT(*) AS total FROM appointment_bookings WHERE company_id=:company AND status='booked' AND starts_at_utc>=UTC_TIMESTAMP() AND starts_at_utc<DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY)");
            $add('appointments', 'Appointments', (string)(int)$row['total'], 'booked in the next 7 days', 'appointments');
        }
        if ($auth->allowsForCompany('todo.read', (int)$companyId)) {
            $canSeeAllTasks = $auth->allowsForCompany('settings.write', (int)$companyId);
            $visibility = $canSeeAllTasks ? '1=1' : '(t.created_by=:creator OR EXISTS (SELECT 1 FROM todo_task_assignees ta WHERE ta.task_id=t.id AND ta.company_id=t.company_id AND ta.user_id=:assignee))';
            $params = ['today' => $today];
            if (!$canSeeAllTasks) $params += ['creator' => $user->id, 'assignee' => $user->id];
            $row = $one("SELECT COUNT(*) AS open_total, SUM(t.deadline<:today) AS overdue_total FROM todo_tasks t JOIN todo_stages s ON s.id=t.stage_id AND s.company_id=t.company_id AND s.stage_type='open' WHERE t.company_id=:company AND t.archived_at IS NULL AND $visibility", $params);
            $add('todo', 'To-do', (string)(int)$row['open_total'], (int)$row['overdue_total'] . ' overdue · only tasks visible to you', 'todo');
        }
        if ($auth->allowsForCompany('sales.read', (int)$companyId)) {
            $rows = $groups("SELECT so.currency_code,c.decimal_places,COUNT(*) AS total,COALESCE(SUM(so.total_amount),0) AS amount FROM sales_orders so JOIN currencies c ON c.currency_code=so.currency_code WHERE so.company_id=:company AND so.state='sale' AND so.order_date>=:from_date AND so.order_date<:until_date GROUP BY so.currency_code,c.decimal_places ORDER BY so.currency_code", ['from_date' => $monthStart, 'until_date' => $nextMonthStart]);
            $add('sales', 'Sales Orders', (string)array_sum(array_map(static fn(array $r): int => (int)$r['total'], $rows)), 'confirmed this month', 'sales', $this->currencyLines($rows, 'amount', (int)$company['decimal_places']));
        }
        if ($auth->allowsForCompany('purchase.read', (int)$companyId)) {
            $rows = $groups("SELECT po.currency_code,c.decimal_places,COUNT(*) AS total,COALESCE(SUM(po.total_amount),0) AS amount FROM purchase_orders po JOIN currencies c ON c.currency_code=po.currency_code WHERE po.company_id=:company AND po.state='purchase' AND po.order_date>=:from_date AND po.order_date<:until_date GROUP BY po.currency_code,c.decimal_places ORDER BY po.currency_code", ['from_date' => $monthStart, 'until_date' => $nextMonthStart]);
            $add('purchase', 'Purchase Orders', (string)array_sum(array_map(static fn(array $r): int => (int)$r['total'], $rows)), 'confirmed this month', 'purchase', $this->currencyLines($rows, 'amount', (int)$company['decimal_places']));
        }
        if ($auth->allowsForCompany('invoicing.read', (int)$companyId)) {
            $rows = $groups("SELECT i.currency_code,c.decimal_places,COUNT(CASE WHEN i.document_type='invoice' THEN 1 END) AS total,COALESCE(SUM(CASE WHEN i.document_type='invoice' THEN i.amount_residual WHEN i.document_type='credit_note' THEN -i.amount_residual ELSE 0 END),0) AS amount,SUM(CASE WHEN i.document_type='invoice' AND i.due_date<:today THEN 1 ELSE 0 END) AS overdue FROM customer_invoices i JOIN currencies c ON c.currency_code=i.currency_code WHERE i.company_id=:company AND i.document_type IN ('invoice','credit_note') AND i.state='posted' AND i.amount_residual>0 GROUP BY i.currency_code,c.decimal_places ORDER BY i.currency_code", ['today' => $today]);
            $count = array_sum(array_map(static fn(array $r): int => (int)$r['total'], $rows));
            $overdue = array_sum(array_map(static fn(array $r): int => (int)$r['overdue'], $rows));
            $add('receivables', 'Receivables', (string)$count, $overdue . ' overdue invoices · net of open credit notes', 'invoicing', $this->currencyLines($rows, 'amount', (int)$company['decimal_places']));
        }
        if ($auth->allowsForCompany('invoicing.read', (int)$companyId) && $auth->allowsForCompany('purchase.read', (int)$companyId)) {
            $rows = $groups("SELECT b.currency_code,c.decimal_places,COUNT(*) AS total,COALESCE(SUM(b.amount_residual),0) AS amount,SUM(b.due_date<:today) AS overdue FROM vendor_bills b JOIN currencies c ON c.currency_code=b.currency_code WHERE b.company_id=:company AND b.state='posted' AND b.amount_residual>0 GROUP BY b.currency_code,c.decimal_places ORDER BY b.currency_code", ['today' => $today]);
            $count = array_sum(array_map(static fn(array $r): int => (int)$r['total'], $rows));
            $overdue = array_sum(array_map(static fn(array $r): int => (int)$r['overdue'], $rows));
            $add('payables', 'Payables', (string)$count, $overdue . ' overdue bills · posted residual', 'purchase', $this->currencyLines($rows, 'amount', (int)$company['decimal_places']));
        }
        if ($auth->allowsForCompany('inventory.read', (int)$companyId)) {
            $row = $one('SELECT COUNT(*) AS products,COALESCE(SUM(value),0) AS value FROM inventory_valuation_balances WHERE company_id=:company AND quantity<>0');
            $add('inventory', 'Inventory', (string)(int)$row['products'], 'products with stock on hand', 'inventory', [
                ['label' => 'Valuation · ' . $company['currency_code'], 'value' => $this->money($row['value'], (int)$company['decimal_places'])],
            ]);
        }
        if ($auth->allowsForCompany('accounting.read', (int)$companyId)) {
            $row = $one("SELECT COUNT(*) AS total FROM accounting_moves WHERE company_id=:company AND state='posted' AND accounting_date>=:from_date AND accounting_date<:until_date", ['from_date' => $monthStart, 'until_date' => $nextMonthStart]);
            $add('accounting', 'Accounting', (string)(int)$row['total'], 'posted journal entries this month', 'accounting');
        }
        $recentActivity = $this->recentActivity((int)$companyId, $user, $auth);
        $quickActions = [
            'new_contact' => $auth->allowsForCompany('contact_master_data.create', (int)$companyId),
            'new_lead' => $auth->allowsForCompany('crm.read', (int)$companyId) && $auth->allowsForCompany('crm.write', (int)$companyId),
            'new_quotation' => $auth->allowsForCompany('sales.read', (int)$companyId) && $auth->allowsForCompany('sales.write', (int)$companyId),
            'new_rfq' => $auth->allowsForCompany('purchase.read', (int)$companyId) && $auth->allowsForCompany('purchase.write', (int)$companyId),
            'new_invoice' => $auth->allowsForCompany('invoicing.read', (int)$companyId) && $auth->allowsForCompany('invoicing.write', (int)$companyId) && $auth->allowsForCompany('contacts.read', (int)$companyId),
            'new_task' => $auth->allowsForCompany('todo.read', (int)$companyId) && $auth->allowsForCompany('todo.write', (int)$companyId),
            'new_contract' => $auth->allowsForCompany('contracts.write', (int)$companyId),
            'new_appointment' => $auth->allowsForCompany('appointments.read', (int)$companyId) && $auth->allowsForCompany('appointments.write', (int)$companyId),
            'open_inventory' => $auth->allowsForCompany('inventory.read', (int)$companyId),
            'open_accounts' => $auth->allowsForCompany('accounting.read', (int)$companyId),
            'company_settings' => $auth->allowsForCompany('settings.read', (int)$companyId),
        ];

        return ['company' => ['id' => (int)$company['id'], 'name' => (string)$company['name'], 'currency_code' => (string)$company['currency_code'], 'decimal_places' => (int)$company['decimal_places']], 'companies' => $this->companiesFor($user, $auth), 'cards' => $cards, 'quick_actions' => $quickActions, 'recent_activity' => $recentActivity, 'as_of' => $companyNow->format(DATE_ATOM)];
    }

    private function recentActivity(int $companyId, UserContext $user, Authorization $auth): array
    {
        // Only query records from the requested module scope and modules the user can read.
        // Audit JSON is intentionally excluded: it may contain private business data.
        $models = [];
        $add = static function (string $permission, array $keys) use (&$models, $auth, $companyId): void {
            if (!$auth->allowsForCompany($permission, $companyId)) return;
            foreach ($keys as $key => $label) $models[$key] = $label;
        };
        $add('contacts.read', ['contacts' => 'Contacts', 'contact.message' => 'Contacts', 'contact.activity' => 'Contacts']);
        $add('crm.read', ['crm.lead' => 'CRM', 'crm.activity' => 'CRM', 'crm.stage' => 'CRM', 'crm.team' => 'CRM']);
        $add('sales.read', ['sales_order' => 'Sales', 'crm_lead' => 'Sales']);
        $add('purchase.read', [
            'purchase_order' => 'Purchase', 'vendor_bill' => 'Purchase / Accounts', 'vendor_payment' => 'Purchase / Accounts',
            'vendor_credit_note' => 'Purchase / Accounts', 'vendor_credit_note_application' => 'Purchase / Accounts',
            'vendor_credit_note_refund' => 'Purchase / Accounts', 'vendor_credit_note_refund_match' => 'Purchase / Accounts',
            'vendor_bank_statement_match' => 'Purchase / Accounts',
        ]);
        $add('invoicing.read', [
            'customer_invoice' => 'Invoice', 'customer_payment' => 'Invoice / Accounts',
            'customer_credit_note_application' => 'Invoice / Accounts', 'customer_credit_note_refund' => 'Invoice / Accounts',
            'customer_credit_note_refund_match' => 'Invoice / Accounts', 'bank_statement_match' => 'Invoice / Accounts',
        ]);
        $add('contracts.read', ['contract' => 'Contract']);
        $add('appointments.read', ['appointment.booking' => 'Appointment', 'appointment.type' => 'Appointment', 'appointment.resource' => 'Appointment']);
        $add('todo.read', ['todo.task' => 'To-do', 'todo.stage' => 'To-do']);
        $add('inventory.read', [
            'inventory.stock_move' => 'Inventory', 'inventory.product' => 'Inventory',
            'inventory.product.category' => 'Inventory', 'inventory.uom.category' => 'Inventory',
            'inventory.uom' => 'Inventory', 'inventory.warehouse' => 'Inventory', 'inventory.location' => 'Inventory',
            'inventory_cycle_count' => 'Inventory', 'inventory_cycle_count_line' => 'Inventory',
            'inventory_valuation_closing' => 'Inventory', 'stock_location' => 'Inventory',
        ]);
        $add('accounting.read', [
            'accounting.manual_journal_entry' => 'Accounts', 'accounting_move' => 'Accounts',
            'bank_statement_match' => 'Accounts', 'vendor_bank_statement_match' => 'Accounts',
            'customer_payment' => 'Invoice / Accounts', 'vendor_payment' => 'Purchase / Accounts',
            'vendor_bill' => 'Purchase / Accounts',
        ]);
        $add('settings.read', ['companies' => 'Settings', 'company_branches' => 'Settings']);
        if (!$models) return [];

        $placeholders = [];
        $params = ['company' => $companyId];
        foreach (array_keys($models) as $index => $key) {
            $placeholder = 'model_' . $index;
            $placeholders[] = ':' . $placeholder;
            $params[$placeholder] = $key;
        }
        $todoScope = '';
        if (isset($models['todo.task']) && !$auth->allowsForCompany('settings.write', $companyId)) {
            $todoScope = ' AND (e.model_key<>\'todo.task\' OR EXISTS (SELECT 1 FROM todo_tasks t WHERE t.id=e.record_id AND t.company_id=e.company_id AND t.archived_at IS NULL AND (t.created_by=:todo_creator OR EXISTS (SELECT 1 FROM todo_task_assignees ta WHERE ta.task_id=t.id AND ta.company_id=t.company_id AND ta.user_id=:todo_assignee))))';
            $params['todo_creator'] = $user->id;
            $params['todo_assignee'] = $user->id;
        }
        $sql = 'SELECT e.id,e.model_key,e.event_key,e.created_at,COALESCE(c.name,\'System\') AS actor_name '
            . 'FROM audit_events e LEFT JOIN users u ON u.id=e.user_id '
            . 'LEFT JOIN contacts c ON c.id=u.contact_id '
            . 'WHERE e.company_id=:company AND e.model_key IN (' . implode(',', $placeholders) . ') '
            . $todoScope
            . 'ORDER BY e.created_at DESC,e.id DESC LIMIT 10';
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['module'] = $models[$row['model_key']] ?? 'ERP';
            unset($row['model_key']);
        }
        unset($row);
        return $rows;
    }

    private function companiesFor(UserContext $user, Authorization $auth): array
    {
        if ($user->superuser) {
            return $this->pdo->query('SELECT id,name FROM companies WHERE active=1 ORDER BY name,id')->fetchAll();
        }
        if (!$user->companyIds) return [];
        $params = [];
        $placeholders = [];
        foreach ($user->companyIds as $index => $id) {
            $key = 'company_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $query = $this->pdo->prepare('SELECT id,name FROM companies WHERE active=1 AND id IN (' . implode(',', $placeholders) . ') ORDER BY name,id');
        $query->execute($params);
        return array_values(array_filter($query->fetchAll(), static fn(array $company): bool => $auth->allowsForCompany('dashboards.read', (int)$company['id'])));
    }

    private function firstActiveCompanyId(): ?int
    {
        $id = $this->pdo->query('SELECT id FROM companies WHERE active=1 ORDER BY name,id LIMIT 1')->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function one(string $sql, array $params): array
    {
        $query = $this->pdo->prepare($sql); $query->execute($params); return $query->fetch() ?: [];
    }

    private function all(string $sql, array $params): array
    {
        $query = $this->pdo->prepare($sql); $query->execute($params); return $query->fetchAll();
    }

    private function currencyLines(array $rows, string $amountKey, int $places): array
    {
        return array_map(fn(array $row): array => ['label' => (string)$row['currency_code'], 'value' => $this->money($row[$amountKey], isset($row['decimal_places']) ? (int)$row['decimal_places'] : $places)], $rows);
    }

    private function money(mixed $amount, int $places): string
    {
        return number_format((float)$amount, max(0, min(4, $places)), '.', ',');
    }
}
