<?php
declare(strict_types=1);

namespace Erp\Modules\Contacts;

use DateTimeImmutable;
use Erp\Core\Security\Authorization;
use Erp\Core\Security\AccessDeniedException;
use Erp\Core\Security\DataCipher;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;
use Throwable;

final class ContactRepository
{
    private const CONTACT_FIELDS = [
        'company_id', 'parent_contact_id', 'contact_type', 'name', 'job_title', 'email', 'phone', 'mobile',
        'website', 'tax_id', 'reference', 'language_code', 'street', 'street2', 'city', 'state', 'postal_code', 'country_code',
        'customer', 'supplier', 'employee', 'partner_level_id',
        'area_territory', 'external_code', 'customer_salesperson_user_id', 'customer_pricelist_id',
        'customer_payment_terms_id', 'customer_payment_method_id', 'delivery_method_id', 'customer_incoterm_code',
        'customer_incoterm_location', 'avalara_code', 'avalara_partner_code', 'avalara_exemption', 'group_rfqs',
        'buyer_user_id', 'supplier_payment_terms_id', 'supplier_payment_method_id', 'supplier_currency_code',
        'supplier_1099_box_code', 'receipt_reminder', 'supplier_incoterm_code', 'supplier_incoterm_location',
        'receivable_account_id', 'payable_account_id', 'auto_post_bills', 'peppol_id', 'peppol_status',
        'fiscal_position_id', 'commission_plan_id', 'activation_stage', 'level_weight', 'latest_review_date',
        'next_review_date', 'partnership_date', 'latitude', 'longitude', 'legal_form', 'formation_date', 'internal_notes',
    ];

    private const EXTRA_FIELDS = ['addresses', 'tags', 'bank_accounts', 'remove_bank_account_ids', 'ubo_owners', 'remove_ubo_owner_ids'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly DataCipher $cipher,
    ) {
    }

    /** @return array{records: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function search(UserContext $user, Authorization $authorization, array $filters): array
    {
        $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));
        $offset = max(0, min(1000000, (int)($filters['offset'] ?? 0)));
        $where = [];
        $params = [];
        $this->appendPermissionCompanyScope($where, $params, $user, $authorization, 'contacts.read', 'c');

        $role = (string)($filters['role'] ?? '');
        if ($role === 'customer') {
            $where[] = 'c.customer = 1';
        } elseif ($role === 'supplier') {
            $where[] = 'c.supplier = 1';
        } elseif ($role === 'employee') {
            $where[] = 'c.employee = 1';
        } elseif ($role !== '' && $role !== 'all') {
            throw new InvalidArgumentException('Role filter must be customer, supplier, employee, or all.');
        }

        if (isset($filters['company_id']) && $filters['company_id'] !== '') {
            $companyId = filter_var($filters['company_id'], FILTER_VALIDATE_INT);
            if ($companyId === false || $companyId < 1 || !$authorization->allowsForCompany('contacts.read', (int)$companyId)) {
                throw new InvalidArgumentException('The selected company is not available to this user.');
            }
            $where[] = 'c.company_id = :selected_company_id';
            $params['selected_company_id'] = $companyId;
        }

        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            if (strlen($query) > 190) {
                throw new InvalidArgumentException('Search text must be 190 characters or fewer.');
            }
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            $where[] = '(c.name LIKE :q_name ESCAPE \'!\' OR c.email LIKE :q_email ESCAPE \'!\' OR c.phone LIKE :q_phone ESCAPE \'!\' OR c.tax_id LIKE :q_tax ESCAPE \'!\' OR c.reference LIKE :q_reference ESCAPE \'!\')';
            $params += ['q_name' => $like, 'q_email' => $like, 'q_phone' => $like, 'q_tax' => $like, 'q_reference' => $like];
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM contacts c' . $whereSql);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $list = $this->pdo->prepare(
            'SELECT c.id, c.company_id, c.parent_contact_id, c.contact_type, c.name, c.job_title, c.email, c.phone, c.mobile, ' .
            'c.website, c.tax_id, c.reference, c.customer, c.supplier, c.employee, c.active, c.partner_level_id, ' .
            'c.area_territory, c.language_code, c.updated_at FROM contacts c' . $whereSql .
            ' ORDER BY c.name, c.id LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $list->bindValue(':limit', $limit, PDO::PARAM_INT);
        $list->bindValue(':offset', $offset, PDO::PARAM_INT);
        $list->execute();

        return ['records' => $list->fetchAll(), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, UserContext $user, Authorization $authorization): ?array
    {
        $where = ['c.id = :id'];
        $params = ['id' => $id];
        $this->appendCompanyScope($where, $params, $user, 'c');
        $query = $this->pdo->prepare('SELECT c.* FROM contacts c WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
        $query->execute($params);
        $contact = $query->fetch();
        if (!$contact) {
            return null;
        }
        if (!$this->hasContactPermission($authorization, 'contacts.read', $contact['company_id'] === null ? null : (int)$contact['company_id'])) {
            return null;
        }
        if (!$this->hasPermissionForCompanyOrShared($authorization, 'partner_commissions.read', $contact['company_id'] === null ? null : (int)$contact['company_id'])) {
            $contact['commission_plan_id'] = null;
        }

        $addresses = $this->pdo->prepare(
            'SELECT id, address_type, name, street, street2, city, state, postal_code, country_code, phone, email, is_default ' .
            'FROM contact_addresses WHERE contact_id = :id AND active = 1 ORDER BY is_default DESC, id'
        );
        $addresses->execute(['id' => $id]);
        $contact['addresses'] = $addresses->fetchAll();

        $tags = $this->pdo->prepare(
            'SELECT t.id, t.name, t.color FROM contact_tags t JOIN contact_tag_links l ON l.tag_id = t.id ' .
            'WHERE l.contact_id = :id ORDER BY t.name'
        );
        $tags->execute(['id' => $id]);
        $contact['tags'] = $tags->fetchAll();

        $contact['bank_accounts'] = $this->hasContactPermission($authorization, 'contacts.bank.read', $contact['company_id'] === null ? null : (int)$contact['company_id'])
            ? $this->bankAccounts($id)
            : [];
        $contact['ubo_owners'] = $this->hasContactPermission($authorization, 'contacts.ubo.read', $contact['company_id'] === null ? null : (int)$contact['company_id'])
            ? $this->uboOwners($id)
            : [];

        $reviews = $this->pdo->prepare(
            'SELECT id, reviewer_user_id, review_date, next_review_date, rating, notes ' .
            'FROM contact_partner_reviews WHERE contact_id = :id ORDER BY review_date DESC, id DESC'
        );
        $reviews->execute(['id' => $id]);
        $contact['reviews'] = $reviews->fetchAll();

        return $contact;
    }

    /** @return array<string, mixed> */
    public function save(?int $id, array $input, UserContext $user, Authorization $authorization, ?int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), array_merge(self::CONTACT_FIELDS, self::EXTRA_FIELDS));
        if ($unknown) {
            throw new InvalidArgumentException('Unsupported contact fields: ' . implode(', ', $unknown));
        }
        $values = $this->normalize($input);
        if ($id === null && (!isset($values['name']) || $values['name'] === '')) {
            throw new InvalidArgumentException('Contact name is required.');
        }
        if ($id !== null) {
            $current = $this->find($id, $user, $authorization);
            if (!$current) {
                throw new InvalidArgumentException('Contact was not found or is outside the allowed companies.');
            }
        } else {
            $current = null;
        }

        $companyId = array_key_exists('company_id', $values)
            ? $values['company_id']
            : (isset($current['company_id']) ? (int)$current['company_id'] : null);
        if ($companyId !== null && !$user->superuser && !in_array((int)$companyId, $user->companyIds, true)) {
            throw new InvalidArgumentException('The selected company is not available to this user.');
        }
        $requiredPermission = $id === null ? 'contact_master_data.create' : 'contacts.write';
        $canChangeContact = $companyId === null
            ? ($id === null
                ? $authorization->allowsForSharedRecords('contact_master_data.create')
                : $authorization->allowsForSharedRecords('contacts.write'))
            : $authorization->allowsForCompany($requiredPermission, $companyId);
        if (!$canChangeContact) {
            throw new AccessDeniedException($id === null
                ? 'You do not have Contact creation access for this company.'
                : 'You do not have permission to change Contacts in this company.');
        }
        if ((array_key_exists('bank_accounts', $input) || array_key_exists('remove_bank_account_ids', $input))
            && !$this->hasContactPermission($authorization, 'contacts.bank.write', $companyId)) {
            throw new AccessDeniedException('You do not have permission to change Contact bank details in this company.');
        }
        if ((array_key_exists('ubo_owners', $input) || array_key_exists('remove_ubo_owner_ids', $input))
            && !$this->hasContactPermission($authorization, 'contacts.ubo.write', $companyId)) {
            throw new AccessDeniedException('You do not have permission to change Contact beneficial ownership in this company.');
        }
        if (array_key_exists('commission_plan_id', $input)
            && (!$this->hasPermissionForCompanyOrShared($authorization, 'partner_commissions.read', $companyId)
                || !$this->hasPermissionForCompanyOrShared($authorization, 'partner_commissions.assign', $companyId))) {
            throw new AccessDeniedException('Partner Commissions read and assignment access are required to assign a Contact commission plan.');
        }
        if (!empty($values['parent_contact_id'])) {
            $canReadParent = $companyId === null
                ? $authorization->allowsForSharedRecords('contacts.read')
                : $authorization->allowsForCompany('contacts.read', $companyId);
            if (!$canReadParent) {
                throw new AccessDeniedException('Contacts read access is required to link a parent Contact.');
            }
        }
        $hasAccountingAccess = $companyId === null
            ? $authorization->allowsForSharedRecords('accounting.read')
            : $authorization->allowsForCompany('accounting.read', $companyId);
        if (!$hasAccountingAccess && (!empty($values['receivable_account_id']) || !empty($values['payable_account_id']))) {
            throw new AccessDeniedException('Accounting read access is required to assign Contact accounts.');
        }
        $this->validateLinks($values, $companyId, $user);

        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $insertValues = $values;
                $insertValues['company_id'] = $companyId;
                $columns = array_keys($insertValues);
                $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
                $statement = $this->pdo->prepare(
                    'INSERT INTO contacts (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
                );
                $statement->execute($insertValues);
                $id = (int)$this->pdo->lastInsertId();
                $eventKey = 'create';
                $oldValues = null;
            } else {
                if ($values) {
                    $assignments = array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($values));
                    $values['id'] = $id;
                    $statement = $this->pdo->prepare('UPDATE contacts SET ' . implode(', ', $assignments) . ' WHERE id = :id');
                    $statement->execute($values);
                }
                $eventKey = 'update';
                $oldValues = $this->auditValues($current);
            }

            if (array_key_exists('addresses', $input)) {
                $this->syncAddresses($id, $input['addresses']);
            }
            if (array_key_exists('tags', $input)) {
                $this->syncTags($id, $companyId, $input['tags']);
            }
            if (array_key_exists('bank_accounts', $input) || array_key_exists('remove_bank_account_ids', $input)) {
                $this->syncBankAccounts($id, $input['bank_accounts'] ?? [], $input['remove_bank_account_ids'] ?? []);
            }
            if (array_key_exists('ubo_owners', $input) || array_key_exists('remove_ubo_owner_ids', $input)) {
                $this->syncUboOwners($id, $input['ubo_owners'] ?? [], $input['remove_ubo_owner_ids'] ?? [], $user);
            }

            $newValues = $this->auditValues($values);
            $this->writeAudit($companyId, $actorId, $eventKey, $id, $oldValues, $newValues, $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        $canReadSaved = $companyId === null
            ? $authorization->allowsForSharedRecords('contacts.read')
            : $authorization->allowsForCompany('contacts.read', $companyId);
        if (!$canReadSaved) {
            return ['id' => $id, 'company_id' => $companyId, 'name' => $values['name'] ?? null];
        }
        $saved = $this->find($id, $user, $authorization);
        if (!$saved) {
            throw new InvalidArgumentException('Saved contact could not be reloaded.');
        }
        return $saved;
    }

    /** Archive or restore a Contact without deleting or unlinking its historical business documents. */
    public function setActive(int $id, bool $active, UserContext $user, Authorization $authorization, int $actorId, string $remoteAddress): array
    {
        $where = ['c.id = :id'];
        $params = ['id' => $id];
        $this->appendCompanyScope($where, $params, $user, 'c');
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT c.id,c.company_id,c.active FROM contacts c WHERE ' . implode(' AND ', $where) . ' LIMIT 1 FOR UPDATE');
            $query->execute($params);
            $current = $query->fetch();
            if (!$current) {
                throw new InvalidArgumentException('Contact was not found in an allowed company.');
            }
            $companyId = $current['company_id'] === null ? null : (int)$current['company_id'];
            if (!$this->hasContactPermission($authorization, 'contacts.read', $companyId)) {
                throw new AccessDeniedException('You do not have permission to read this Contact in this company.');
            }
            if (!$this->hasContactPermission($authorization, 'contacts.archive', $companyId)) {
                throw new AccessDeniedException('You do not have permission to archive or restore Contacts in this company.');
            }
            $before = (int)$current['active'];
            $after = $active ? 1 : 0;
            if ($before !== $after) {
                $this->pdo->prepare('UPDATE contacts SET active=:active WHERE id=:id')->execute(['active' => $after, 'id' => $id]);
                $this->writeAudit($companyId, $actorId, $active ? 'restore' : 'archive', $id, ['active' => $before], ['active' => $after], $remoteAddress);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $this->find($id, $user, $authorization) ?? throw new InvalidArgumentException('Contact status was saved but the Contact could not be reloaded.');
    }

    private function normalize(array $input): array
    {
        $values = [];
        foreach (self::CONTACT_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if (in_array($field, ['customer', 'supplier', 'employee', 'receipt_reminder'], true)) {
                $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($boolean === null) {
                    throw new InvalidArgumentException($field . ' must be a boolean value.');
                }
                $values[$field] = $boolean ? 1 : 0;
                continue;
            }
            if (in_array($field, [
                'company_id', 'parent_contact_id', 'partner_level_id', 'customer_salesperson_user_id',
                'customer_pricelist_id', 'customer_payment_terms_id', 'customer_payment_method_id', 'delivery_method_id',
                'buyer_user_id', 'supplier_payment_terms_id', 'supplier_payment_method_id', 'receivable_account_id',
                'payable_account_id', 'fiscal_position_id', 'commission_plan_id',
            ], true)) {
                $values[$field] = $this->nullableId($field, $value);
                continue;
            }
            if (in_array($field, ['level_weight', 'latitude', 'longitude'], true)) {
                $values[$field] = $this->decimal($field, $value, $field === 'level_weight' ? 4 : 7);
                continue;
            }
            if (in_array($field, ['latest_review_date', 'next_review_date', 'partnership_date', 'formation_date'], true)) {
                $values[$field] = $this->date($field, $value);
                continue;
            }
            if ($field === 'supplier_currency_code') {
                $text = $this->nullableText($field, $value, 3);
                $values[$field] = $text === null ? null : strtoupper($text);
                continue;
            }
            if ($field === 'customer_incoterm_code' || $field === 'supplier_incoterm_code') {
                $text = $this->nullableText($field, $value, 8);
                $values[$field] = $text === null ? null : strtoupper($text);
                continue;
            }
            if ($field === 'country_code') {
                $values[$field] = $this->countryCode($value);
                continue;
            }
            $limit = $field === 'internal_notes' ? 60000 : (in_array($field, ['job_title', 'activation_stage'], true) ? 120 : 190);
            $values[$field] = $this->nullableText($field, $value, $limit);
        }

        if (array_key_exists('contact_type', $values) && !in_array($values['contact_type'], ['person', 'company'], true)) {
            throw new InvalidArgumentException('Contact type must be person or company.');
        }
        if (isset($values['group_rfqs']) && !in_array($values['group_rfqs'], ['on_order', 'daily', 'weekly', 'always'], true)) {
            throw new InvalidArgumentException('Group RFQs must be on_order, daily, weekly, or always.');
        }
        if (isset($values['auto_post_bills']) && !in_array($values['auto_post_bills'], ['never', 'after3'], true)) {
            throw new InvalidArgumentException('Auto-post bills must be never or after3.');
        }
        if (isset($values['peppol_status']) && $values['peppol_status'] !== null && !in_array($values['peppol_status'], ['unchecked', 'verified', 'invalid'], true)) {
            throw new InvalidArgumentException('Peppol status must be unchecked, verified, or invalid.');
        }
        if (isset($values['language_code']) && $values['language_code'] !== null && preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $values['language_code']) !== 1) {
            throw new InvalidArgumentException('Language code format is invalid.');
        }
        if (isset($values['email']) && $values['email'] !== null && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email address is invalid.');
        }
        if (isset($values['website']) && $values['website'] !== null) {
            $scheme = strtolower((string)parse_url($values['website'], PHP_URL_SCHEME));
            if (!filter_var($values['website'], FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Website must be a valid http or https URL.');
            }
        }

        return $values;
    }

    private function validateLinks(array $values, ?int $companyId, UserContext $user): void
    {
        if (isset($values['parent_contact_id']) && !$this->contactIsVisible((int)$values['parent_contact_id'], $user)) {
            throw new InvalidArgumentException('Parent contact is not available to this user.');
        }
        $userFields = ['customer_salesperson_user_id', 'buyer_user_id'];
        foreach ($userFields as $field) {
            if (!empty($values[$field])) {
                $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :id AND status IN (\'confirmed\', \'active\')');
                $statement->execute(['id' => $values[$field]]);
                if (!$statement->fetchColumn()) {
                    throw new InvalidArgumentException($field . ' must refer to an active user.');
                }
                if ($companyId !== null && !$user->superuser) {
                    $access = $this->pdo->prepare('SELECT 1 FROM user_company_access WHERE user_id = :user_id AND company_id = :company_id');
                    $access->execute(['user_id' => $values[$field], 'company_id' => $companyId]);
                    if (!$access->fetchColumn()) {
                        throw new InvalidArgumentException($field . ' must have access to the contact company.');
                    }
                }
            }
        }

        $scopedFields = [
            'partner_level_id' => 'partner_levels', 'customer_pricelist_id' => 'price_lists',
            'customer_payment_terms_id' => 'payment_terms', 'customer_payment_method_id' => 'payment_methods',
            'delivery_method_id' => 'delivery_methods', 'supplier_payment_terms_id' => 'payment_terms',
            'supplier_payment_method_id' => 'payment_methods', 'receivable_account_id' => 'accounting_accounts',
            'payable_account_id' => 'accounting_accounts', 'fiscal_position_id' => 'fiscal_positions',
            'commission_plan_id' => 'commission_plans',
        ];
        foreach ($scopedFields as $field => $table) {
            if (empty($values[$field])) {
                continue;
            }
            $statement = $this->pdo->prepare('SELECT company_id FROM ' . $table . ' WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $values[$field]]);
            $record = $statement->fetch();
            if (!$record) {
                throw new InvalidArgumentException($field . ' refers to an unavailable record.');
            }
            $linkedCompany = $record['company_id'] === null ? null : (int)$record['company_id'];
            if ($linkedCompany !== null && !$user->superuser && !in_array($linkedCompany, $user->companyIds, true)) {
                throw new InvalidArgumentException($field . ' refers to a record outside the allowed companies.');
            }
            if ($companyId !== null && $linkedCompany !== null && $linkedCompany !== $companyId) {
                throw new InvalidArgumentException($field . ' must belong to the same company as the contact.');
            }
        }

        if (!empty($values['supplier_currency_code'])) {
            $statement = $this->pdo->prepare('SELECT 1 FROM currencies WHERE currency_code = :code AND active = 1');
            $statement->execute(['code' => $values['supplier_currency_code']]);
            if (!$statement->fetchColumn()) {
                throw new InvalidArgumentException('Supplier currency is not configured.');
            }
        }
        foreach (['customer_incoterm_code', 'supplier_incoterm_code'] as $field) {
            if (!empty($values[$field])) {
                $statement = $this->pdo->prepare('SELECT 1 FROM incoterms WHERE code = :code AND active = 1');
                $statement->execute(['code' => $values[$field]]);
                if (!$statement->fetchColumn()) {
                    throw new InvalidArgumentException($field . ' is not configured.');
                }
            }
        }
    }

    private function syncAddresses(int $contactId, mixed $addresses): void
    {
        if (!is_array($addresses) || count($addresses) > 100) {
            throw new InvalidArgumentException('Addresses must be a list of at most 100 entries.');
        }
        $this->pdo->prepare('UPDATE contact_addresses SET active = 0, is_default = 0 WHERE contact_id = :id')->execute(['id' => $contactId]);
        $allowedTypes = ['contact', 'invoice', 'delivery', 'other', 'face_center'];
        $seenDefaults = false;
        foreach (array_values($addresses) as $address) {
            if (!is_array($address)) {
                throw new InvalidArgumentException('Each address must be an object.');
            }
            $type = (string)($address['address_type'] ?? 'other');
            if (!in_array($type, $allowedTypes, true)) {
                throw new InvalidArgumentException('Address type is invalid.');
            }
            $isDefault = filter_var($address['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isDefault && $seenDefaults) {
                throw new InvalidArgumentException('Only one related address can be the default address.');
            }
            $seenDefaults = $seenDefaults || $isDefault;
            $fields = [
                'contact_id' => $contactId,
                'address_type' => $type,
                'name' => $this->nullableText('address name', $address['name'] ?? null, 190),
                'street' => $this->nullableText('street', $address['street'] ?? null, 190),
                'street2' => $this->nullableText('street 2', $address['street2'] ?? null, 190),
                'city' => $this->nullableText('city', $address['city'] ?? null, 120),
                'state' => $this->nullableText('state', $address['state'] ?? null, 120),
                'postal_code' => $this->nullableText('postal code', $address['postal_code'] ?? null, 40),
                'country_code' => $this->countryCode($address['country_code'] ?? null),
                'phone' => $this->nullableText('address phone', $address['phone'] ?? null, 80),
                'email' => $this->nullableText('address email', $address['email'] ?? null, 190),
                'is_default' => $isDefault ? 1 : 0,
            ];
            if ($fields['email'] !== null && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Related address email is invalid.');
            }
            $addressId = filter_var($address['id'] ?? null, FILTER_VALIDATE_INT);
            if ($addressId !== false && $addressId !== null && $addressId > 0) {
                $belongs = $this->pdo->prepare('SELECT 1 FROM contact_addresses WHERE id = :id AND contact_id = :contact_id LIMIT 1');
                $belongs->execute(['id' => $addressId, 'contact_id' => $contactId]);
                if (!$belongs->fetchColumn()) {
                    throw new InvalidArgumentException('Related address does not belong to this contact.');
                }
                $fields['id'] = $addressId;
                $statement = $this->pdo->prepare(
                    'UPDATE contact_addresses SET address_type = :address_type, name = :name, street = :street, street2 = :street2, ' .
                    'city = :city, state = :state, postal_code = :postal_code, country_code = :country_code, phone = :phone, email = :email, ' .
                    'is_default = :is_default, active = 1 WHERE id = :id AND contact_id = :contact_id'
                );
                $statement->execute($fields);
            } else {
                $this->insertAddress($fields);
            }
        }
    }

    private function insertAddress(array $fields): void
    {
        $columns = array_keys($fields);
        $statement = $this->pdo->prepare(
            'INSERT INTO contact_addresses (' . implode(', ', $columns) . ') VALUES (' .
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns)) . ')'
        );
        $statement->execute($fields);
    }

    private function syncTags(int $contactId, ?int $companyId, mixed $tags): void
    {
        if (!is_array($tags) || count($tags) > 100) {
            throw new InvalidArgumentException('Tags must be a list of at most 100 names or IDs.');
        }
        $this->pdo->prepare('DELETE FROM contact_tag_links WHERE contact_id = :id')->execute(['id' => $contactId]);
        $insertLink = $this->pdo->prepare('INSERT IGNORE INTO contact_tag_links (contact_id, tag_id) VALUES (:contact_id, :tag_id)');
        foreach ($tags as $tag) {
            if (is_array($tag)) {
                if (isset($tag['id']) && (is_int($tag['id']) || (is_string($tag['id']) && ctype_digit($tag['id'])))) {
                    $tag = (int)$tag['id'];
                } elseif (isset($tag['name']) && is_string($tag['name'])) {
                    $tag = $tag['name'];
                } else {
                    throw new InvalidArgumentException('Each tag object must contain a valid ID or name.');
                }
            }
            if (is_int($tag) || (is_string($tag) && ctype_digit($tag))) {
                $tagId = (int)$tag;
                $find = $this->pdo->prepare('SELECT id FROM contact_tags WHERE id = :id AND (company_id IS NULL OR company_id = :company_id)');
                $find->execute(['id' => $tagId, 'company_id' => $companyId]);
                $found = $find->fetchColumn();
                if (!$found) {
                    throw new InvalidArgumentException('A selected tag is not available to the contact company.');
                }
            } elseif (is_string($tag)) {
                $name = $this->nullableText('tag', $tag, 80);
                if ($name === null) {
                    continue;
                }
                $find = $this->pdo->prepare('SELECT id FROM contact_tags WHERE name = :name AND (company_id <=> :company_id) LIMIT 1');
                $find->execute(['name' => $name, 'company_id' => $companyId]);
                $found = $find->fetchColumn();
                if (!$found) {
                    $create = $this->pdo->prepare('INSERT INTO contact_tags (company_id, name) VALUES (:company_id, :name)');
                    $create->execute(['company_id' => $companyId, 'name' => $name]);
                    $found = (int)$this->pdo->lastInsertId();
                }
                $tagId = (int)$found;
            } else {
                throw new InvalidArgumentException('Each tag must be a tag ID or a tag name.');
            }
            $insertLink->execute(['contact_id' => $contactId, 'tag_id' => $tagId]);
        }
    }

    private function syncBankAccounts(int $contactId, mixed $accounts, mixed $removeIds): void
    {
        if (!is_array($accounts) || count($accounts) > 50 || !is_array($removeIds) || count($removeIds) > 50) {
            throw new InvalidArgumentException('Bank accounts must be a list of at most 50 entries.');
        }
        foreach (array_values($accounts) as $account) {
            if (!is_array($account)) {
                throw new InvalidArgumentException('Each bank account must be an object.');
            }
            $accountId = filter_var($account['id'] ?? null, FILTER_VALIDATE_INT);
            $bankName = $this->nullableText('bank name', $account['bank_name'] ?? null, 190);
            if ($bankName === null) {
                throw new InvalidArgumentException('Bank name is required for each bank account.');
            }
            $holder = $this->nullableText('account holder', $account['account_holder'] ?? null, 190);
            $currency = strtoupper((string)$this->nullableText('bank currency', $account['currency_code'] ?? null, 3));
            if ($currency !== '') {
                $check = $this->pdo->prepare('SELECT 1 FROM currencies WHERE currency_code = :code AND active = 1');
                $check->execute(['code' => $currency]);
                if (!$check->fetchColumn()) {
                    throw new InvalidArgumentException('Bank account currency is not configured.');
                }
            }
            $numbers = [];
            foreach (['account_number', 'routing_number', 'iban'] as $field) {
                $raw = $this->nullableText($field, $account[$field] ?? null, 200);
                if ($raw !== null) {
                    $normalized = preg_replace('/\s+/', '', $raw) ?? $raw;
                    $numbers[$field . '_ciphertext'] = $this->cipher->encrypt($normalized);
                    $numbers[$field . '_last_four'] = substr($normalized, -4);
                }
            }
            if ($accountId !== false && $accountId !== null && $accountId > 0) {
                $exists = $this->pdo->prepare('SELECT id FROM contact_bank_accounts WHERE id = :id AND contact_id = :contact_id');
                $exists->execute(['id' => $accountId, 'contact_id' => $contactId]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('Bank account does not belong to this contact.');
                }
                $sets = ['bank_name = :bank_name', 'account_holder = :account_holder', 'currency_code = :currency_code', 'active = 1'];
                $params = ['bank_name' => $bankName, 'account_holder' => $holder, 'currency_code' => $currency !== '' ? $currency : null, 'id' => $accountId];
                foreach ($numbers as $field => $value) {
                    $sets[] = $field . ' = :' . $field;
                    $params[$field] = $value;
                }
                $this->pdo->prepare('UPDATE contact_bank_accounts SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
            } else {
                $params = [
                    'contact_id' => $contactId,
                    'bank_name' => $bankName,
                    'account_holder' => $holder,
                    'currency_code' => $currency !== '' ? $currency : null,
                    'account_number_ciphertext' => $numbers['account_number_ciphertext'] ?? null,
                    'account_number_last_four' => $numbers['account_number_last_four'] ?? null,
                    'routing_number_ciphertext' => $numbers['routing_number_ciphertext'] ?? null,
                    'routing_number_last_four' => $numbers['routing_number_last_four'] ?? null,
                    'iban_ciphertext' => $numbers['iban_ciphertext'] ?? null,
                    'iban_last_four' => $numbers['iban_last_four'] ?? null,
                ];
                $this->pdo->prepare(
                    'INSERT INTO contact_bank_accounts (contact_id, bank_name, account_holder, currency_code, account_number_ciphertext, account_number_last_four, routing_number_ciphertext, routing_number_last_four, iban_ciphertext, iban_last_four) ' .
                    'VALUES (:contact_id, :bank_name, :account_holder, :currency_code, :account_number_ciphertext, :account_number_last_four, :routing_number_ciphertext, :routing_number_last_four, :iban_ciphertext, :iban_last_four)'
                )->execute($params);
            }
        }
        foreach ($removeIds as $removeId) {
            $value = filter_var($removeId, FILTER_VALIDATE_INT);
            if ($value === false || $value < 1) {
                throw new InvalidArgumentException('Bank account removal IDs must be positive integers.');
            }
            $this->pdo->prepare('UPDATE contact_bank_accounts SET active = 0 WHERE id = :id AND contact_id = :contact_id')->execute(['id' => $value, 'contact_id' => $contactId]);
        }
    }

    private function syncUboOwners(int $companyContactId, mixed $owners, mixed $removeIds, UserContext $user): void
    {
        if (!is_array($owners) || count($owners) > 200 || !is_array($removeIds) || count($removeIds) > 200) {
            throw new InvalidArgumentException('Beneficial owners must be a list of at most 200 entries.');
        }
        $companyCheck = $this->pdo->prepare('SELECT contact_type FROM contacts WHERE id = :id LIMIT 1');
        $companyCheck->execute(['id' => $companyContactId]);
        if ($companyCheck->fetchColumn() !== 'company') {
            throw new InvalidArgumentException('Beneficial ownership can only be set on a company contact.');
        }
        foreach (array_values($owners) as $owner) {
            if (!is_array($owner)) {
                throw new InvalidArgumentException('Each beneficial owner must be an object.');
            }
            $ownerContactId = filter_var($owner['owner_contact_id'] ?? null, FILTER_VALIDATE_INT);
            if ($ownerContactId === false || $ownerContactId < 1 || !$this->contactIsVisible($ownerContactId, $user)) {
                throw new InvalidArgumentException('Each beneficial owner must reference an available contact.');
            }
            if ($ownerContactId === $companyContactId) {
                throw new InvalidArgumentException('A company cannot be its own beneficial owner.');
            }
            $fields = [
                'company_contact_id' => $companyContactId,
                'owner_contact_id' => $ownerContactId,
                'control_method' => $this->nullableText('control method', $owner['control_method'] ?? null, 120),
                'ownership_percent' => $this->percentage('ownership percent', $owner['ownership_percent'] ?? null),
                'voting_rights_percent' => $this->percentage('voting rights percent', $owner['voting_rights_percent'] ?? null),
                'control_start_date' => $this->date('control start date', $owner['control_start_date'] ?? null),
                'control_end_date' => $this->date('control end date', $owner['control_end_date'] ?? null),
            ];
            $ownerId = filter_var($owner['id'] ?? null, FILTER_VALIDATE_INT);
            if ($ownerId !== false && $ownerId !== null && $ownerId > 0) {
                $fields['id'] = $ownerId;
                $statement = $this->pdo->prepare(
                    'UPDATE contact_ubo_owners SET owner_contact_id = :owner_contact_id, control_method = :control_method, ' .
                    'ownership_percent = :ownership_percent, voting_rights_percent = :voting_rights_percent, ' .
                    'control_start_date = :control_start_date, control_end_date = :control_end_date, active = 1 ' .
                    'WHERE id = :id AND company_contact_id = :company_contact_id'
                );
                $statement->execute($fields);
                if ($statement->rowCount() === 0) {
                    throw new InvalidArgumentException('Beneficial ownership entry does not belong to this company contact.');
                }
            } else {
                $this->pdo->prepare(
                    'INSERT INTO contact_ubo_owners (company_contact_id, owner_contact_id, control_method, ownership_percent, voting_rights_percent, control_start_date, control_end_date) ' .
                    'VALUES (:company_contact_id, :owner_contact_id, :control_method, :ownership_percent, :voting_rights_percent, :control_start_date, :control_end_date) ' .
                    'ON DUPLICATE KEY UPDATE control_method = VALUES(control_method), ownership_percent = VALUES(ownership_percent), ' .
                    'voting_rights_percent = VALUES(voting_rights_percent), control_start_date = VALUES(control_start_date), control_end_date = VALUES(control_end_date), active = 1'
                )->execute($fields);
            }
        }
        foreach ($removeIds as $removeId) {
            $value = filter_var($removeId, FILTER_VALIDATE_INT);
            if ($value === false || $value < 1) {
                throw new InvalidArgumentException('Beneficial ownership removal IDs must be positive integers.');
            }
            $this->pdo->prepare('UPDATE contact_ubo_owners SET active = 0 WHERE id = :id AND company_contact_id = :company_contact_id')->execute(['id' => $value, 'company_contact_id' => $companyContactId]);
        }
    }

    private function bankAccounts(int $contactId): array
    {
        $query = $this->pdo->prepare(
            'SELECT id, bank_name, account_holder, account_number_ciphertext, account_number_last_four, ' .
            'routing_number_ciphertext, routing_number_last_four, iban_ciphertext, iban_last_four, currency_code ' .
            'FROM contact_bank_accounts WHERE contact_id = :id AND active = 1 ORDER BY id'
        );
        $query->execute(['id' => $contactId]);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            foreach (['account_number', 'routing_number', 'iban'] as $field) {
                $cipherField = $field . '_ciphertext';
                $lastFourField = $field . '_last_four';
                $row[$field . '_masked'] = $row[$lastFourField] ? '••••' . $row[$lastFourField] : null;
                $row[$field] = null;
                if (is_string($row[$cipherField]) && $row[$cipherField] !== '') {
                    try {
                        $row[$field] = $this->cipher->decrypt($row[$cipherField]);
                    } catch (Throwable) {
                        $row[$field] = null;
                    }
                }
                unset($row[$cipherField], $row[$lastFourField]);
            }
        }
        unset($row);
        return $rows;
    }

    private function uboOwners(int $contactId): array
    {
        $query = $this->pdo->prepare(
            'SELECT o.id, o.owner_contact_id, p.name AS owner_name, o.control_method, o.ownership_percent, ' .
            'o.voting_rights_percent, o.control_start_date, o.control_end_date ' .
            'FROM contact_ubo_owners o JOIN contacts p ON p.id = o.owner_contact_id ' .
            'WHERE o.company_contact_id = :id AND o.active = 1 ORDER BY p.name, o.id'
        );
        $query->execute(['id' => $contactId]);
        return $query->fetchAll();
    }

    private function contactIsVisible(int $contactId, UserContext $user): bool
    {
        $where = ['id = :id'];
        $params = ['id' => $contactId];
        if (!$user->superuser) {
            if ($user->companyIds) {
                $where[] = '(company_id IS NULL OR company_id IN (' . implode(', ', array_map(static fn (int $i): string => ':company_' . $i, array_keys($user->companyIds))) . '))';
                foreach ($user->companyIds as $index => $companyId) {
                    $params['company_' . $index] = $companyId;
                }
            } else {
                $where[] = 'company_id IS NULL';
            }
        }
        $statement = $this->pdo->prepare('SELECT 1 FROM contacts WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
        $statement->execute($params);
        return (bool)$statement->fetchColumn();
    }

    private function appendCompanyScope(array &$where, array &$params, UserContext $user, string $alias): void
    {
        if ($user->superuser) {
            return;
        }
        if (!$user->companyIds) {
            $where[] = $alias . '.company_id IS NULL';
            return;
        }
        $placeholders = [];
        foreach ($user->companyIds as $index => $companyId) {
            $name = 'scope_company_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $companyId;
        }
        $where[] = '(' . $alias . '.company_id IS NULL OR ' . $alias . '.company_id IN (' . implode(', ', $placeholders) . '))';
    }

    private function appendPermissionCompanyScope(array &$where, array &$params, UserContext $user, Authorization $authorization, string $permission, string $alias): void
    {
        if ($user->superuser) {
            return;
        }
        $companyIds = [];
        foreach ($user->companyIds as $companyId) {
            if ($authorization->allowsForCompany($permission, $companyId)) {
                $companyIds[] = $companyId;
            }
        }
        $canReadShared = $authorization->allows($permission);
        $parts = [];
        if ($canReadShared) {
            $parts[] = $alias . '.company_id IS NULL';
        }
        if ($companyIds) {
            $placeholders = [];
            foreach ($companyIds as $index => $companyId) {
                $name = 'permission_company_' . $index;
                $placeholders[] = ':' . $name;
                $params[$name] = $companyId;
            }
            $parts[] = $alias . '.company_id IN (' . implode(', ', $placeholders) . ')';
        }
        $where[] = $parts ? '(' . implode(' OR ', $parts) . ')' : '1 = 0';
    }

    private function hasContactPermission(Authorization $authorization, string $permission, ?int $companyId): bool
    {
        return $companyId === null
            ? $authorization->allows($permission)
            : $authorization->allowsForCompany($permission, $companyId);
    }

    private function hasPermissionForCompanyOrShared(Authorization $authorization, string $permission, ?int $companyId): bool
    {
        return $companyId === null
            ? $authorization->allowsForSharedRecords($permission)
            : $authorization->allowsForCompany($permission, $companyId);
    }

    private function writeAudit(?int $companyId, ?int $actorId, string $eventKey, int $contactId, ?array $oldValues, array $newValues, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $requestId = sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events (company_id, user_id, event_key, model_key, record_id, old_values, new_values, request_id, ip_address) ' .
            'VALUES (:company_id, :user_id, :event_key, \'contacts\', :record_id, :old_values, :new_values, :request_id, :ip_address)'
        );
        $statement->bindValue(':company_id', $companyId, $companyId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':user_id', $actorId, $actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':event_key', $eventKey);
        $statement->bindValue(':record_id', $contactId, PDO::PARAM_INT);
        $statement->bindValue(':old_values', $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $oldValues === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':new_values', json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $statement->bindValue(':request_id', $requestId);
        $statement->bindValue(':ip_address', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $statement->execute();
    }

    private function auditValues(array $values): array
    {
        unset($values['internal_notes'], $values['addresses'], $values['tags'], $values['bank_accounts'], $values['ubo_owners']);
        return $values;
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
        $length = function_exists('mb_strlen')
            ? mb_strlen($text, 'UTF-8')
            : (preg_match_all('/./us', $text, $characters) ?: strlen($text));
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

    private function decimal(string $field, mixed $value, int $places): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($field . ' must be numeric.');
        }
        $number = (float)$value;
        if (!is_finite($number)) {
            throw new InvalidArgumentException($field . ' must be finite.');
        }
        if ($field === 'level_weight' && $number < 0) {
            throw new InvalidArgumentException('Level weight cannot be negative.');
        }
        if ($field === 'latitude' && ($number < -90 || $number > 90)) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }
        if ($field === 'longitude' && ($number < -180 || $number > 180)) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }
        return number_format($number, $places, '.', '');
    }

    private function percentage(string $field, mixed $value): ?string
    {
        $decimal = $this->decimal($field, $value, 4);
        if ($decimal !== null && ((float)$decimal < 0 || (float)$decimal > 100)) {
            throw new InvalidArgumentException($field . ' must be between 0 and 100.');
        }
        return $decimal;
    }

    private function date(string $field, mixed $value): ?string
    {
        $text = $this->nullableText($field, $value, 10);
        if ($text === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text) {
            throw new InvalidArgumentException($field . ' must be a valid YYYY-MM-DD date.');
        }
        return $text;
    }

}
