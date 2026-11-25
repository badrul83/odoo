<?php
declare(strict_types=1);

namespace Erp\Modules\Settings;

use DateTimeZone;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;
use Throwable;

final class CompanyRepository
{
    private const DOCUMENT_PREFIX_DEFAULTS = [
        'sales_order' => 'SO',
        'purchase_rfq' => 'RFQ',
        'purchase_order' => 'PO',
        'customer_invoice' => 'INV',
        'vendor_bill' => 'BILL',
    ];

    private const COMPANY_FIELDS = [
        'name', 'legal_name', 'tax_id', 'street', 'street2', 'city', 'state', 'postal_code', 'country_code',
        'phone', 'email', 'website', 'email_domain', 'currency_code', 'timezone', 'color', 'external_code',
        'x_account_url', 'facebook_account_url', 'github_account_url', 'linkedin_account_url', 'youtube_account_url',
    ];

    private const BRANCH_FIELDS = [
        'parent_branch_id', 'partner_contact_id', 'name', 'legal_name', 'code', 'external_code', 'email', 'email_domain', 'phone', 'tax_id', 'website', 'street', 'street2', 'city',
        'state', 'postal_code', 'country_code', 'currency_code', 'timezone', 'color', 'x_account_url', 'facebook_account_url', 'github_account_url', 'linkedin_account_url', 'youtube_account_url',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function documentPrefixes(int $companyId, UserContext $user): array
    {
        if (!$this->find($companyId, $user)) {
            throw new InvalidArgumentException('Company was not found or is outside the allowed companies.');
        }
        $prefixes = self::DOCUMENT_PREFIX_DEFAULTS;
        $query = $this->pdo->prepare('SELECT sequence_key, prefix FROM company_document_prefixes WHERE company_id = :company');
        $query->execute(['company' => $companyId]);
        foreach ($query->fetchAll() as $row) {
            if (array_key_exists($row['sequence_key'], $prefixes)) {
                $prefixes[$row['sequence_key']] = (string)$row['prefix'];
            }
        }
        return $prefixes;
    }

    public function saveDocumentPrefixes(int $companyId, array $input, UserContext $user, int $actorId, string $remoteAddress): array
    {
        if (!$this->find($companyId, $user)) {
            throw new InvalidArgumentException('Company was not found or is outside the allowed companies.');
        }
        $unknown = array_diff(array_keys($input), array_keys(self::DOCUMENT_PREFIX_DEFAULTS));
        $missing = array_diff(array_keys(self::DOCUMENT_PREFIX_DEFAULTS), array_keys($input));
        if ($unknown || $missing) {
            throw new InvalidArgumentException('Submit all five supported document prefixes.');
        }
        $prefixes = [];
        foreach (self::DOCUMENT_PREFIX_DEFAULTS as $key => $default) {
            $prefix = strtoupper(trim((string)$input[$key]));
            if (strlen($prefix) > 20 || ($prefix !== '' && preg_match('/^[A-Z0-9][A-Z0-9._\/-]*$/D', $prefix) !== 1)) {
                throw new InvalidArgumentException('Prefixes must be at most 20 letters, numbers, dots, underscores, slashes, or hyphens.');
            }
            $prefixes[$key] = $prefix;
        }
        $old = $this->documentPrefixes($companyId, $user);
        $this->pdo->beginTransaction();
        try {
            $save = $this->pdo->prepare(
                'INSERT INTO company_document_prefixes (company_id, sequence_key, prefix, updated_by) ' .
                'VALUES (:company, :sequence_key, :prefix, :actor) ' .
                'ON DUPLICATE KEY UPDATE prefix = VALUES(prefix), updated_by = VALUES(updated_by)'
            );
            foreach ($prefixes as $key => $prefix) {
                $save->execute(['company' => $companyId, 'sequence_key' => $key, 'prefix' => $prefix, 'actor' => $actorId]);
            }
            $this->writeAudit($companyId, $actorId, 'company.document_prefix.update', $companyId, $old, $prefixes, $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $prefixes;
    }

    public function search(UserContext $user): array
    {
        [$where, $params] = $this->companyScope($user, 'c');
        $sql = 'SELECT c.id, c.name, c.legal_name, c.email, c.phone, c.currency_code, c.timezone, c.active, c.contact_id FROM companies c';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY c.name, c.id';
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $records = $query->fetchAll();
        return ['records' => $records, 'total' => count($records)];
    }

    public function find(int $id, UserContext $user): ?array
    {
        [$where, $params] = $this->companyScope($user, 'c');
        $conditions = ['c.id = :id'];
        if ($where !== '') {
            $conditions[] = $where;
        }
        $query = $this->pdo->prepare('SELECT c.* FROM companies c WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1');
        $query->execute(['id' => $id] + $params);
        $company = $query->fetch();
        if (!$company) {
            return null;
        }

        $contact = $this->pdo->prepare(
            'SELECT id, name, email, phone, mobile, website, tax_id, street, street2, city, state, postal_code, country_code ' .
            'FROM contacts WHERE id = :id LIMIT 1'
        );
        $contact->execute(['id' => $company['contact_id']]);
        $company['contact'] = $contact->fetch() ?: null;
        $company['logo_url'] = !empty($company['logo_path']) ? '/api/v1/companies/' . $id . '/logo' : null;
        $branches = $this->pdo->prepare(
            'SELECT b.*, p.name AS partner_name FROM company_branches b ' .
            'LEFT JOIN contacts p ON p.id = b.partner_contact_id ' .
            'WHERE b.company_id = :company_id AND b.active = 1 ORDER BY b.name, b.id'
        );
        $branches->execute(['company_id' => $id]);
        $company['branches'] = $branches->fetchAll();
        foreach ($company['branches'] as &$branch) {
            $branch['logo_url'] = !empty($branch['logo_path']) ? '/api/v1/companies/' . $id . '/branches/' . (int)$branch['id'] . '/logo' : null;
        }
        unset($branch);
        return $company;
    }

    public function setCompanyLogo(int $companyId, ?string $path, UserContext $user, int $actorId, string $remoteAddress): array
    {
        $current = $this->find($companyId, $user);
        if (!$current) throw new InvalidArgumentException('Company was not found or is outside the allowed companies.');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE companies SET logo_path = :path WHERE id = :id')->execute(['path' => $path, 'id' => $companyId]);
            $this->writeAudit($companyId, $actorId, 'company.logo.update', $companyId, ['logo_path' => $current['logo_path']], ['logo_path' => $path], $remoteAddress);
            $saved = $this->find($companyId, $user) ?? throw new InvalidArgumentException('Company could not be reloaded after its logo was saved.');
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $saved;
    }

    public function setBranchLogo(int $companyId, int $branchId, ?string $path, UserContext $user, int $actorId, string $remoteAddress): array
    {
        if (!$this->find($companyId, $user)) throw new InvalidArgumentException('Company was not found or is outside the allowed companies.');
        $current = $this->findBranch($companyId, $branchId);
        if (!$current) throw new InvalidArgumentException('Branch was not found in this company.');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE company_branches SET logo_path = :path WHERE id = :id AND company_id = :company')->execute(['path' => $path, 'id' => $branchId, 'company' => $companyId]);
            $this->writeAudit($companyId, $actorId, 'company.branch.logo.update', $branchId, ['logo_path' => $current['logo_path']], ['logo_path' => $path], $remoteAddress);
            $saved = $this->findBranch($companyId, $branchId) ?? throw new InvalidArgumentException('Branch could not be reloaded after its logo was saved.');
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $saved;
    }

    public function save(?int $id, array $input, UserContext $user, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), self::COMPANY_FIELDS);
        if ($unknown) {
            throw new InvalidArgumentException('Unsupported company fields: ' . implode(', ', $unknown));
        }
        $values = $this->normalizeCompany($input);
        if ($id === null && (!isset($values['name']) || $values['name'] === null)) {
            throw new InvalidArgumentException('Company name is required.');
        }
        $current = $id === null ? null : $this->find($id, $user);
        if ($id !== null && !$current) {
            throw new InvalidArgumentException('Company was not found or is outside the allowed companies.');
        }
        $this->validateCurrency($values['currency_code'] ?? null, $current['currency_code'] ?? null);

        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $insertValues = $values + ['currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka'];
                $columns = array_keys($insertValues);
                $statement = $this->pdo->prepare(
                    'INSERT INTO companies (' . implode(', ', $columns) . ') VALUES (' .
                    implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
                );
                $statement->execute($insertValues);
                $id = (int)$this->pdo->lastInsertId();
                $contactId = $this->createCompanyContact($id, $insertValues);
                $this->pdo->prepare('UPDATE companies SET contact_id = :contact_id WHERE id = :id')
                    ->execute(['contact_id' => $contactId, 'id' => $id]);
                if (!$user->superuser) {
                    $accessRows = $this->pdo->prepare('SELECT COUNT(*) FROM user_company_access WHERE user_id = :id');
                    $accessRows->execute(['id' => $user->id]);
                    $isFirstCompany = (int)$accessRows->fetchColumn() === 0;
                    $this->pdo->prepare(
                        'INSERT INTO user_company_access (user_id, company_id, default_company) VALUES (:user, :company, :is_default)'
                    )->execute(['user' => $user->id, 'company' => $id, 'is_default' => $isFirstCompany ? 1 : 0]);
                }
                $event = 'create';
                $old = null;
            } else {
                if ($values) {
                    $assignments = array_map(static fn (string $field): string => $field . ' = :' . $field, array_keys($values));
                    $values['id'] = $id;
                    $this->pdo->prepare('UPDATE companies SET ' . implode(', ', $assignments) . ' WHERE id = :id')->execute($values);
                    $contactId = $current['contact_id'] === null ? null : (int)$current['contact_id'];
                    if ($contactId === null) {
                        $contactValues = array_replace($current, $values);
                        $contactId = $this->createCompanyContact($id, $contactValues);
                        $this->pdo->prepare('UPDATE companies SET contact_id = :contact_id WHERE id = :id')
                            ->execute(['contact_id' => $contactId, 'id' => $id]);
                    } else {
                        $this->syncCompanyContact($contactId, $values);
                    }
                }
                $event = 'update';
                $old = $this->auditValues($current);
            }
            $new = $this->auditValues($values);
            $this->writeAudit($id, $actorId, 'company.' . $event, $id, $old, $new, $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        $company = $this->find($id, $user);
        if (!$company) {
            throw new InvalidArgumentException('Saved company could not be reloaded.');
        }
        return $company;
    }

    public function createBranch(int $companyId, array $input, UserContext $user, int $actorId, string $remoteAddress): array
    {
        $this->assertCompanyAvailable($companyId, $user);
        $values = $this->normalizeBranch($input, true);
        $this->validateBranchLinks($companyId, $values);
        $company = $this->find($companyId, $user);
        if (!$company) {
            throw new InvalidArgumentException('Company was not found or is outside the allowed companies.');
        }

        $this->pdo->beginTransaction();
        try {
            $insertValues = $values + ['company_id' => $companyId];
            $columns = array_keys($insertValues);
            $statement = $this->pdo->prepare(
                'INSERT INTO company_branches (' . implode(', ', $columns) . ') VALUES (' .
                implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
            );
            $statement->execute($insertValues);
            $branchId = (int)$this->pdo->lastInsertId();

            $partnerId = $values['partner_contact_id'] ?? null;
            if ($partnerId === null) {
                $partnerId = $this->createBranchContact($companyId, (int)($company['contact_id'] ?? 0), $values);
                $this->pdo->prepare('UPDATE company_branches SET partner_contact_id = :partner WHERE id = :id')
                    ->execute(['partner' => $partnerId, 'id' => $branchId]);
            }
            $this->writeAudit($companyId, $actorId, 'company.branch.create', $branchId, null, $values, $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->findBranch($companyId, $branchId);
    }

    public function updateBranch(int $companyId, int $branchId, array $input, UserContext $user, int $actorId, string $remoteAddress): array
    {
        $this->assertCompanyAvailable($companyId, $user);
        $current = $this->findBranch($companyId, $branchId);
        if (!$current) {
            throw new InvalidArgumentException('Branch was not found in this company.');
        }
        $unknown = array_diff(array_keys($input), self::BRANCH_FIELDS);
        if ($unknown) {
            throw new InvalidArgumentException('Unsupported branch fields: ' . implode(', ', $unknown));
        }
        $values = $this->normalizeBranch($input, false);
        $this->validateBranchLinks($companyId, $values);
        if (($values['parent_branch_id'] ?? null) === $branchId) {
            throw new InvalidArgumentException('A branch cannot be its own parent.');
        }
        if (!empty($values['parent_branch_id'])) $this->assertNoBranchCycle($companyId, $branchId, (int)$values['parent_branch_id']);
        $this->pdo->beginTransaction();
        try {
            if ($values) {
                $assignments = array_map(static fn (string $field): string => $field . ' = :' . $field, array_keys($values));
                $values['id'] = $branchId;
                $values['company_id'] = $companyId;
                $this->pdo->prepare(
                    'UPDATE company_branches SET ' . implode(', ', $assignments) . ' WHERE id = :id AND company_id = :company_id'
                )->execute($values);
                $partnerId = array_key_exists('partner_contact_id', $values)
                    ? $values['partner_contact_id']
                    : ($current['partner_contact_id'] === null ? null : (int)$current['partner_contact_id']);
                if ($partnerId !== null) {
                    $this->syncBranchContact($partnerId, $values);
                }
            }
            $this->writeAudit($companyId, $actorId, 'company.branch.update', $branchId, $this->auditValues($current), $this->auditValues($values), $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->findBranch($companyId, $branchId);
    }

    public function archiveBranch(int $companyId, int $branchId, UserContext $user, int $actorId, string $remoteAddress): void
    {
        $this->assertCompanyAvailable($companyId, $user);
        $current = $this->findBranch($companyId, $branchId);
        if (!$current) {
            throw new InvalidArgumentException('Branch was not found in this company.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE company_branches SET active = 0 WHERE id = :id AND company_id = :company_id')
                ->execute(['id' => $branchId, 'company_id' => $companyId]);
            $this->writeAudit($companyId, $actorId, 'company.branch.archive', $branchId, $this->auditValues($current), ['active' => 0], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function normalizeCompany(array $input): array
    {
        $values = [];
        foreach (self::COMPANY_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($field === 'color') {
                $color = filter_var($input[$field], FILTER_VALIDATE_INT);
                if ($color === false || $color < 0 || $color > 255) {
                    throw new InvalidArgumentException('Company color must be between 0 and 255.');
                }
                $values[$field] = $color;
                continue;
            }
            if ($field === 'country_code') {
                $values[$field] = $this->countryCode($input[$field]);
                continue;
            }
            $limit = match ($field) {
                'name', 'legal_name', 'email', 'street', 'street2' => 190,
                'external_code' => 100,
                'tax_id' => 80,
                'email_domain' => 190,
                'website', 'x_account_url', 'facebook_account_url', 'github_account_url', 'linkedin_account_url', 'youtube_account_url' => 255,
                'phone' => 80,
                'city', 'state' => 120,
                'postal_code' => 40,
                'currency_code' => 3,
                'timezone' => 80,
                default => 190,
            };
            $text = $this->nullableText($field, $input[$field], $limit);
            if ($field === 'currency_code' && $text !== null) {
                $text = strtoupper($text);
            } elseif ($field === 'timezone' && $text !== null && !in_array($text, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                throw new InvalidArgumentException('Timezone is not a recognized IANA timezone.');
            } elseif ($field === 'email' && $text !== null && !filter_var($text, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Company email address is invalid.');
            } elseif ($field === 'email_domain' && $text !== null) {
                $text = strtolower(ltrim($text, '@'));
                if (!filter_var('company@' . $text, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Company email domain is invalid.');
                }
            } elseif (in_array($field, ['website', 'x_account_url', 'facebook_account_url', 'github_account_url', 'linkedin_account_url', 'youtube_account_url'], true) && $text !== null) {
                $scheme = strtolower((string)parse_url($text, PHP_URL_SCHEME));
                if (!filter_var($text, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                    throw new InvalidArgumentException(ucwords(str_replace('_', ' ', $field)) . ' must be a valid http or https URL.');
                }
            }
            $values[$field] = $text;
        }
        if (array_key_exists('name', $values) && $values['name'] === null) {
            throw new InvalidArgumentException('Company name cannot be empty.');
        }
        foreach (['currency_code', 'timezone'] as $requiredField) {
            if (array_key_exists($requiredField, $values) && $values[$requiredField] === null) {
                throw new InvalidArgumentException('Company ' . str_replace('_', ' ', $requiredField) . ' cannot be empty.');
            }
        }
        return $values;
    }

    private function normalizeBranch(array $input, bool $creating): array
    {
        $unknown = array_diff(array_keys($input), self::BRANCH_FIELDS);
        if ($unknown) {
            throw new InvalidArgumentException('Unsupported branch fields: ' . implode(', ', $unknown));
        }
        $values = [];
        foreach (self::BRANCH_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($field === 'parent_branch_id' || $field === 'partner_contact_id') {
                $values[$field] = $this->nullableId($field, $input[$field]);
                continue;
            }
            if ($field === 'country_code') {
                $values[$field] = $this->countryCode($input[$field]);
                continue;
            }
            if ($field === 'color') {
                $color = filter_var($input[$field], FILTER_VALIDATE_INT);
                if ($color === false || $color < 0 || $color > 255) throw new InvalidArgumentException('Branch color must be between 0 and 255.');
                $values[$field] = $color;
                continue;
            }
            $limit = match ($field) {
                'name', 'legal_name' => 190,
                'code' => 40,
                'external_code' => 100,
                'email', 'street', 'street2' => 190,
                'tax_id' => 80,
                'email_domain' => 190,
                'website', 'x_account_url', 'facebook_account_url', 'github_account_url', 'linkedin_account_url', 'youtube_account_url' => 255,
                'phone' => 80,
                'city', 'state' => 120,
                'postal_code' => 40,
                'currency_code' => 3,
                'timezone' => 80,
                default => 190,
            };
            $text = $this->nullableText($field, $input[$field], $limit);
            if ($field === 'currency_code' && $text !== null) {
                $text = strtoupper($text);
            } elseif ($field === 'email' && $text !== null && !filter_var($text, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Branch email address is invalid.');
            } elseif ($field === 'timezone' && $text !== null && !in_array($text, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                throw new InvalidArgumentException('Branch timezone is not a recognized IANA timezone.');
            } elseif ($field === 'email_domain' && $text !== null) {
                $text = strtolower(ltrim($text, '@'));
                if (!filter_var('branch@' . $text, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Branch email domain is invalid.');
            } elseif (in_array($field, ['website', 'x_account_url', 'facebook_account_url', 'github_account_url', 'linkedin_account_url', 'youtube_account_url'], true) && $text !== null) {
                $scheme = strtolower((string)parse_url($text, PHP_URL_SCHEME));
                if (!filter_var($text, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                    throw new InvalidArgumentException(ucwords(str_replace('_', ' ', $field)) . ' must be a valid http or https URL.');
                }
            }
            $values[$field] = $text;
        }
        if ($creating && (!isset($values['name']) || $values['name'] === null)) {
            throw new InvalidArgumentException('Branch name is required.');
        }
        return $values;
    }

    private function validateBranchLinks(int $companyId, array $values): void
    {
        if (!empty($values['parent_branch_id'])) {
            $parent = $this->pdo->prepare('SELECT 1 FROM company_branches WHERE id = :id AND company_id = :company AND active = 1 LIMIT 1');
            $parent->execute(['id' => $values['parent_branch_id'], 'company' => $companyId]);
            if (!$parent->fetchColumn()) {
                throw new InvalidArgumentException('Parent branch must be active in the same company.');
            }
        }
        if (!empty($values['partner_contact_id'])) {
            $contact = $this->pdo->prepare('SELECT company_id FROM contacts WHERE id = :id LIMIT 1');
            $contact->execute(['id' => $values['partner_contact_id']]);
            $contactCompany = $contact->fetchColumn();
            if ($contactCompany === false || ($contactCompany !== null && (int)$contactCompany !== $companyId)) {
                throw new InvalidArgumentException('Branch contact must be shared or belong to the same company.');
            }
        }
        if (array_key_exists('currency_code', $values) && $values['currency_code'] !== null) {
            $this->validateCurrency((string)$values['currency_code'], null);
        }
    }

    private function assertNoBranchCycle(int $companyId, int $branchId, int $parentId): void
    {
        $visited = [];
        while ($parentId > 0) {
            if ($parentId === $branchId || isset($visited[$parentId])) {
                throw new InvalidArgumentException('Branch hierarchy cannot contain a cycle.');
            }
            $visited[$parentId] = true;
            $query = $this->pdo->prepare('SELECT parent_branch_id FROM company_branches WHERE id = :id AND company_id = :company AND active = 1 LIMIT 1');
            $query->execute(['id' => $parentId, 'company' => $companyId]);
            $next = $query->fetchColumn();
            $parentId = $next === false || $next === null ? 0 : (int)$next;
        }
    }

    private function createCompanyContact(int $companyId, array $values): int
    {
        $contactValues = $this->companyContactValues($values);
        $columns = array_merge(['company_id', 'contact_type'], array_keys($contactValues));
        $params = ['company_id' => $companyId, 'contact_type' => 'company'] + $contactValues;
        $this->pdo->prepare(
            'INSERT INTO contacts (' . implode(', ', $columns) . ') VALUES (' .
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        )->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    private function createBranchContact(int $companyId, int $parentContactId, array $values): int
    {
        $contactValues = $this->branchContactValues($values);
        $columns = array_merge(['company_id', 'parent_contact_id', 'contact_type'], array_keys($contactValues));
        $params = ['company_id' => $companyId, 'parent_contact_id' => $parentContactId > 0 ? $parentContactId : null, 'contact_type' => 'company'] + $contactValues;
        $this->pdo->prepare(
            'INSERT INTO contacts (' . implode(', ', $columns) . ') VALUES (' .
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        )->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    private function syncCompanyContact(int $contactId, array $values): void
    {
        if ($contactId < 1) {
            return;
        }
        $contactValues = $this->companyContactValues($values);
        if (!$contactValues) {
            return;
        }
        $assignments = array_map(static fn (string $field): string => $field . ' = :' . $field, array_keys($contactValues));
        $contactValues['id'] = $contactId;
        $this->pdo->prepare('UPDATE contacts SET ' . implode(', ', $assignments) . ' WHERE id = :id')->execute($contactValues);
    }

    private function syncBranchContact(int $contactId, array $values): void
    {
        if ($contactId < 1) {
            return;
        }
        $contactValues = $this->branchContactValues($values);
        if (!$contactValues) {
            return;
        }
        $assignments = array_map(static fn (string $field): string => $field . ' = :' . $field, array_keys($contactValues));
        $contactValues['id'] = $contactId;
        $this->pdo->prepare('UPDATE contacts SET ' . implode(', ', $assignments) . ' WHERE id = :id')->execute($contactValues);
    }

    private function companyContactValues(array $values): array
    {
        $mapping = [
            'name' => 'name', 'tax_id' => 'tax_id', 'email' => 'email', 'phone' => 'phone', 'website' => 'website',
            'street' => 'street', 'street2' => 'street2', 'city' => 'city', 'state' => 'state',
            'postal_code' => 'postal_code', 'country_code' => 'country_code',
        ];
        $result = [];
        foreach ($mapping as $source => $target) {
            if (array_key_exists($source, $values)) {
                $result[$target] = $values[$source];
            }
        }
        return $result;
    }

    private function branchContactValues(array $values): array
    {
        $result = $this->companyContactValues($values);
        if (array_key_exists('code', $values)) {
            $result['reference'] = $values['code'];
        }
        if (array_key_exists('external_code', $values)) {
            $result['reference'] = $values['external_code'];
        }
        return $result;
    }

    private function findBranch(int $companyId, int $branchId): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT b.*, p.name AS partner_name FROM company_branches b LEFT JOIN contacts p ON p.id = b.partner_contact_id ' .
            'WHERE b.company_id = :company_id AND b.id = :id AND b.active = 1 LIMIT 1'
        );
        $query->execute(['company_id' => $companyId, 'id' => $branchId]);
        $branch = $query->fetch();
        if ($branch && !empty($branch['logo_path'])) $branch['logo_url'] = '/api/v1/companies/' . $companyId . '/branches/' . $branchId . '/logo';
        return $branch ?: null;
    }

    private function assertCompanyAvailable(int $companyId, UserContext $user): void
    {
        if ($this->find($companyId, $user) === null) {
            throw new InvalidArgumentException('Company was not found or is outside the allowed companies.');
        }
    }

    private function companyScope(UserContext $user, string $alias): array
    {
        if ($user->superuser) {
            return ['', []];
        }
        if (!$user->companyIds) {
            return ['1 = 0', []];
        }
        $placeholders = [];
        $params = [];
        foreach ($user->companyIds as $index => $companyId) {
            $key = 'company_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $companyId;
        }
        return [$alias . '.id IN (' . implode(', ', $placeholders) . ')', $params];
    }

    private function validateCurrency(?string $requested, ?string $current): void
    {
        $code = $requested ?? $current ?? 'BDT';
        $query = $this->pdo->prepare('SELECT 1 FROM currencies WHERE currency_code = :code AND active = 1 LIMIT 1');
        $query->execute(['code' => $code]);
        if (!$query->fetchColumn()) {
            throw new InvalidArgumentException('Company currency is not configured.');
        }
    }

    private function nullableText(string $field, mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException($field . ' must be text.');
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : (preg_match_all('/./us', $text, $characters) ?: strlen($text));
        if ($length > $maxLength) {
            throw new InvalidArgumentException($field . ' must be ' . $maxLength . ' characters or fewer.');
        }
        return $text;
    }

    private function countryCode(mixed $value): ?string
    {
        $code = $this->nullableText('country code', $value, 2);
        if ($code === null) {
            return null;
        }
        $code = strtoupper($code);
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new InvalidArgumentException('Country code must be a two-letter ISO code.');
        }
        return $code;
    }

    private function nullableId(string $field, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException($field . ' must be a positive record ID.');
        }
        return $id;
    }

    private function auditValues(array $values): array
    {
        unset($values['branches'], $values['contact'], $values['contact_id'], $values['id'], $values['created_at'], $values['updated_at']);
        return $values;
    }

    private function writeAudit(int $companyId, int $actorId, string $eventKey, int $recordId, ?array $oldValues, array $newValues, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $requestId = sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events (company_id, user_id, event_key, model_key, record_id, old_values, new_values, request_id, ip_address) ' .
            'VALUES (:company_id, :user_id, :event_key, :model_key, :record_id, :old_values, :new_values, :request_id, :ip_address)'
        );
        $statement->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $actorId, PDO::PARAM_INT);
        $statement->bindValue(':event_key', $eventKey);
        $statement->bindValue(':model_key', str_starts_with($eventKey, 'company.branch.') ? 'company_branches' : 'companies');
        $statement->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $statement->bindValue(':old_values', $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $oldValues === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':new_values', json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $statement->bindValue(':request_id', $requestId);
        $statement->bindValue(':ip_address', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $statement->execute();
    }
}
