<?php
declare(strict_types=1);

namespace Erp\Modules\Export;

use Erp\Core\Config;
use Erp\Core\Database;
use Erp\Core\Http\Request;
use Erp\Core\Http\Response;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\AuthenticationRequiredException;
use Erp\Core\Security\AuthService;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\DataCipher;
use Erp\Core\Security\UserContext;
use Erp\Modules\Inventory\ProductCsv;
use InvalidArgumentException;
use PDO;
use Throwable;

final class MasterDataExportController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function download(Request $request, array $params): Response
    {
        @set_time_limit(0);
        $stream = null;
        try {
            $pdo = Database::connection($this->config);
            $user = (new AuthService($pdo))->currentUser();
            if (!$user) {
                throw new AuthenticationRequiredException('Sign in to export master data.');
            }
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $authorization = new Authorization($pdo, $user);
            $filters = $request->query();
            $resource = $this->scalarString($filters['resource'] ?? '', 'Export resource');
            if (!in_array($resource, ['contacts', 'products'], true)) {
                throw new InvalidArgumentException('Choose Contacts or Products for export.');
            }
            $allowedFilters = $resource === 'contacts'
                ? ['resource', 'company_id', 'q', 'role']
                : ['resource', 'company_id', 'q', 'status'];
            $unknown = array_diff(array_keys($filters), $allowedFilters);
            if ($unknown) {
                throw new InvalidArgumentException('Unsupported export filters: ' . implode(', ', $unknown));
            }

            $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
            if (!is_resource($stream)) {
                throw new \RuntimeException('The export file could not be prepared.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            if ($resource === 'contacts') {
                $filename = $this->exportContacts($stream, $pdo, $user, $authorization, $filters);
            } else {
                $filename = $this->exportProducts($stream, $pdo, $user, $authorization, $filters);
            }
            return Response::stream($stream, 'text/csv; charset=utf-8', 200, [
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (AccessDeniedException $error) {
            if (is_resource($stream)) fclose($stream);
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 403);
        } catch (AuthenticationRequiredException $error) {
            if (is_resource($stream)) fclose($stream);
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 401);
        } catch (InvalidArgumentException $error) {
            if (is_resource($stream)) fclose($stream);
            return Response::json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            if (is_resource($stream)) fclose($stream);
            error_log('Master data export failed: ' . $error->getMessage());
            return Response::json(['ok' => false, 'error' => 'Master data could not be exported.'], 500);
        }
    }

    /** @param resource $stream @param array<string, mixed> $filters */
    private function exportContacts($stream, PDO $pdo, UserContext $user, Authorization $authorization, array $filters): string
    {
        $scope = $this->companyScope($user, $authorization, 'contacts.read', $filters['company_id'] ?? null, true);
        $where = $scope['where'];
        $params = $scope['params'];
        $role = $this->scalarString($filters['role'] ?? 'all', 'Contact type filter');
        if (!in_array($role, ['all', 'customer', 'supplier', 'employee'], true)) {
            throw new InvalidArgumentException('Contact type filter must be customer, supplier, employee, or all.');
        }
        if ($role !== 'all') $where[] = 'c.' . $role . ' = 1';
        $query = $this->searchText($filters['q'] ?? '', 'Contact');
        if ($query !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            $where[] = '(c.name LIKE :q_name ESCAPE \'!\' OR c.email LIKE :q_email ESCAPE \'!\' OR c.phone LIKE :q_phone ESCAPE \'!\' OR c.tax_id LIKE :q_tax ESCAPE \'!\' OR c.reference LIKE :q_reference ESCAPE \'!\')';
            $params += ['q_name' => $like, 'q_email' => $like, 'q_phone' => $like, 'q_tax' => $like, 'q_reference' => $like];
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $fields = $this->tableFields($pdo, 'contacts');
        $extra = ['addresses_json', 'tags_json', 'partner_reviews_json', 'bank_accounts_json', 'ubo_owners_json'];
        $this->writeRow($stream, array_merge($fields, $extra));
        $maxQuery = $pdo->prepare('SELECT MAX(c.id) FROM contacts c' . $whereSql);
        $maxQuery->execute($params);
        $maxId = (int)($maxQuery->fetchColumn() ?: 0);
        $after = 0;
        $list = $pdo->prepare('SELECT c.* FROM contacts c' . $whereSql . ' AND c.id > :after_id AND c.id <= :max_id ORDER BY c.id LIMIT 500');
        $cipher = new DataCipher($this->config->dataEncryptionKey());
        while ($after < $maxId) {
            $batchParams = $params + ['after_id' => $after, 'max_id' => $maxId];
            $list->execute($batchParams);
            $rows = $list->fetchAll();
            if (!$rows) break;
            foreach ($rows as $row) {
                $after = (int)$row['id'];
                $companyId = $row['company_id'] === null ? null : (int)$row['company_id'];
                if (!$this->hasPermission($authorization, 'partner_commissions.read', $companyId)) {
                    $row['commission_plan_id'] = null;
                }
                if (!$this->hasPermission($authorization, 'accounting.read', $companyId)) {
                    $row['receivable_account_id'] = null;
                    $row['payable_account_id'] = null;
                }
                $related = $this->contactRelatedData($pdo, (int)$row['id'], $authorization, $companyId, $cipher);
                $values = [];
                foreach ($fields as $field) $values[] = $row[$field] ?? null;
                foreach ($extra as $field) $values[] = $related[$field] ?? null;
                $this->writeRow($stream, $values);
            }
        }
        return 'contacts-' . gmdate('Ymd-His') . '.csv';
    }

    /** @param resource $stream @param array<string, mixed> $filters */
    private function exportProducts($stream, PDO $pdo, UserContext $user, Authorization $authorization, array $filters): string
    {
        $scope = $this->companyScope($user, $authorization, 'inventory.read', $filters['company_id'] ?? null, false);
        $where = $scope['where'];
        $params = $scope['params'];
        $status = $this->scalarString($filters['status'] ?? 'active', 'Product status filter');
        if ($status === 'active') $where[] = 'p.active = 1';
        elseif ($status === 'archived') $where[] = 'p.active = 0';
        elseif ($status !== 'all') throw new InvalidArgumentException('Product status filter must be active, archived, or all.');
        $query = $this->searchText($filters['q'] ?? '', 'Product');
        if ($query !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            $where[] = '(p.name LIKE :q_name ESCAPE \'!\' OR p.internal_reference LIKE :q_reference ESCAPE \'!\' OR p.barcode LIKE :q_barcode ESCAPE \'!\')';
            $params += ['q_name' => $like, 'q_reference' => $like, 'q_barcode' => $like];
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $includeAccounts = $authorization->allowsForCompany('accounting.read', (int)$scope['company_id']);
        $this->writeRow($stream, ProductCsv::headers($includeAccounts));
        $maxQuery = $pdo->prepare('SELECT MAX(p.id) FROM products p' . $whereSql);
        $maxQuery->execute($params);
        $maxId = (int)($maxQuery->fetchColumn() ?: 0);
        $after = 0;
        $list = $pdo->prepare(
            'SELECT p.id,p.name,p.internal_reference,p.barcode,p.product_type,p.product_class,p.carton_size,p.units_per_carton,p.carton_price,p.tracking,p.invoice_policy,p.list_price,p.cost_price,p.sale_ok,p.purchase_ok,p.active,p.weight,p.volume,p.description,' .
            'category.name AS category_name,CONCAT(sales_uom.name,\' (\',sales_uom.abbreviation,\') · \',sales_uom_category.name) AS sales_unit,' .
            'CONCAT(purchase_uom.name,\' (\',purchase_uom.abbreviation,\') · \',purchase_uom_category.name) AS purchase_unit,' .
            'income_account.code AS income_account_code,purchase_account.code AS purchase_account_code ' .
            'FROM products p JOIN product_categories category ON category.id=p.category_id ' .
            'JOIN units_of_measure sales_uom ON sales_uom.id=p.uom_id JOIN uom_categories sales_uom_category ON sales_uom_category.id=sales_uom.category_id ' .
            'JOIN units_of_measure purchase_uom ON purchase_uom.id=p.purchase_uom_id JOIN uom_categories purchase_uom_category ON purchase_uom_category.id=purchase_uom.category_id ' .
            'LEFT JOIN accounting_accounts income_account ON income_account.id=p.income_account_id AND income_account.company_id=p.company_id ' .
            'LEFT JOIN accounting_accounts purchase_account ON purchase_account.id=p.purchase_account_id AND purchase_account.company_id=p.company_id' .
            $whereSql . ' AND p.id > :after_id AND p.id <= :max_id ORDER BY p.id LIMIT 500'
        );
        while ($after < $maxId) {
            $list->execute($params + ['after_id' => $after, 'max_id' => $maxId]);
            $rows = $list->fetchAll();
            if (!$rows) break;
            foreach ($rows as $row) {
                $after = (int)$row['id'];
                $values = [
                    $row['id'], $row['name'], $row['internal_reference'], $row['barcode'], $row['category_name'],
                    match ((string)$row['product_class']) { 'cp' => 'CP', 'non_cp' => 'Non-CP', default => 'Unclassified' },
                    match ((string)$row['product_type']) { 'service' => 'Service', 'combo' => 'Combo', default => 'Goods' },
                    $row['sales_unit'], $row['purchase_unit'], $row['carton_size'], $row['units_per_carton'],
                    match ((string)$row['tracking']) { 'lot' => 'By Lots', 'serial' => 'By Unique Serial Number', default => 'By Quantity' },
                    (string)$row['invoice_policy'] === 'delivered' ? 'Delivered quantities' : 'Ordered quantities',
                    $row['list_price'], $row['carton_price'], $row['cost_price'], (int)$row['sale_ok'] ? 'Yes' : 'No', (int)$row['purchase_ok'] ? 'Yes' : 'No',
                    (int)$row['active'] ? 'Yes' : 'No', $row['weight'], $row['volume'], $row['description'],
                ];
                if ($includeAccounts) {
                    $values[] = $row['income_account_code'];
                    $values[] = $row['purchase_account_code'];
                }
                $this->writeRow($stream, $values);
            }
        }
        return 'products-' . gmdate('Ymd-His') . '.csv';
    }

    /** @return array{where: list<string>, params: array<string, mixed>, company_id: ?int} */
    private function companyScope(UserContext $user, Authorization $authorization, string $readPermission, mixed $rawCompanyId, bool $allowShared): array
    {
        $where = [];
        $params = [];
        $companyId = null;
        if ($rawCompanyId !== null && $rawCompanyId !== '') {
            $companyId = filter_var($rawCompanyId, FILTER_VALIDATE_INT);
            if ($companyId === false || $companyId < 1 || !$authorization->allowsForCompany($readPermission, (int)$companyId)
                || !$authorization->allowsForCompany('data_export.allowed', (int)$companyId)) {
                throw new AccessDeniedException('You do not have export and read access for the selected company.');
            }
            $where[] = 'c.company_id = :selected_company_id';
            if (!$allowShared) $where[count($where) - 1] = 'p.company_id = :selected_company_id';
            $params['selected_company_id'] = (int)$companyId;
            return ['where' => $where, 'params' => $params, 'company_id' => (int)$companyId];
        }

        if (!$allowShared) {
            throw new InvalidArgumentException('Choose a company before exporting Products.');
        }
        $parts = [];
        if ($authorization->allowsForSharedRecords($readPermission) && $authorization->allowsForSharedRecords('data_export.allowed')) {
            $parts[] = 'c.company_id IS NULL';
        }
        if (!$user->superuser) {
            foreach ($user->companyIds as $id) {
                if (!$authorization->allowsForCompany($readPermission, $id) || !$authorization->allowsForCompany('data_export.allowed', $id)) continue;
                $key = 'export_company_' . count($params);
                $parts[] = 'c.company_id = :' . $key;
                $params[$key] = $id;
            }
        }
        if ($user->superuser) $where[] = '1 = 1';
        else $where[] = $parts ? '(' . implode(' OR ', $parts) . ')' : '1 = 0';
        return ['where' => $where, 'params' => $params, 'company_id' => null];
    }

    /** @return array<string, mixed> */
    private function contactRelatedData(PDO $pdo, int $contactId, Authorization $authorization, ?int $companyId, DataCipher $cipher): array
    {
        $related = [
            'addresses_json' => $this->jsonRows($pdo, 'SELECT * FROM contact_addresses WHERE contact_id=:id ORDER BY id', $contactId),
            'tags_json' => $this->jsonRows($pdo, 'SELECT t.* FROM contact_tags t JOIN contact_tag_links l ON l.tag_id=t.id WHERE l.contact_id=:id ORDER BY t.name,t.id', $contactId),
            'partner_reviews_json' => $this->jsonRows($pdo, 'SELECT * FROM contact_partner_reviews WHERE contact_id=:id ORDER BY review_date DESC,id DESC', $contactId),
            'bank_accounts_json' => null,
            'ubo_owners_json' => null,
        ];
        if ($this->hasPermission($authorization, 'contacts.bank.read', $companyId)) {
            $rows = $this->queryRows($pdo, 'SELECT * FROM contact_bank_accounts WHERE contact_id=:id ORDER BY id', $contactId);
            foreach ($rows as &$row) {
                foreach (['account_number', 'routing_number', 'iban'] as $field) {
                    $encrypted = $row[$field . '_ciphertext'] ?? null;
                    $row[$field] = null;
                    if (is_string($encrypted) && $encrypted !== '') {
                        try { $row[$field] = $cipher->decrypt($encrypted); } catch (Throwable) { $row[$field] = null; }
                    }
                    unset($row[$field . '_ciphertext']);
                }
            }
            unset($row);
            $related['bank_accounts_json'] = $this->encodeJson($rows);
        }
        if ($this->hasPermission($authorization, 'contacts.ubo.read', $companyId)) {
            $related['ubo_owners_json'] = $this->jsonRows(
                $pdo,
                'SELECT o.*, p.name AS owner_contact_name FROM contact_ubo_owners o JOIN contacts p ON p.id=o.owner_contact_id WHERE o.company_contact_id=:id ORDER BY p.name,o.id',
                $contactId,
            );
        }
        return $related;
    }

    private function hasPermission(Authorization $authorization, string $permission, ?int $companyId): bool
    {
        return $companyId === null
            ? $authorization->allowsForSharedRecords($permission)
            : $authorization->allowsForCompany($permission, $companyId);
    }

    /** @return list<string> */
    private function tableFields(PDO $pdo, string $table): array
    {
        if (!in_array($table, ['contacts', 'products'], true)) throw new InvalidArgumentException('Unsupported export table.');
        $statement = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        return array_map(static fn (array $row): string => (string)$row['Field'], $statement->fetchAll());
    }

    private function searchText(mixed $raw, string $label): string
    {
        $query = $this->scalarString($raw, $label . ' search text');
        if (strlen($query) > 190) throw new InvalidArgumentException($label . ' search text must be 190 characters or fewer.');
        return $query;
    }

    private function scalarString(mixed $value, string $label): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            throw new InvalidArgumentException($label . ' must be a single text value.');
        }
        return trim((string)$value);
    }

    private function jsonRows(PDO $pdo, string $sql, int $contactId): ?string
    {
        return $this->encodeJson($this->queryRows($pdo, $sql, $contactId));
    }

    /** @return list<array<string, mixed>> */
    private function queryRows(PDO $pdo, string $sql, int $contactId): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute(['id' => $contactId]);
        return $statement->fetchAll();
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param resource $stream @param list<mixed> $values */
    private function writeRow($stream, array $values): void
    {
        $safe = array_map(static function (mixed $value): mixed {
            if (!is_string($value) || $value === '') return $value;
            $trimmed = ltrim($value, " \t\r\n\0\x0B");
            if ($trimmed === '' || !str_contains('=+-@', $trimmed[0])) return $value;
            if (preg_match('/^-?\d+(?:\.\d+)?$/D', $trimmed) === 1) return $value;
            return "'" . $value;
        }, $values);
        if (fputcsv($stream, $safe, ',', '"', '') === false) throw new \RuntimeException('A CSV row could not be written.');
    }
}
