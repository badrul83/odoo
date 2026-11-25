<?php
declare(strict_types=1);

namespace Erp\Core\Security;

use PDO;

final class Authorization
{
    /** @var array<string, bool> */
    private array $permissionCache = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserContext $user,
    ) {
    }

    public function allows(string $permissionKey): bool
    {
        if ($this->user->superuser) {
            return true;
        }
        if (array_key_exists($permissionKey, $this->permissionCache)) {
            return $this->permissionCache[$permissionKey];
        }

        $params = ['user_id' => $this->user->id, 'permission_key' => $permissionKey];
        if (!$this->user->companyIds) {
            $query = $this->pdo->prepare(
                'SELECT 1 FROM user_role_links ur ' .
                'JOIN access_roles r ON r.id = ur.role_id AND r.active = 1 AND r.company_id IS NULL ' .
                'JOIN role_permission_links rp ON rp.role_id = r.id ' .
                'JOIN access_permissions p ON p.id = rp.permission_id ' .
                'WHERE ur.user_id = :user_id AND p.permission_key = :permission_key LIMIT 1'
            );
            $params = ['user_id' => $this->user->id, 'permission_key' => $permissionKey];
        } else {
            foreach ($this->user->companyIds as $index => $companyId) {
                $params['company_' . $index] = $companyId;
            }
            $query = $this->pdo->prepare(
                'SELECT 1 FROM user_role_links ur ' .
                'JOIN access_roles r ON r.id = ur.role_id AND r.active = 1 ' .
                'JOIN role_permission_links rp ON rp.role_id = r.id ' .
                'JOIN access_permissions p ON p.id = rp.permission_id ' .
                'WHERE ur.user_id = :user_id AND p.permission_key = :permission_key ' .
                'AND (r.company_id IS NULL OR r.company_id IN (' . $this->companyPlaceholders() . ')) LIMIT 1'
            );
        }
        $query->execute($params);
        return $this->permissionCache[$permissionKey] = (bool)$query->fetchColumn();
    }

    public function allowsForCompany(string $permissionKey, int $companyId): bool
    {
        if ($companyId < 1 || !$this->canAccessCompany($companyId)) {
            return false;
        }
        if ($this->user->superuser) {
            return true;
        }
        $cacheKey = $companyId . ':' . $permissionKey;
        if (array_key_exists($cacheKey, $this->permissionCache)) {
            return $this->permissionCache[$cacheKey];
        }
        $query = $this->pdo->prepare(
            'SELECT 1 FROM user_role_links ur ' .
            'JOIN access_roles r ON r.id = ur.role_id AND r.active = 1 ' .
            'JOIN role_permission_links rp ON rp.role_id = r.id ' .
            'JOIN access_permissions p ON p.id = rp.permission_id ' .
            'WHERE ur.user_id = :user_id AND p.permission_key = :permission_key ' .
            'AND (r.company_id IS NULL OR r.company_id = :company_id) LIMIT 1'
        );
        $query->execute(['user_id' => $this->user->id, 'permission_key' => $permissionKey, 'company_id' => $companyId]);
        return $this->permissionCache[$cacheKey] = (bool)$query->fetchColumn();
    }

    public function allowsForSharedRecords(string $permissionKey): bool
    {
        if ($this->user->superuser) {
            return true;
        }
        $cacheKey = 'shared:' . $permissionKey;
        if (array_key_exists($cacheKey, $this->permissionCache)) {
            return $this->permissionCache[$cacheKey];
        }
        $query = $this->pdo->prepare(
            'SELECT 1 FROM user_role_links ur ' .
            'JOIN access_roles r ON r.id = ur.role_id AND r.active = 1 AND r.company_id IS NULL ' .
            'JOIN role_permission_links rp ON rp.role_id = r.id ' .
            'JOIN access_permissions p ON p.id = rp.permission_id ' .
            'WHERE ur.user_id = :user_id AND p.permission_key = :permission_key LIMIT 1'
        );
        $query->execute(['user_id' => $this->user->id, 'permission_key' => $permissionKey]);
        return $this->permissionCache[$cacheKey] = (bool)$query->fetchColumn();
    }

    public function canAccessCompany(?int $companyId): bool
    {
        return $companyId === null || $this->user->superuser || in_array($companyId, $this->user->companyIds, true);
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        if ($this->user->superuser) {
            $query = $this->pdo->query('SELECT permission_key FROM access_permissions ORDER BY permission_key');
            return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
        }
        $params = ['user_id' => $this->user->id];
        if (!$this->user->companyIds) {
            $companyClause = 'r.company_id IS NULL';
        } else {
            foreach ($this->user->companyIds as $index => $companyId) {
                $params['company_' . $index] = $companyId;
            }
            $companyClause = '(r.company_id IS NULL OR r.company_id IN (' . $this->companyPlaceholders() . '))';
        }
        $query = $this->pdo->prepare(
            'SELECT DISTINCT p.permission_key FROM user_role_links ur ' .
            'JOIN access_roles r ON r.id = ur.role_id AND r.active = 1 ' .
            'JOIN role_permission_links rp ON rp.role_id = r.id ' .
            'JOIN access_permissions p ON p.id = rp.permission_id ' .
            'WHERE ur.user_id = :user_id AND ' . $companyClause . ' ORDER BY p.permission_key'
        );
        $query->execute($params);
        return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    private function companyPlaceholders(): string
    {
        return implode(', ', array_map(static fn (int $index): string => ':company_' . $index, array_keys($this->user->companyIds)));
    }
}
