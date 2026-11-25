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
use Erp\Core\Security\UserContext;
use PDO;
use Throwable;

final class ContactOptionsController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function index(Request $request, array $params): Response
    {
        try {
            $pdo = Database::connection($this->config);
            $user = (new AuthService($pdo))->currentUser();
            if (!$user) {
                throw new AuthenticationRequiredException('Sign in to load Contact options.');
            }
            $authorization = new Authorization($pdo, $user);
            if (!$authorization->allows('contacts.read')) {
                throw new AccessDeniedException('You do not have permission to read Contacts.');
            }

            $companyId = $this->companyId($request, $user);
            $accountingAccess = $companyId === null
                ? $authorization->allowsForSharedRecords('accounting.read')
                : $authorization->allowsForCompany('accounting.read', $companyId);
            $commissionReadAccess = $this->allowsForCompanyOrShared($authorization, 'partner_commissions.read', $companyId);
            $commissionAssignAccess = $commissionReadAccess
                && $this->allowsForCompanyOrShared($authorization, 'partner_commissions.assign', $companyId);
            $commissionManageAccess = $commissionReadAccess
                && $this->allowsForCompanyOrShared($authorization, 'partner_commissions.manage', $companyId);
            return Response::json(['ok' => true, 'options' => [
                'companies' => $this->companies($pdo, $user),
                'contacts' => $this->contacts($pdo, $user),
                'users' => $this->users($pdo, $user, $companyId),
                'partner_levels' => $this->companyLookup($pdo, 'partner_levels', 'id, name', $companyId),
                'price_lists' => $this->companyLookup($pdo, 'price_lists', 'id, name, currency_code', $companyId),
                'payment_terms' => $this->companyLookup($pdo, 'payment_terms', 'id, name', $companyId),
                'payment_methods' => $this->companyLookup($pdo, 'payment_methods', 'id, name, code', $companyId),
                'delivery_methods' => $this->companyLookup($pdo, 'delivery_methods', 'id, name', $companyId),
                'currencies' => $this->simpleLookup($pdo, 'SELECT currency_code AS value, name, decimal_places, symbol FROM currencies WHERE active = 1 ORDER BY name'),
                'incoterms' => $this->simpleLookup($pdo, 'SELECT code AS value, name FROM incoterms WHERE active = 1 ORDER BY code'),
                'fiscal_positions' => $this->companyLookup($pdo, 'fiscal_positions', 'id, name', $companyId),
                'commission_plans' => $commissionReadAccess ? $this->companyLookup($pdo, 'commission_plans', 'id, name', $companyId) : [],
                'commission_read_access' => $commissionReadAccess,
                'commission_assign_access' => $commissionAssignAccess,
                'commission_manage_access' => $commissionManageAccess,
                'accounts' => $accountingAccess ? $this->accounts($pdo, $companyId) : [],
                'accounting_access' => $accountingAccess,
            ]]);
        } catch (AccessDeniedException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 403);
        } catch (AuthenticationRequiredException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 401);
        } catch (\InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Contact options failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Contact options could not be loaded.'], 500);
        }
    }

    public function create(Request $request, array $params): Response
    {
        try {
            $pdo = Database::connection($this->config);
            $user = (new AuthService($pdo))->currentUser();
            if (!$user) {
                throw new AuthenticationRequiredException('Sign in to load Contact creation options.');
            }
            $authorization = new Authorization($pdo, $user);
            if (!$authorization->allows('contact_master_data.create')) {
                throw new AccessDeniedException('You do not have Contact creation access.');
            }

            $companies = $this->companiesForPermission($pdo, $user, $authorization, 'contact_master_data.create');
            $companyId = $this->createCompanyId($request, $user, $authorization, $companies);
            $sharedContactAccess = $authorization->allowsForSharedRecords('contact_master_data.create');
            $canReadContacts = $companyId === null
                ? $authorization->allowsForSharedRecords('contacts.read')
                : $authorization->allowsForCompany('contacts.read', $companyId);
            $accountingAccess = $companyId === null
                ? $authorization->allowsForSharedRecords('accounting.read')
                : $authorization->allowsForCompany('accounting.read', $companyId);
            $commissionReadAccess = $this->allowsForCompanyOrShared($authorization, 'partner_commissions.read', $companyId);
            $commissionAssignAccess = $commissionReadAccess
                && $this->allowsForCompanyOrShared($authorization, 'partner_commissions.assign', $companyId);
            $commissionManageAccess = $commissionReadAccess
                && $this->allowsForCompanyOrShared($authorization, 'partner_commissions.manage', $companyId);

            $options = [
                'companies' => $companies,
                'company_id' => $companyId,
                'contacts' => $canReadContacts
                    ? ($companyId === null ? $this->sharedContacts($pdo) : $this->contactsForCompany($pdo, $companyId))
                    : [],
                'users' => $companyId === null ? [] : $this->users($pdo, $user, $companyId),
                'partner_levels' => $companyId === null ? [] : $this->companyLookup($pdo, 'partner_levels', 'id, name', $companyId),
                'price_lists' => $companyId === null ? [] : $this->companyLookup($pdo, 'price_lists', 'id, name, currency_code', $companyId),
                'payment_terms' => $companyId === null ? [] : $this->companyLookup($pdo, 'payment_terms', 'id, name', $companyId),
                'payment_methods' => $companyId === null ? [] : $this->companyLookup($pdo, 'payment_methods', 'id, name, code', $companyId),
                'delivery_methods' => $companyId === null ? [] : $this->companyLookup($pdo, 'delivery_methods', 'id, name', $companyId),
                'currencies' => $this->simpleLookup($pdo, 'SELECT currency_code AS value, name, decimal_places, symbol FROM currencies WHERE active = 1 ORDER BY name'),
                'incoterms' => $this->simpleLookup($pdo, 'SELECT code AS value, name FROM incoterms WHERE active = 1 ORDER BY code'),
                'fiscal_positions' => $companyId === null ? [] : $this->companyLookup($pdo, 'fiscal_positions', 'id, name', $companyId),
                'commission_plans' => $commissionReadAccess ? $this->companyLookup($pdo, 'commission_plans', 'id, name', $companyId) : [],
                'accounts' => $accountingAccess ? $this->accounts($pdo, $companyId) : [],
                'contact_create_access' => true,
                'shared_contact_access' => $sharedContactAccess,
                'contacts_read_access' => $canReadContacts,
                'accounting_access' => $accountingAccess,
                'commission_read_access' => $commissionReadAccess,
                'commission_assign_access' => $commissionAssignAccess,
                'commission_manage_access' => $commissionManageAccess,
            ];
            return Response::json(['ok' => true, 'options' => $options]);
        } catch (AccessDeniedException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 403);
        } catch (AuthenticationRequiredException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 401);
        } catch (\InvalidArgumentException $error) {
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Contact creation options failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Contact creation options could not be loaded.'], 500);
        }
    }

    private function companyId(Request $request, UserContext $user): ?int
    {
        $raw = $request->query()['company_id'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1 || (!$user->superuser && !in_array($id, $user->companyIds, true))) {
            throw new AccessDeniedException('The selected company is not available to this user.');
        }
        return $id;
    }

    private function createCompanyId(Request $request, UserContext $user, Authorization $authorization, array $companies): ?int
    {
        $raw = $request->query()['company_id'] ?? null;
        if ($raw !== null && $raw !== '') {
            $id = $this->companyId($request, $user);
            if (!$authorization->allowsForCompany('contact_master_data.create', (int)$id)) {
                throw new AccessDeniedException('You do not have Contact creation access for this company.');
            }
            return $id;
        }
        $companyIds = array_map(static fn (array $company): int => (int)$company['id'], $companies);
        if ($user->defaultCompanyId !== null && in_array($user->defaultCompanyId, $companyIds, true)) {
            return $user->defaultCompanyId;
        }
        return count($companyIds) === 1 ? $companyIds[0] : null;
    }

    private function companiesForPermission(PDO $pdo, UserContext $user, Authorization $authorization, string $permission): array
    {
        return array_values(array_filter(
            $this->companies($pdo, $user),
            static fn (array $company): bool => $authorization->allowsForCompany($permission, (int)$company['id'])
        ));
    }

    private function companies(PDO $pdo, UserContext $user): array
    {
        if ($user->superuser) {
            return $pdo->query('SELECT id, name FROM companies WHERE active = 1 ORDER BY name')->fetchAll();
        }
        if (!$user->companyIds) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($user->companyIds as $i => $companyId) {
            $placeholders[] = ':company_' . $i;
            $params['company_' . $i] = $companyId;
        }
        $query = $pdo->prepare('SELECT id, name FROM companies WHERE active = 1 AND id IN (' . implode(', ', $placeholders) . ') ORDER BY name');
        $query->execute($params);
        return $query->fetchAll();
    }

    private function contactsForCompany(PDO $pdo, int $companyId): array
    {
        $query = $pdo->prepare(
            'SELECT c.id, c.name, c.contact_type FROM contacts c ' .
            'WHERE c.active = 1 AND (c.company_id IS NULL OR c.company_id = :company_id) ORDER BY c.name, c.id LIMIT 500'
        );
        $query->execute(['company_id' => $companyId]);
        return $query->fetchAll();
    }

    private function contacts(PDO $pdo, UserContext $user): array
    {
        $where = ['c.active = 1'];
        $params = [];
        if (!$user->superuser) {
            if ($user->companyIds) {
                $ids = [];
                foreach ($user->companyIds as $index => $companyId) {
                    $ids[] = ':company_' . $index;
                    $params['company_' . $index] = $companyId;
                }
                $where[] = '(c.company_id IS NULL OR c.company_id IN (' . implode(', ', $ids) . '))';
            } else {
                $where[] = 'c.company_id IS NULL';
            }
        }
        $query = $pdo->prepare('SELECT c.id, c.name, c.contact_type FROM contacts c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.name, c.id LIMIT 500');
        $query->execute($params);
        return $query->fetchAll();
    }

    private function sharedContacts(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT c.id, c.name, c.contact_type FROM contacts c ' .
            'WHERE c.active = 1 AND c.company_id IS NULL ORDER BY c.name, c.id LIMIT 500'
        )->fetchAll();
    }

    private function users(PDO $pdo, UserContext $user, ?int $companyId): array
    {
        $where = ["u.status IN ('confirmed', 'active')"];
        $params = [];
        if ($companyId !== null) {
            $where[] = 'uca.company_id = :company_id';
            $params['company_id'] = $companyId;
        } elseif (!$user->superuser && $user->companyIds) {
            $ids = [];
            foreach ($user->companyIds as $index => $allowedCompanyId) {
                $ids[] = ':allowed_' . $index;
                $params['allowed_' . $index] = $allowedCompanyId;
            }
            $where[] = 'uca.company_id IN (' . implode(', ', $ids) . ')';
        } elseif (!$user->superuser) {
            $where[] = '(uca.company_id IS NULL OR u.is_superuser = 1)';
        }
        $query = $pdo->prepare(
            'SELECT DISTINCT u.id, c.name, u.login FROM users u JOIN contacts c ON c.id = u.contact_id ' .
            'LEFT JOIN user_company_access uca ON uca.user_id = u.id WHERE ' . implode(' AND ', $where) . ' ORDER BY c.name, u.id LIMIT 500'
        );
        $query->execute($params);
        return $query->fetchAll();
    }

    private function companyLookup(PDO $pdo, string $table, string $columns, ?int $companyId): array
    {
        $sql = 'SELECT ' . $columns . ' FROM ' . $table . ' WHERE active = 1';
        $params = [];
        if ($companyId === null) {
            $sql .= ' AND company_id IS NULL';
        } else {
            $sql .= ' AND (company_id IS NULL OR company_id = :company_id)';
            $params['company_id'] = $companyId;
        }
        $sql .= ' ORDER BY name LIMIT 1000';
        $query = $pdo->prepare($sql);
        $query->execute($params);
        return $query->fetchAll();
    }

    private function allowsForCompanyOrShared(Authorization $authorization, string $permission, ?int $companyId): bool
    {
        return $companyId === null
            ? $authorization->allowsForSharedRecords($permission)
            : $authorization->allowsForCompany($permission, $companyId);
    }

    private function simpleLookup(PDO $pdo, string $sql): array
    {
        return $pdo->query($sql)->fetchAll();
    }

    private function accounts(PDO $pdo, ?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }
        $query = $pdo->prepare('SELECT id, code, name FROM accounting_accounts WHERE active = 1 AND company_id = :company_id ORDER BY code LIMIT 2000');
        $query->execute(['company_id' => $companyId]);
        return $query->fetchAll();
    }
}
