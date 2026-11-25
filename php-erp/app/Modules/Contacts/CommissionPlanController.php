<?php
declare(strict_types=1);

namespace Erp\Modules\Contacts;

use Erp\Core\Config;
use Erp\Core\Database;
use Erp\Core\Http\Request;
use Erp\Core\Http\Response;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\AuthenticationRequiredException;
use Erp\Core\Security\AuthService;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\Session;
use Erp\Core\Security\UserContext;
use PDO;
use PDOException;
use Throwable;

final class CommissionPlanController
{
    public function __construct(private readonly Config $config) {}

    public function options(Request $request, array $params): Response
    {
        return $this->run($request, 'partner_commissions.read', 'Commission plan options could not be loaded.', function (PDO $pdo, UserContext $user, Authorization $auth) use ($request): array {
            $companyId = $this->companyId($request->query()['company_id'] ?? null, $user, $auth, 'partner_commissions.read');
            $companyClause = $companyId === null ? 'company_id IS NULL' : '(company_id IS NULL OR company_id = :company_id)';
            $params = $companyId === null ? [] : ['company_id' => $companyId];
            $companies = $this->companies($pdo, $user, $auth, 'partner_commissions.read');
            $manageableCompanies = $this->companies($pdo, $user, $auth, 'partner_commissions.manage');

            $products = $pdo->prepare('SELECT id, name, internal_reference FROM products WHERE active = 1 AND company_id = :company_id ORDER BY name, id LIMIT 2000');
            $products->execute(['company_id' => $companyId ?? 0]);
            $categories = $pdo->prepare('SELECT id, name FROM product_categories WHERE active = 1 AND ' . $companyClause . ' ORDER BY name, id LIMIT 1000');
            $categories->execute($params);
            $templateClause = $companyId === null ? 'company_id IS NULL' : 'company_id = :company_id';
            $templates = $pdo->prepare('SELECT id, name FROM sales_order_templates WHERE active = 1 AND ' . $templateClause . ' ORDER BY name, id LIMIT 1000');
            $templates->execute($params);
            $pricelists = $pdo->prepare('SELECT id, name, currency_code FROM price_lists WHERE active = 1 AND ' . $companyClause . ' ORDER BY name, id LIMIT 1000');
            $pricelists->execute($params);
            return [
                'company_id' => $companyId,
                'companies' => $companies,
                'manageable_companies' => $manageableCompanies,
                'products' => $products->fetchAll(),
                'product_categories' => $categories->fetchAll(),
                'sale_order_templates' => $templates->fetchAll(),
                'pricelists' => $pricelists->fetchAll(),
                'can_manage' => $companyId === null
                    ? $auth->allowsForSharedRecords('partner_commissions.manage')
                    : $auth->allowsForCompany('partner_commissions.manage', $companyId),
            ];
        });
    }

    public function index(Request $request, array $params): Response
    {
        return $this->run($request, 'partner_commissions.read', 'Commission plans could not be loaded.', function (PDO $pdo, UserContext $user, Authorization $auth) use ($request): array {
            $companyId = $this->companyId($request->query()['company_id'] ?? null, $user, $auth, 'partner_commissions.read');
            $where = $companyId === null ? 'p.company_id IS NULL' : '(p.company_id IS NULL OR p.company_id = :company_id)';
            $query = $pdo->prepare('SELECT p.id, p.company_id, c.name AS company_name, p.name, p.purchase_default_product_id, product.name AS purchase_default_product_name, p.active, p.updated_at FROM commission_plans p LEFT JOIN companies c ON c.id = p.company_id LEFT JOIN products product ON product.id = p.purchase_default_product_id WHERE ' . $where . ' ORDER BY p.active DESC, p.name, p.id LIMIT 500');
            $query->execute($companyId === null ? [] : ['company_id' => $companyId]);
            $plans = $query->fetchAll();
            if ($plans) {
                $ids = array_map(static fn(array $plan): int => (int)$plan['id'], $plans);
                $slots = [];
                $bindings = [];
                foreach ($ids as $i => $id) { $slots[] = ':plan_' . $i; $bindings['plan_' . $i] = $id; }
                $rulesQuery = $pdo->prepare('SELECT r.id, r.commission_plan_id, r.product_category_id, category.name AS product_category_name, r.product_id, product.name AS product_name, r.sale_order_template_id, template.name AS sale_order_template_name, r.pricelist_id, pricelist.name AS pricelist_name, r.rate, r.is_capped, r.max_commission, r.sequence, r.active FROM commission_plan_rules r LEFT JOIN product_categories category ON category.id = r.product_category_id LEFT JOIN products product ON product.id = r.product_id LEFT JOIN sales_order_templates template ON template.id = r.sale_order_template_id LEFT JOIN price_lists pricelist ON pricelist.id = r.pricelist_id WHERE r.active = 1 AND r.commission_plan_id IN (' . implode(',', $slots) . ') ORDER BY r.commission_plan_id, r.sequence, r.id');
                $rulesQuery->execute($bindings);
                $rulesByPlan = [];
                foreach ($rulesQuery->fetchAll() as $rule) $rulesByPlan[(int)$rule['commission_plan_id']][] = $rule;
                foreach ($plans as &$plan) {
                    $plan['rules'] = $rulesByPlan[(int)$plan['id']] ?? [];
                    $plan['can_manage'] = $plan['company_id'] === null
                        ? $auth->allowsForSharedRecords('partner_commissions.manage')
                        : $auth->allowsForCompany('partner_commissions.manage', (int)$plan['company_id']);
                }
                unset($plan);
            }
            return ['company_id' => $companyId, 'can_manage' => $companyId === null
                ? $auth->allowsForSharedRecords('partner_commissions.manage')
                : $auth->allowsForCompany('partner_commissions.manage', $companyId), 'plans' => $plans];
        });
    }

    public function create(Request $request, array $params): Response { return $this->save($request, null); }
    public function update(Request $request, array $params): Response { return $this->save($request, $this->routeId($params)); }

    public function archive(Request $request, array $params): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
        return $this->run($request, 'partner_commissions.manage', 'Commission plan could not be archived.', function (PDO $pdo, UserContext $user, Authorization $auth) use ($request, $params): array {
            $id = $this->routeId($params);
            $pdo->beginTransaction();
            try {
                $query = $pdo->prepare('SELECT id, company_id, name, active FROM commission_plans WHERE id = :id LIMIT 1 FOR UPDATE');
                $query->execute(['id' => $id]);
                $plan = $query->fetch();
                if (!$plan) throw new \InvalidArgumentException('Commission Plan was not found.');
                $companyId = $plan['company_id'] === null ? null : (int)$plan['company_id'];
                $this->requireCompanyPermission($auth, $companyId, 'partner_commissions.read');
                $this->requireCompanyPermission($auth, $companyId, 'partner_commissions.manage');
                $assigned = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE commission_plan_id = :id');
                $assigned->execute(['id' => $id]);
                if ((int)$assigned->fetchColumn() > 0) throw new \InvalidArgumentException('This plan is assigned to Contacts. Remove those assignments before archiving it.');
                if ((bool)$plan['active']) {
                    $pdo->prepare('UPDATE commission_plans SET active = 0 WHERE id = :id')->execute(['id' => $id]);
                    $this->audit($pdo, $companyId, $user->id, $id, 'commission_plan_archived', ['name' => $plan['name'], 'active' => true], ['name' => $plan['name'], 'active' => false], $request->remoteAddress());
                }
                $pdo->commit();
                return ['plan_id' => $id, 'active' => false];
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
        });
    }

    private function save(Request $request, ?int $id): Response
    {
        if (!Session::verifyCsrf($request->header('x-csrf-token'))) return Response::json(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
        if ($request->hasInvalidJson()) return Response::json(['ok' => false, 'error' => 'The request body must be valid JSON.'], 400);
        try {
            $pdo = Database::connection($this->config);
            $user = (new AuthService($pdo))->currentUser();
            if (!$user) throw new AuthenticationRequiredException('Sign in to manage commission plans.');
            $auth = new Authorization($pdo, $user);
            $input = $request->input();
            $unknown = array_diff(array_keys($input), ['company_id', 'name', 'purchase_default_product_id', 'active', 'rules']);
            if ($unknown) throw new \InvalidArgumentException('Unsupported Commission Plan fields: ' . implode(', ', $unknown));
            $companyId = $this->nullableId($input['company_id'] ?? null, 'Company');
            $this->requireCompanyPermission($auth, $companyId, 'partner_commissions.read');
            $this->requireCompanyPermission($auth, $companyId, 'partner_commissions.manage');
            $name = $this->text($input['name'] ?? null, 'Name', 120, true);
            $defaultProductId = $this->nullableId($input['purchase_default_product_id'] ?? null, 'Purchase Default Product');
            if ($defaultProductId !== null) $this->assertReference($pdo, 'products', $defaultProductId, $companyId, false, 'Purchase Default Product');
            $active = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($active === null) throw new \InvalidArgumentException('Active must be true or false.');
            $rawRules = $input['rules'] ?? [];
            if (!is_array($rawRules) || count($rawRules) > 200) throw new \InvalidArgumentException('Rules must be a list of up to 200 rows.');
            $rules = [];
            foreach (array_values($rawRules) as $index => $rawRule) $rules[] = $this->prepareRule($pdo, $rawRule, $companyId, $index);

            $pdo->beginTransaction();
            try {
                $existing = null;
                $existingRules = [];
                if ($id !== null) {
                    $lock = $pdo->prepare('SELECT id, company_id, name, purchase_default_product_id, active FROM commission_plans WHERE id = :id LIMIT 1 FOR UPDATE');
                    $lock->execute(['id' => $id]);
                    $existing = $lock->fetch();
                    if (!$existing) throw new \InvalidArgumentException('Commission Plan was not found.');
                    $existingCompanyId = $existing['company_id'] === null ? null : (int)$existing['company_id'];
                    $this->requireCompanyPermission($auth, $existingCompanyId, 'partner_commissions.read');
                    $this->requireCompanyPermission($auth, $existingCompanyId, 'partner_commissions.manage');
                    if ($existingCompanyId !== $companyId) throw new \InvalidArgumentException('A Commission Plan cannot be moved to another company.');
                    $oldRulesQuery = $pdo->prepare('SELECT product_category_id, product_id, sale_order_template_id, pricelist_id, rate, is_capped, max_commission FROM commission_plan_rules WHERE commission_plan_id = :id AND active = 1 ORDER BY sequence, id');
                    $oldRulesQuery->execute(['id' => $id]);
                    $existingRules = $oldRulesQuery->fetchAll();
                    $pdo->prepare('UPDATE commission_plans SET name = :name, purchase_default_product_id = :product, active = :active WHERE id = :id')
                        ->execute(['name' => $name, 'product' => $defaultProductId, 'active' => (int)$active, 'id' => $id]);
                    $pdo->prepare('UPDATE commission_plan_rules SET active = 0 WHERE commission_plan_id = :id AND active = 1')->execute(['id' => $id]);
                } else {
                    $pdo->prepare('INSERT INTO commission_plans (company_id, name, purchase_default_product_id, active) VALUES (:company, :name, :product, :active)')
                        ->execute(['company' => $companyId, 'name' => $name, 'product' => $defaultProductId, 'active' => (int)$active]);
                    $id = (int)$pdo->lastInsertId();
                }
                $insert = $pdo->prepare('INSERT INTO commission_plan_rules (commission_plan_id, product_category_id, product_id, sale_order_template_id, pricelist_id, rate, is_capped, max_commission, sequence, active) VALUES (:plan, :category, :product, :template, :pricelist, :rate, :capped, :maximum, :sequence, 1)');
                foreach ($rules as $index => $rule) {
                    $insert->execute([
                        'plan' => $id, 'category' => $rule['product_category_id'], 'product' => $rule['product_id'],
                        'template' => $rule['sale_order_template_id'], 'pricelist' => $rule['pricelist_id'],
                        'rate' => $rule['rate'], 'capped' => (int)$rule['is_capped'], 'maximum' => $rule['max_commission'],
                        'sequence' => ($index + 1) * 10,
                    ]);
                }
                $this->audit($pdo, $companyId, $user->id, $id, $existing ? 'commission_plan_updated' : 'commission_plan_created', $existing ? ['name' => $existing['name'], 'purchase_default_product_id' => $existing['purchase_default_product_id'], 'active' => (bool)$existing['active'], 'rules' => $existingRules] : null, ['name' => $name, 'purchase_default_product_id' => $defaultProductId, 'active' => $active, 'rules' => $rules], $request->remoteAddress());
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
            return Response::json(['ok' => true, 'plan_id' => $id], $existing ? 200 : 201);
        } catch (AccessDeniedException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 403);
        } catch (AuthenticationRequiredException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 401);
        } catch (\InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log('Commission Plan save failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Commission Plan could not be saved. Check that all selected records are still active.'], 409);
        } catch (Throwable $error) {
            error_log('Commission Plan save failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Commission Plan could not be saved.'], 500);
        }
    }

    private function run(Request $request, string $permission, string $failure, callable $action): Response
    {
        try {
            $pdo = Database::connection($this->config);
            $user = (new AuthService($pdo))->currentUser();
            if (!$user) throw new AuthenticationRequiredException('Sign in to continue.');
            $auth = new Authorization($pdo, $user);
            if (!$auth->allows($permission) && !$auth->allowsForSharedRecords($permission)) throw new AccessDeniedException('You do not have permission to read Partner Commissions.');
            return Response::json(['ok' => true] + $action($pdo, $user, $auth));
        } catch (AccessDeniedException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 403);
        } catch (AuthenticationRequiredException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 401);
        } catch (\InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (PDOException $error) {
            error_log($failure . ' ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => $failure], 503);
        } catch (Throwable $error) {
            error_log($failure . ' ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => $failure], 500);
        }
    }

    private function prepareRule(PDO $pdo, mixed $raw, ?int $companyId, int $index): array
    {
        if (!is_array($raw)) throw new \InvalidArgumentException('Rule ' . ($index + 1) . ' must be an object.');
        $allowed = ['product_category_id', 'product_id', 'sale_order_template_id', 'pricelist_id', 'rate', 'is_capped', 'max_commission'];
        $unknown = array_diff(array_keys($raw), $allowed);
        if ($unknown) throw new \InvalidArgumentException('Rule ' . ($index + 1) . ' has unsupported fields: ' . implode(', ', $unknown));
        $rule = [];
        foreach (['product_category_id' => 'product_categories', 'product_id' => 'products', 'sale_order_template_id' => 'sales_order_templates', 'pricelist_id' => 'price_lists'] as $field => $table) {
            $value = $this->nullableId($raw[$field] ?? null, $field);
            $sharedAllowed = $table !== 'products' && ($table !== 'sales_order_templates' || $companyId === null);
            if ($value !== null) $this->assertReference($pdo, $table, $value, $companyId, $sharedAllowed, $field);
            $rule[$field] = $value;
        }
        $rate = $this->decimal($raw['rate'] ?? null, 'Rule ' . ($index + 1) . ' Rate', true, 8);
        $capped = filter_var($raw['is_capped'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($capped === null) throw new \InvalidArgumentException('Rule ' . ($index + 1) . ' Capped must be true or false.');
        $maximum = $capped ? $this->decimal($raw['max_commission'] ?? null, 'Rule ' . ($index + 1) . ' Max Commission', true, 16) : null;
        if ($capped && (float)$maximum <= 0) throw new \InvalidArgumentException('Rule ' . ($index + 1) . ' Max Commission must be greater than zero when Capped is enabled.');
        $rule['rate'] = $rate;
        $rule['is_capped'] = $capped;
        $rule['max_commission'] = $maximum;
        return $rule;
    }

    private function assertReference(PDO $pdo, string $table, int $id, ?int $companyId, bool $sharedAllowed, string $label): void
    {
        $allowedTables = ['products', 'product_categories', 'sales_order_templates', 'price_lists'];
        if (!in_array($table, $allowedTables, true)) throw new \LogicException('Invalid lookup table.');
        $scope = $sharedAllowed ? '(company_id IS NULL OR company_id = :company_id)' : 'company_id = :company_id';
        $query = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE id = :id AND active = 1 AND ' . $scope . ' LIMIT 1');
        $query->execute(['id' => $id, 'company_id' => $companyId ?? 0]);
        if (!$query->fetchColumn()) throw new \InvalidArgumentException($label . ' is inactive, unavailable, or outside the selected company.');
    }

    private function companies(PDO $pdo, UserContext $user, Authorization $auth, string $permission): array
    {
        if ($user->superuser) return $pdo->query('SELECT id, name FROM companies WHERE active = 1 ORDER BY name')->fetchAll();
        if (!$user->companyIds) return [];
        $items = [];
        foreach ($user->companyIds as $index => $id) {
            if ($auth->allowsForCompany($permission, $id) || $auth->allowsForCompany('partner_commissions.read', $id)) $items[] = $id;
        }
        if (!$items) return [];
        $slots = [];
        $bindings = [];
        foreach ($items as $i => $id) { $slots[] = ':company_' . $i; $bindings['company_' . $i] = $id; }
        $query = $pdo->prepare('SELECT id, name FROM companies WHERE active = 1 AND id IN (' . implode(',', $slots) . ') ORDER BY name');
        $query->execute($bindings);
        return $query->fetchAll();
    }

    private function companyId(mixed $raw, UserContext $user, Authorization $auth, string $permission): ?int
    {
        $companyId = $this->nullableId($raw, 'Company');
        if ($companyId === null) {
            if (!$auth->allowsForSharedRecords($permission)) throw new AccessDeniedException('Shared Commission Plans are not available to this user.');
        } else {
            $this->requireCompanyPermission($auth, $companyId, $permission);
        }
        return $companyId;
    }

    private function requireCompanyPermission(Authorization $auth, ?int $companyId, string $permission): void
    {
        $allowed = $companyId === null ? $auth->allowsForSharedRecords($permission) : $auth->allowsForCompany($permission, $companyId);
        if (!$allowed) throw new AccessDeniedException('You do not have the required Partner Commissions access for this company.');
    }

    private function audit(PDO $pdo, ?int $companyId, int $userId, int $recordId, string $event, ?array $old, array $new, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $query = $pdo->prepare('INSERT INTO audit_events (company_id, user_id, event_key, model_key, record_id, old_values, new_values, ip_address) VALUES (:company, :user, :event, \'commission_plan\', :record, :old_values, :new_values, :ip)');
        $query->bindValue(':company', $companyId, $companyId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(':user', $userId, PDO::PARAM_INT);
        $query->bindValue(':event', $event);
        $query->bindValue(':record', $recordId, PDO::PARAM_INT);
        $query->bindValue(':old_values', $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $old === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $query->bindValue(':new_values', json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $query->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $query->execute();
    }

    private function routeId(array $params): int
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new \InvalidArgumentException('Select a valid Commission Plan.');
        return $id;
    }

    private function nullableId(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') return null;
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new \InvalidArgumentException($label . ' must be a valid record.');
        return $id;
    }

    private function text(mixed $value, string $label, int $max, bool $required): ?string
    {
        if (!is_string($value) && !is_numeric($value)) throw new \InvalidArgumentException($label . ' must be text.');
        $value = trim((string)$value);
        if ($value === '' && $required) throw new \InvalidArgumentException($label . ' is required.');
        if (mb_strlen($value) > $max) throw new \InvalidArgumentException($label . ' must be ' . $max . ' characters or fewer.');
        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value, string $label, bool $required, int $maxIntegerDigits): ?string
    {
        if (($value === null || $value === '') && !$required) return null;
        if (!is_string($value) && !is_numeric($value)) throw new \InvalidArgumentException($label . ' must be a non-negative number.');
        $value = trim((string)$value);
        if (!preg_match('/^\d{1,' . $maxIntegerDigits . '}(?:\.\d{1,4})?$/D', $value)) throw new \InvalidArgumentException($label . ' must be a non-negative number with up to 4 decimal places.');
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        return ($integer === '' ? '0' : $integer) . '.' . str_pad($fraction, 4, '0');
    }
}
