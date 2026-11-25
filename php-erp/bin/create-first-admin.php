<?php
declare(strict_types=1);

use Erp\Core\Config;
use Erp\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$companyName = trim((string)getenv('ERP_BOOTSTRAP_COMPANY_NAME'));
$adminName = trim((string)getenv('ERP_BOOTSTRAP_ADMIN_NAME'));
$adminLogin = strtolower(trim((string)getenv('ERP_BOOTSTRAP_ADMIN_LOGIN')));
$existingCompanyId = filter_var(getenv('ERP_BOOTSTRAP_COMPANY_ID') ?: null, FILTER_VALIDATE_INT);
if ($companyName === '' || $adminName === '' || $adminLogin === '') {
    fwrite(STDERR, "Set ERP_BOOTSTRAP_COMPANY_NAME, ERP_BOOTSTRAP_ADMIN_NAME, and ERP_BOOTSTRAP_ADMIN_LOGIN before running this command.\n");
    exit(2);
}
if (strlen($companyName) > 190 || strlen($adminName) > 190 || strlen($adminLogin) > 190) {
    fwrite(STDERR, "Company, administrator name, and login must each be 190 characters or fewer.\n");
    exit(2);
}

$config = Config::fromEnvironment(dirname(__DIR__));
$pdo = Database::connection($config);
$existingUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($existingUsers > 0) {
    fwrite(STDERR, "An administrator has already been bootstrapped. This command will not add another initial administrator.\n");
    exit(3);
}

$companyCount = (int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
if ($existingCompanyId !== false && $existingCompanyId !== null && $existingCompanyId > 0) {
    $companyCheck = $pdo->prepare('SELECT id FROM companies WHERE id = :id LIMIT 1');
    $companyCheck->execute(['id' => $existingCompanyId]);
    $companyId = $companyCheck->fetchColumn();
    if (!$companyId) {
        fwrite(STDERR, "ERP_BOOTSTRAP_COMPANY_ID does not match a company.\n");
        exit(2);
    }
} elseif ($companyCount === 0) {
    $companyId = null;
} else {
    fwrite(STDERR, "Companies already exist. Set ERP_BOOTSTRAP_COMPANY_ID to the company that will own the administrator account.\n");
    exit(2);
}

$generatedPassword = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
$pdo->beginTransaction();
try {
    if ($companyId === null) {
        $companyInsert = $pdo->prepare('INSERT INTO companies (name, currency_code, timezone) VALUES (:name, :currency, :timezone)');
        $companyInsert->execute(['name' => $companyName, 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka']);
        $companyId = (int)$pdo->lastInsertId();
    }

    $companyQuery = $pdo->prepare('SELECT id, name, contact_id FROM companies WHERE id = :id LIMIT 1');
    $companyQuery->execute(['id' => $companyId]);
    $company = $companyQuery->fetch();
    if (!$company) {
        throw new RuntimeException('The selected company could not be loaded.');
    }

    $companyContactId = $company['contact_id'] === null ? null : (int)$company['contact_id'];
    if ($companyContactId === null) {
        $existingPartner = $pdo->prepare(
            'SELECT id FROM contacts WHERE company_id = :company_id AND contact_type = \'company\' AND name = :name ORDER BY id LIMIT 1'
        );
        $existingPartner->execute(['company_id' => $companyId, 'name' => $company['name']]);
        $companyContactId = $existingPartner->fetchColumn();
        if ($companyContactId === false) {
            $partnerInsert = $pdo->prepare(
                'INSERT INTO contacts (company_id, contact_type, name) VALUES (:company_id, \'company\', :name)'
            );
            $partnerInsert->execute(['company_id' => $companyId, 'name' => $company['name']]);
            $companyContactId = (int)$pdo->lastInsertId();
        } else {
            $companyContactId = (int)$companyContactId;
        }
        $pdo->prepare('UPDATE companies SET contact_id = :contact_id WHERE id = :id')
            ->execute(['contact_id' => $companyContactId, 'id' => $companyId]);
    }

    $contactInsert = $pdo->prepare(
        'INSERT INTO contacts (company_id, contact_type, name, email, employee) VALUES (:company_id, :contact_type, :name, :email, 1)'
    );
    $contactInsert->execute([
        'company_id' => $companyId,
        'contact_type' => 'person',
        'name' => $adminName,
        'email' => filter_var($adminLogin, FILTER_VALIDATE_EMAIL) ? $adminLogin : null,
    ]);
    $contactId = (int)$pdo->lastInsertId();

    $userInsert = $pdo->prepare(
        'INSERT INTO users (contact_id, login, password_hash, status, is_superuser) ' .
        'VALUES (:contact_id, :login, :password_hash, \'active\', 1)'
    );
    $userInsert->execute([
        'contact_id' => $contactId,
        'login' => $adminLogin,
        'password_hash' => password_hash($generatedPassword, PASSWORD_DEFAULT),
    ]);
    $userId = (int)$pdo->lastInsertId();

    $access = $pdo->prepare('INSERT INTO user_company_access (user_id, company_id, default_company) VALUES (:user_id, :company_id, 1)');
    $access->execute(['user_id' => $userId, 'company_id' => $companyId]);

    $roleInsert = $pdo->prepare('INSERT INTO access_roles (company_id, name, description, system_role) VALUES (:company_id, :name, :description, 1)');
    $roleInsert->execute(['company_id' => $companyId, 'name' => 'Administrator', 'description' => 'Initial system administrator']);
    $roleId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO user_role_links (user_id, role_id) VALUES (:user_id, :role_id)'
    )->execute(['user_id' => $userId, 'role_id' => $roleId]);

    $pdo->prepare(
        'INSERT INTO role_permission_links (role_id, permission_id) SELECT :role_id, id FROM access_permissions'
    )->execute(['role_id' => $roleId]);
    $pdo->prepare(
        'INSERT INTO audit_events (company_id, user_id, event_key, model_key, record_id, new_values) ' .
        'VALUES (:company_id, :user_id, \'bootstrap\', \'users\', :record_id, :new_values)'
    )->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'record_id' => $userId,
        'new_values' => json_encode(['login' => $adminLogin, 'contact_id' => $contactId], JSON_THROW_ON_ERROR),
    ]);

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Administrator bootstrap failed; no initial account was committed. Check the schema migration status.\n");
    error_log('Initial ERP administrator bootstrap failed: ' . $error->getMessage());
    exit(1);
}

fwrite(STDOUT, "Initial administrator created for company ID {$companyId}.\n");
fwrite(STDOUT, "Login: {$adminLogin}\n");
fwrite(STDOUT, "One-time password: {$generatedPassword}\n");
fwrite(STDOUT, "Store this password securely and rotate it after signing in. It is not saved in a file.\n");
