<?php
declare(strict_types=1);

namespace Erp\Modules\Settings;

use Erp\Core\Security\Authorization;
use Erp\Core\Security\TwoFactorAuthenticator;
use Erp\Core\Security\UserContext;
use InvalidArgumentException;
use PDO;
use Throwable;

final class UserAccessRepository
{
    public function __construct(private readonly PDO $pdo, private readonly ?string $encryptionKey = null) {}

    public function search(UserContext $actor, Authorization $auth): array
    {
        $params = [];
        $scope = '';
        if (!$actor->superuser) {
            if (!$actor->companyIds) return ['records' => [], 'total' => 0];
            $slots = [];
            foreach ($actor->companyIds as $i => $companyId) {
                $slots[] = ':company_' . $i;
                $params['company_' . $i] = $companyId;
            }
            $scope = ' AND (u.id = :actor OR EXISTS (SELECT 1 FROM user_company_access ua WHERE ua.user_id=u.id AND ua.company_id IN (' . implode(',', $slots) . ')))';
            $params['actor'] = $actor->id;
        }
        $query = $this->pdo->prepare(
            'SELECT u.id,u.contact_id,u.login,u.status,u.is_superuser,u.last_login_at,c.name,c.email,c.phone,c.mobile ' .
            'FROM users u JOIN contacts c ON c.id=u.contact_id WHERE 1=1' . $scope . ' ORDER BY c.name,u.id'
        );
        $query->execute($params);
        $users = $query->fetchAll();
        foreach ($users as &$user) {
            $user['id'] = (int)$user['id'];
            $user['is_superuser'] = (bool)$user['is_superuser'];
            $companyQuery = $this->pdo->prepare('SELECT co.id,co.name,ua.default_company FROM user_company_access ua JOIN companies co ON co.id=ua.company_id WHERE ua.user_id=:user ORDER BY ua.default_company DESC,co.name');
            $companyQuery->execute(['user' => $user['id']]);
            $user['companies'] = $companyQuery->fetchAll();
            $user['role_name'] = $user['is_superuser'] ? 'Administrator' : $this->profileRoleName($user['id']);
        }
        unset($user);
        return ['records' => $users, 'total' => count($users)];
    }

    public function options(UserContext $actor): array
    {
        return ['permissions' => $this->permissions(), 'companies' => $this->availableCompanies($actor)];
    }

    public function warehouseOptions(int $companyId, UserContext $actor, Authorization $auth): array
    {
        if (!$auth->canAccessCompany($companyId)) {
            throw new InvalidArgumentException('Company was not found in your allowed companies.');
        }
        $query = $this->pdo->prepare('SELECT id,name,code FROM warehouses WHERE company_id=:company AND active=1 ORDER BY name,id');
        $query->execute(['company' => $companyId]);
        return ['warehouses' => array_map(static fn(array $row): array => [
            'id' => (int)$row['id'], 'name' => (string)$row['name'], 'code' => (string)$row['code'],
        ], $query->fetchAll())];
    }

    public function twoFactorStatus(UserContext $actor): array
    {
        $query = $this->pdo->prepare('SELECT two_factor_enabled,two_factor_confirmed_at FROM users WHERE id=:id LIMIT 1');
        $query->execute(['id' => $actor->id]);
        $user = $query->fetch();
        if (!$user) throw new InvalidArgumentException('Your user profile could not be found.');
        $remaining = $this->pdo->prepare('SELECT COUNT(*) FROM user_two_factor_recovery_codes WHERE user_id=:user AND used_at IS NULL');
        $remaining->execute(['user' => $actor->id]);
        return ['enabled' => (bool)$user['two_factor_enabled'], 'confirmed_at' => $user['two_factor_confirmed_at'], 'recovery_codes_remaining' => (int)$remaining->fetchColumn()];
    }

    public function apiKeys(UserContext $actor): array
    {
        $query = $this->pdo->prepare('SELECT id,name,key_prefix,last_used_at,expires_at,revoked_at,created_at FROM user_api_keys WHERE user_id=:user ORDER BY revoked_at IS NULL DESC,created_at DESC,id DESC');
        $query->execute(['user' => $actor->id]);
        return ['api_keys' => array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'name'=>$row['name'],'key_prefix'=>$row['key_prefix'],'last_used_at'=>$row['last_used_at'],'expires_at'=>$row['expires_at'],'revoked_at'=>$row['revoked_at'],'created_at'=>$row['created_at']], $query->fetchAll())];
    }

    public function createApiKey(array $input, UserContext $actor, int $actorId, string $remoteAddress): array
    {
        $unknown = array_diff(array_keys($input), ['name','current_password','expires_in_days']);
        if ($unknown) throw new InvalidArgumentException('Unsupported API key fields: ' . implode(', ', $unknown));
        $name = $this->text($input['name'] ?? null, 'API key name', 120, true);
        $this->assertOwnPassword((string)($input['current_password'] ?? ''), $actor->id, $remoteAddress);
        $days = filter_var($input['expires_in_days'] ?? 90, FILTER_VALIDATE_INT);
        if ($days === false || !in_array($days, [30,90,365], true)) throw new InvalidArgumentException('Choose an expiration of 30, 90, or 365 days.');
        $token = 'erp_' . bin2hex(random_bytes(32));
        $prefix = substr($token, 0, 12);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($days * 86400));
        $this->pdo->beginTransaction();
        try {
            $owner = $this->pdo->prepare("SELECT id FROM users WHERE id=:user AND status IN ('confirmed','active') LIMIT 1 FOR UPDATE");
            $owner->execute(['user'=>$actor->id]);
            if ($owner->fetchColumn() === false) throw new InvalidArgumentException('Only an active user can create API keys.');
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM user_api_keys WHERE user_id=:user AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())');
            $count->execute(['user'=>$actor->id]);
            if ((int)$count->fetchColumn() >= 50) throw new InvalidArgumentException('An account can have at most 50 active API keys. Revoke an unused key first.');
            $this->pdo->prepare('INSERT INTO user_api_keys (user_id,name,key_prefix,key_hash,expires_at) VALUES (:user,:name,:prefix,:hash,:expires)')
                ->execute(['user'=>$actor->id,'name'=>$name,'prefix'=>$prefix,'hash'=>hash('sha256',$token),'expires'=>$expiresAt]);
            $id = (int)$this->pdo->lastInsertId();
            $this->auditSecurityAction($actorId,'user_api_key_created','user_api_key',$id,[],['name'=>$name,'key_prefix'=>$prefix,'expires_at'=>$expiresAt],$remoteAddress);
            $this->pdo->commit();
            return ['api_key'=>['id'=>$id,'name'=>$name,'key_prefix'=>$prefix,'expires_at'=>$expiresAt,'token'=>$token]];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function revokeApiKey(int $id, UserContext $actor, int $actorId, string $remoteAddress): void
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT name,key_prefix,revoked_at FROM user_api_keys WHERE id=:id AND user_id=:user LIMIT 1 FOR UPDATE');
            $query->execute(['id'=>$id,'user'=>$actor->id]);
            $key = $query->fetch();
            if (!$key) throw new InvalidArgumentException('API key not found on your account.');
            if ($key['revoked_at'] === null) {
                $this->pdo->prepare('UPDATE user_api_keys SET revoked_at=UTC_TIMESTAMP() WHERE id=:id AND user_id=:user AND revoked_at IS NULL')->execute(['id'=>$id,'user'=>$actor->id]);
                $this->auditSecurityAction($actorId,'user_api_key_revoked','user_api_key',$id,['revoked'=>false],['revoked'=>true,'name'=>$key['name'],'key_prefix'=>$key['key_prefix']],$remoteAddress);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function beginTwoFactorEnrollment(string $password, UserContext $actor, string $issuer, string $remoteAddress): array
    {
        $this->assertEncryptionKey();
        $this->assertOwnPassword($password, $actor->id, $remoteAddress);
        $query = $this->pdo->prepare("SELECT login,status,two_factor_enabled FROM users WHERE id=:id LIMIT 1");
        $query->execute(['id' => $actor->id]);
        $user = $query->fetch();
        if (!$user || !in_array($user['status'], ['confirmed', 'active'], true)) throw new InvalidArgumentException('Only an active account can enroll in two-factor authentication.');
        if ((bool)$user['two_factor_enabled']) throw new InvalidArgumentException('Two-factor authentication is already enabled.');
        $secret = TwoFactorAuthenticator::generateSecret();
        $_SESSION['pending_two_factor_enrollment_secret'] = $secret;
        $_SESSION['pending_two_factor_enrollment_user_id'] = $actor->id;
        $_SESSION['pending_two_factor_enrollment_expires_at'] = time() + 600;
        return ['secret' => $secret, 'provisioning_uri' => TwoFactorAuthenticator::provisioningUri($secret, (string)$user['login'], $issuer), 'expires_in' => 600];
    }

    public function confirmTwoFactorEnrollment(string $code, UserContext $actor, int $actorId, string $remoteAddress): array
    {
        $this->assertEncryptionKey();
        if (!$this->twoFactorAttemptsAllowed($actor->id, $remoteAddress)) throw new InvalidArgumentException('Too many security-code attempts. Wait 15 minutes and try again.');
        $secret = $_SESSION['pending_two_factor_enrollment_secret'] ?? null;
        $pendingUser = filter_var($_SESSION['pending_two_factor_enrollment_user_id'] ?? null, FILTER_VALIDATE_INT);
        $expiresAt = filter_var($_SESSION['pending_two_factor_enrollment_expires_at'] ?? null, FILTER_VALIDATE_INT);
        if (!is_string($secret) || $pendingUser === false || $pendingUser !== $actor->id || $expiresAt === false || $expiresAt === null || $expiresAt < time()) {
            $this->clearPendingEnrollment();
            throw new InvalidArgumentException('Authenticator setup expired. Start enrollment again.');
        }
        if (!TwoFactorAuthenticator::verifyCode($secret, $code)) {
            $this->recordTwoFactorAttempt($actor->id, $remoteAddress, false);
            throw new InvalidArgumentException('The authenticator code is incorrect or expired.');
        }
        $codes = TwoFactorAuthenticator::recoveryCodes();
        $encrypted = TwoFactorAuthenticator::encryptSecret($secret, (string)$this->encryptionKey);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare("SELECT two_factor_enabled,status FROM users WHERE id=:id LIMIT 1 FOR UPDATE");
            $lock->execute(['id' => $actor->id]);
            $user = $lock->fetch();
            if (!$user || !in_array($user['status'], ['confirmed', 'active'], true) || (bool)$user['two_factor_enabled']) throw new InvalidArgumentException('This account can no longer complete authenticator enrollment.');
            $this->pdo->prepare('UPDATE users SET two_factor_enabled=1,two_factor_secret=:secret,two_factor_confirmed_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['secret' => $encrypted, 'id' => $actor->id]);
            $insert = $this->pdo->prepare('INSERT INTO user_two_factor_recovery_codes (user_id,code_hash) VALUES (:user,:hash)');
            foreach ($codes as $recovery) $insert->execute(['user' => $actor->id, 'hash' => hash('sha256', TwoFactorAuthenticator::normalizeRecoveryCode($recovery))]);
            $this->auditSecurityAction($actorId, 'user_two_factor_enabled', 'user', $actor->id, ['enabled' => false], ['enabled' => true, 'recovery_codes_issued' => count($codes)], $remoteAddress);
            $this->pdo->commit();
            $this->recordTwoFactorAttempt($actor->id, $remoteAddress, true);
            $this->clearPendingEnrollment();
            return ['enabled' => true, 'confirmed_at' => gmdate('Y-m-d H:i:s'), 'recovery_codes' => $codes];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function disableTwoFactor(string $password, string $code, UserContext $actor, int $actorId, string $remoteAddress): array
    {
        $this->assertOwnPassword($password, $actor->id, $remoteAddress);
        if (!$this->twoFactorAttemptsAllowed($actor->id, $remoteAddress)) throw new InvalidArgumentException('Too many security-code attempts. Wait 15 minutes and try again.');
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare("SELECT two_factor_enabled,two_factor_secret FROM users WHERE id=:id AND status IN ('confirmed','active') LIMIT 1 FOR UPDATE");
            $query->execute(['id' => $actor->id]);
            $user = $query->fetch();
            if (!$user || !(bool)$user['two_factor_enabled'] || !is_string($user['two_factor_secret'])) throw new InvalidArgumentException('Two-factor authentication is not enabled on this account.');
            $valid = false;
            if ($this->encryptionKey !== null && function_exists('openssl_decrypt')) {
                try {
                    $secret = TwoFactorAuthenticator::decryptSecret($user['two_factor_secret'], $this->encryptionKey);
                    $valid = TwoFactorAuthenticator::verifyCode($secret, $code);
                } catch (\InvalidArgumentException) {
                    // Recovery codes remain available after an encryption-key rotation or loss.
                }
            } elseif ($this->encryptionKey !== null && function_exists('sodium_crypto_secretbox_open')) {
                try {
                    $secret = TwoFactorAuthenticator::decryptSecret($user['two_factor_secret'], $this->encryptionKey);
                    $valid = TwoFactorAuthenticator::verifyCode($secret, $code);
                } catch (\InvalidArgumentException) {
                    // Recovery codes remain available after an encryption-key rotation or loss.
                }
            }
            if (!$valid) $valid = $this->consumeRecoveryCodeInTransaction($actor->id, $code);
            if (!$valid) {
                $this->pdo->rollBack();
                $this->recordTwoFactorAttempt($actor->id, $remoteAddress, false);
                throw new InvalidArgumentException('Enter a current authenticator code or an unused recovery code.');
            }
            $this->pdo->prepare('DELETE FROM user_two_factor_recovery_codes WHERE user_id=:user')->execute(['user' => $actor->id]);
            $this->pdo->prepare('UPDATE users SET two_factor_enabled=0,two_factor_secret=NULL,two_factor_confirmed_at=NULL WHERE id=:id')->execute(['id' => $actor->id]);
            $this->auditSecurityAction($actorId, 'user_two_factor_disabled', 'user', $actor->id, ['enabled' => true], ['enabled' => false], $remoteAddress);
            $this->pdo->commit();
            $this->recordTwoFactorAttempt($actor->id, $remoteAddress, true);
            return ['enabled' => false, 'recovery_codes_remaining' => 0];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function consumeRecoveryCodeInTransaction(int $userId, string $code): bool
    {
        $normalized = TwoFactorAuthenticator::normalizeRecoveryCode($code);
        if (preg_match('/^[A-Z2-7]{12}$/', $normalized) !== 1) return false;
        $query = $this->pdo->prepare('SELECT id FROM user_two_factor_recovery_codes WHERE user_id=:user AND code_hash=:hash AND used_at IS NULL LIMIT 1 FOR UPDATE');
        $query->execute(['user' => $userId, 'hash' => hash('sha256', $normalized)]);
        $id = $query->fetchColumn();
        if ($id === false) return false;
        $this->pdo->prepare('UPDATE user_two_factor_recovery_codes SET used_at=UTC_TIMESTAMP() WHERE id=:id AND used_at IS NULL')->execute(['id' => $id]);
        return true;
    }

    private function assertOwnPassword(string $password, int $userId, string $remoteAddress): void
    {
        if ($password === '' || strlen($password) > 4096) throw new InvalidArgumentException('Enter your current password to continue.');
        $query = $this->pdo->prepare("SELECT login,password_hash,status FROM users WHERE id=:id LIMIT 1");
        $query->execute(['id' => $userId]);
        $user = $query->fetch();
        if (!$user || !in_array($user['status'], ['confirmed','active'], true) || !password_verify($password, (string)$user['password_hash'])) {
            if ($user) $this->recordPasswordFailure((string)$user['login'], $remoteAddress);
            throw new InvalidArgumentException('The current password is incorrect.');
        }
    }

    private function twoFactorAttemptsAllowed(int $userId, string $remoteAddress): bool
    {
        $query = $this->pdo->prepare('SELECT login FROM users WHERE id=:id LIMIT 1');
        $query->execute(['id' => $userId]);
        $login = $query->fetchColumn();
        if (!is_string($login)) return false;
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $attempts = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE login_hash=:login AND ip_address <=> :ip AND succeeded=0 AND attempted_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)');
        $attempts->bindValue(':login', hash('sha256', strtolower(trim($login))));
        $attempts->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $attempts->execute();
        return (int)$attempts->fetchColumn() < 8;
    }

    private function recordTwoFactorAttempt(int $userId, string $remoteAddress, bool $succeeded): void
    {
        $query = $this->pdo->prepare('SELECT login FROM users WHERE id=:id LIMIT 1');
        $query->execute(['id' => $userId]);
        $login = $query->fetchColumn();
        if (!is_string($login)) return;
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $insert = $this->pdo->prepare('INSERT INTO login_attempts (login_hash,ip_address,succeeded) VALUES (:login,:ip,:success)');
        $insert->bindValue(':login', hash('sha256', strtolower(trim($login))));
        $insert->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $insert->bindValue(':success', $succeeded ? 1 : 0, PDO::PARAM_INT);
        $insert->execute();
    }

    private function assertEncryptionKey(): void
    {
        if ((!function_exists('sodium_crypto_secretbox') && !function_exists('openssl_encrypt')) || $this->encryptionKey === null || strlen($this->encryptionKey) !== 32) {
            throw new InvalidArgumentException('Set ERP_DATA_ENCRYPTION_KEY to a private 32-byte key before configuring authenticator-based two-factor authentication.');
        }
    }

    private function clearPendingEnrollment(): void
    {
        unset($_SESSION['pending_two_factor_enrollment_secret'], $_SESSION['pending_two_factor_enrollment_user_id'], $_SESSION['pending_two_factor_enrollment_expires_at']);
    }

    public function ownSessions(UserContext $actor, int $limit = 40, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(1000000, $offset));
        $currentHash = session_id() !== '' ? hash('sha256', session_id()) : '';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id=:user AND revoked_at IS NULL');
        $count->execute(['user' => $actor->id]);
        $total = (int)$count->fetchColumn();
        $query = $this->pdo->prepare(
            'SELECT id,session_hash,user_agent,ip_address,created_at,last_seen_at ' .
            'FROM user_sessions WHERE user_id=:user AND revoked_at IS NULL ORDER BY last_seen_at DESC,id DESC LIMIT :limit OFFSET :offset'
        );
        $query->bindValue(':user', $actor->id, PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        $sessions = [];
        foreach ($query->fetchAll() as $row) {
            $sessions[] = [
                'id' => (int)$row['id'],
                'user_agent' => (string)$row['user_agent'],
                'ip_address' => is_string($row['ip_address']) && $row['ip_address'] !== '' ? (inet_ntop($row['ip_address']) ?: '') : '',
                'created_at' => $row['created_at'],
                'last_seen_at' => $row['last_seen_at'],
                'is_current' => $currentHash !== '' && hash_equals($currentHash, (string)$row['session_hash']),
            ];
        }
        return ['sessions' => $sessions, 'total' => $total, 'offset' => $offset, 'limit' => $limit];
    }

    public function revokeOwnSession(int $sessionRecordId, UserContext $actor, int $actorId, string $remoteAddress): void
    {
        $currentHash = session_id() !== '' ? hash('sha256', session_id()) : '';
        $query = $this->pdo->prepare('SELECT session_hash FROM user_sessions WHERE id=:id AND user_id=:user AND revoked_at IS NULL LIMIT 1');
        $query->execute(['id' => $sessionRecordId, 'user' => $actor->id]);
        $sessionHash = $query->fetchColumn();
        if (!is_string($sessionHash)) throw new InvalidArgumentException('Active device session was not found.');
        if ($currentHash !== '' && hash_equals($currentHash, $sessionHash)) {
            throw new InvalidArgumentException('Use Log out from the profile menu to end this current session.');
        }
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE user_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=:id AND user_id=:user AND revoked_at IS NULL');
            $update->execute(['id' => $sessionRecordId, 'user' => $actor->id]);
            if ($update->rowCount() === 1) $this->auditSecurityAction($actorId, 'user_session_revoked', 'user_session', $sessionRecordId, ['revoked' => false], ['revoked' => true], $remoteAddress);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function changeOwnPassword(array $input, UserContext $actor, string $remoteAddress): array
    {
        foreach (['current_password', 'new_password', 'confirm_password'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) throw new InvalidArgumentException('Enter your current password and both new password fields.');
        }
        $currentPassword = $input['current_password'];
        $newPassword = $input['new_password'];
        $confirmPassword = $input['confirm_password'];
        $newLength = function_exists('mb_strlen') ? mb_strlen($newPassword, 'UTF-8') : strlen($newPassword);
        if ($currentPassword === '' || strlen($currentPassword) > 4096) throw new InvalidArgumentException('Enter your current password.');
        if ($newLength < 12 || strlen($newPassword) > 4096) throw new InvalidArgumentException('New password must contain at least 12 characters and no more than 4096 bytes.');
        if (!hash_equals($newPassword, $confirmPassword)) throw new InvalidArgumentException('New password and confirmation do not match.');
        if (hash_equals($currentPassword, $newPassword)) throw new InvalidArgumentException('Choose a password different from your current password.');

        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT login,password_hash,status FROM users WHERE id=:id LIMIT 1 FOR UPDATE');
            $query->execute(['id' => $actor->id]);
            $user = $query->fetch();
            if (!$user || !in_array($user['status'], ['confirmed', 'active'], true)) throw new InvalidArgumentException('This active user account could not be verified.');
            if (!password_verify($currentPassword, (string)$user['password_hash'])) {
                $this->recordPasswordFailure((string)$user['login'], $remoteAddress);
                $this->pdo->commit();
                throw new InvalidArgumentException('Current password is incorrect.');
            }

            $sessionId = session_id();
            if ($sessionId === '') throw new InvalidArgumentException('The active session could not be verified. Sign in again and retry.');
            $sessionHash = hash('sha256', $sessionId);
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->pdo->prepare('UPDATE users SET password_hash=:hash WHERE id=:id')->execute(['hash' => $hash, 'id' => $actor->id]);
            $revoke = $this->pdo->prepare(
                'UPDATE user_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=:user AND session_hash<>:current AND revoked_at IS NULL'
            );
            $revoke->execute(['user' => $actor->id, 'current' => $sessionHash]);
            $revoked = $revoke->rowCount();
            $this->auditSecurityAction($actor->id, 'user_password_changed', 'user', $actor->id, [], ['other_sessions_revoked' => $revoked], $remoteAddress);
            $this->pdo->commit();
            return ['other_sessions_revoked' => $revoked];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function updateOwnPreferences(array $input, UserContext $actor, Authorization $auth, string $remoteAddress): array
    {
        $allowed = ['language_code','theme','notification_preference','email_signature','live_chat_name','spoken_languages','expertise','calendar_default_view','calendar_show_weekends','default_warehouse_id'];
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown) throw new InvalidArgumentException('Unsupported preference fields: ' . implode(', ', $unknown));
        $language = $this->text($input['language_code'] ?? 'en_US', 'Language', 12, true);
        if (preg_match('/^[A-Za-z]{2}(?:_[A-Za-z]{2})?$/', $language) !== 1) throw new InvalidArgumentException('Language must use a locale code such as en_US.');
        $theme = (string)($input['theme'] ?? 'system');
        if (!in_array($theme, ['system','light','dark'], true)) throw new InvalidArgumentException('Choose System, Light, or Dark theme.');
        $notification = (string)($input['notification_preference'] ?? 'email');
        if (!in_array($notification, ['email','in_app'], true)) throw new InvalidArgumentException('Choose Email or In Odoo notifications.');
        $signature = $this->text($input['email_signature'] ?? null, 'Email Signature', 10000);
        $liveChatName = $this->text($input['live_chat_name'] ?? null, 'Live Chat Name', 120);
        $spokenLanguages = $this->stringList($input['spoken_languages'] ?? [], 'Spoken Languages', 20, 12);
        $expertise = $this->stringList($input['expertise'] ?? [], 'Expertise', 30, 80);
        $calendarDefaultView = (string)($input['calendar_default_view'] ?? 'month');
        if (!in_array($calendarDefaultView, ['day','week','month','year'], true)) throw new InvalidArgumentException('Choose Day, Week, Month, or Year as the Calendar default view.');
        $calendarShowWeekends = $this->boolean($input['calendar_show_weekends'] ?? true, 'Show Weekends');
        $defaultWarehouseId = $this->nullablePositiveId($input['default_warehouse_id'] ?? null, 'Default Warehouse');
        $this->pdo->beginTransaction();
        try {
        $query = $this->pdo->prepare('SELECT u.language_code,u.theme,u.notification_preference,u.email_signature,u.live_chat_name,u.spoken_languages,u.expertise,u.calendar_default_view,u.calendar_show_weekends,u.default_warehouse_id,ua.company_id AS default_company_id FROM users u LEFT JOIN user_company_access ua ON ua.user_id=u.id AND ua.default_company=1 WHERE u.id=:id LIMIT 1 FOR UPDATE');
        $query->execute(['id' => $actor->id]);
        $old = $query->fetch();
        if (!$old) throw new InvalidArgumentException('Your user profile could not be found.');
        $defaultCompanyId = $old['default_company_id'] === null ? null : (int)$old['default_company_id'];
        $this->validateDefaultWarehouse(
            $defaultWarehouseId,
            $defaultCompanyId,
            $old['default_warehouse_id'] === null ? null : (int)$old['default_warehouse_id'],
            $defaultCompanyId
        );
        $this->pdo->prepare(
            'UPDATE users SET language_code=:language,theme=:theme,notification_preference=:notification,email_signature=:signature,' .
            'live_chat_name=:chat,spoken_languages=:spoken,expertise=:expertise,calendar_default_view=:calendar_view,calendar_show_weekends=:calendar_weekends,default_warehouse_id=:warehouse WHERE id=:id'
        )->execute([
            'language' => $language, 'theme' => $theme, 'notification' => $notification, 'signature' => $signature,
            'chat' => $liveChatName, 'spoken' => json_encode($spokenLanguages, JSON_THROW_ON_ERROR),
            'expertise' => json_encode($expertise, JSON_THROW_ON_ERROR), 'calendar_view' => $calendarDefaultView,
            'calendar_weekends' => $calendarShowWeekends ? 1 : 0, 'warehouse' => $defaultWarehouseId, 'id' => $actor->id,
        ]);
        $this->auditSecurityAction($actor->id, 'user_preferences_updated', 'user', $actor->id,
            ['language_code'=>$old['language_code'],'theme'=>$old['theme'],'notification_preference'=>$old['notification_preference'],'email_signature_set'=>$old['email_signature']!==null,'live_chat_name'=>$old['live_chat_name'],'spoken_languages'=>$old['spoken_languages']?json_decode((string)$old['spoken_languages'],true):[],'expertise'=>$old['expertise']?json_decode((string)$old['expertise'],true):[],'calendar_default_view'=>$old['calendar_default_view'],'calendar_show_weekends'=>(bool)$old['calendar_show_weekends'],'default_warehouse_id'=>$old['default_warehouse_id']===null?null:(int)$old['default_warehouse_id']],
            ['language_code'=>$language,'theme'=>$theme,'notification_preference'=>$notification,'email_signature_set'=>$signature!==null,'live_chat_name'=>$liveChatName,'spoken_languages'=>$spokenLanguages,'expertise'=>$expertise,'calendar_default_view'=>$calendarDefaultView,'calendar_show_weekends'=>$calendarShowWeekends,'default_warehouse_id'=>$defaultWarehouseId],$remoteAddress);
        $profile = $this->find($actor->id, $actor, $auth) ?? throw new InvalidArgumentException('Your preferences could not be reloaded.');
        $this->pdo->commit();
        return $profile;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function find(int $id, UserContext $actor, Authorization $auth): ?array
    {
        if (!$this->isVisible($id, $actor, $auth)) return null;
        $query = $this->pdo->prepare(
            'SELECT u.id,u.contact_id,u.login,u.status,u.is_superuser,u.two_factor_enabled,u.last_login_at,u.language_code,u.theme,u.notification_preference,u.email_signature,u.live_chat_name,u.spoken_languages,u.expertise,u.calendar_default_view,u.calendar_show_weekends,u.default_warehouse_id,c.name,c.email,c.phone,c.mobile ' .
            'FROM users u JOIN contacts c ON c.id=u.contact_id WHERE u.id=:id LIMIT 1'
        );
        $query->execute(['id' => $id]);
        $user = $query->fetch();
        if (!$user) return null;
        $user['id'] = (int)$user['id'];
        $user['contact_id'] = (int)$user['contact_id'];
        $user['is_superuser'] = (bool)$user['is_superuser'];
        $user['two_factor_enabled'] = (bool)$user['two_factor_enabled'];
        $user['calendar_show_weekends'] = (bool)$user['calendar_show_weekends'];
        $user['default_warehouse_id'] = $user['default_warehouse_id'] === null ? null : (int)$user['default_warehouse_id'];
        $user['spoken_languages'] = $user['spoken_languages'] ? json_decode((string)$user['spoken_languages'], true) : [];
        $user['expertise'] = $user['expertise'] ? json_decode((string)$user['expertise'], true) : [];
        $companies = $this->pdo->prepare('SELECT co.id,co.name,ua.default_company FROM user_company_access ua JOIN companies co ON co.id=ua.company_id WHERE ua.user_id=:user ORDER BY ua.default_company DESC,co.name');
        $companies->execute(['user' => $id]);
        $user['companies'] = $companies->fetchAll();
        $user['default_company_id'] = null;
        foreach ($user['companies'] as &$company) {
            $company['id'] = (int)$company['id'];
            $company['default_company'] = (bool)$company['default_company'];
            if ($company['default_company']) $user['default_company_id'] = $company['id'];
        }
        unset($company);
        $permissions = $this->pdo->prepare(
            'SELECT DISTINCT p.permission_key,p.module_key,p.description FROM user_role_links ur ' .
            'JOIN access_roles r ON r.id=ur.role_id AND r.active=1 ' .
            'JOIN role_permission_links rp ON rp.role_id=r.id ' .
            'JOIN access_permissions p ON p.id=rp.permission_id WHERE ur.user_id=:user ORDER BY p.module_key,p.permission_key'
        );
        $permissions->execute(['user' => $id]);
        $user['permission_keys'] = array_column($permissions->fetchAll(), 'permission_key');
        $user['role_name'] = $user['is_superuser'] ? 'Administrator' : $this->profileRoleName($id);
        $user['read_only'] = $user['is_superuser'];
        $user['available_companies'] = $this->availableCompanies($actor);
        return $user;
    }

    public function create(array $input, UserContext $actor, Authorization $auth, int $actorId, string $ip): array
    {
        $allowed = ['name','login','email','phone','mobile','company_ids','default_company_id','default_warehouse_id','permission_keys','language_code','theme','notification_preference','email_signature','live_chat_name','spoken_languages','expertise','calendar_default_view','calendar_show_weekends'];
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown) throw new InvalidArgumentException('Unsupported User Profile fields: ' . implode(', ', $unknown));
        $name = $this->text($input['name'] ?? null, 'Name', 190, true);
        $login = mb_strtolower((string)$this->text($input['login'] ?? null, 'Login', 190, true));
        $email = $this->text($input['email'] ?? null, 'Email', 190);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Enter a valid email address.');
        $phone = $this->text($input['phone'] ?? null, 'Phone', 80);
        $mobile = $this->text($input['mobile'] ?? null, 'Mobile', 80);
        $companyIds = $this->ids($input['company_ids'] ?? null, 'Companies');
        if (!$companyIds) throw new InvalidArgumentException('Select at least one allowed company for this user.');
        foreach ($companyIds as $companyId) if (!$auth->canAccessCompany($companyId)) throw new InvalidArgumentException('You cannot grant a company that you cannot access.');
        $rawDefaultCompany = $input['default_company_id'] ?? null;
        $defaultCompanyId = $rawDefaultCompany === null || $rawDefaultCompany === '' ? $companyIds[0] : $this->positiveId($rawDefaultCompany, 'Default Company');
        if (!in_array($defaultCompanyId, $companyIds, true)) throw new InvalidArgumentException('Default Company must be included in the allowed company list.');
        $defaultWarehouseId = $this->nullablePositiveId($input['default_warehouse_id'] ?? null, 'Default Warehouse');
        $this->validateDefaultWarehouse($defaultWarehouseId, $defaultCompanyId);
        $permissionKeys = $this->permissionKeys($input['permission_keys'] ?? null);
        $language = $this->text($input['language_code'] ?? 'en_US', 'Language', 12, true);
        $theme = (string)($input['theme'] ?? 'system');
        if (!in_array($theme, ['system','light','dark'], true)) throw new InvalidArgumentException('Choose System, Light, or Dark theme.');
        $notification = (string)($input['notification_preference'] ?? 'email');
        if (!in_array($notification, ['email','in_app'], true)) throw new InvalidArgumentException('Choose Email or In Odoo notifications.');
        $signature = $this->text($input['email_signature'] ?? null, 'Email Signature', 10000);
        $liveChatName = $this->text($input['live_chat_name'] ?? null, 'Live Chat Name', 120);
        $spokenLanguages = $this->stringList($input['spoken_languages'] ?? [], 'Spoken Languages', 20, 12);
        $expertise = $this->stringList($input['expertise'] ?? [], 'Expertise', 30, 80);
        $calendarDefaultView = (string)($input['calendar_default_view'] ?? 'month');
        if (!in_array($calendarDefaultView, ['day','week','month','year'], true)) throw new InvalidArgumentException('Choose Day, Week, Month, or Year as the Calendar default view.');
        $calendarShowWeekends = $this->boolean($input['calendar_show_weekends'] ?? true, 'Show Weekends');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO contacts (company_id,contact_type,name,email,phone,mobile,language_code,active) VALUES (:company,:type,:name,:email,:phone,:mobile,:language,1)')
                ->execute(['company'=>$defaultCompanyId,'type'=>'person','name'=>$name,'email'=>$email,'phone'=>$phone,'mobile'=>$mobile,'language'=>$language]);
            $contactId = (int)$this->pdo->lastInsertId();
            $randomPassword = bin2hex(random_bytes(32));
            $this->pdo->prepare('INSERT INTO users (contact_id,login,password_hash,status,is_superuser,language_code,theme,notification_preference,email_signature,live_chat_name,spoken_languages,expertise,calendar_default_view,calendar_show_weekends,default_warehouse_id) VALUES (:contact,:login,:password,:status,0,:language,:theme,:notification,:signature,:chat,:spoken,:expertise,:calendar_view,:calendar_weekends,:warehouse)')
                ->execute(['contact'=>$contactId,'login'=>$login,'password'=>password_hash($randomPassword,PASSWORD_DEFAULT),'status'=>'invited','language'=>$language,'theme'=>$theme,'notification'=>$notification,'signature'=>$signature,'chat'=>$liveChatName,'spoken'=>json_encode($spokenLanguages,JSON_THROW_ON_ERROR),'expertise'=>json_encode($expertise,JSON_THROW_ON_ERROR),'calendar_view'=>$calendarDefaultView,'calendar_weekends'=>$calendarShowWeekends?1:0,'warehouse'=>$defaultWarehouseId]);
            $userId = (int)$this->pdo->lastInsertId();
            $companyInsert = $this->pdo->prepare('INSERT INTO user_company_access (user_id,company_id,default_company) VALUES (:user,:company,:is_default)');
            foreach ($companyIds as $companyId) $companyInsert->execute(['user'=>$userId,'company'=>$companyId,'is_default'=>$defaultCompanyId===$companyId?1:0]);
            $this->savePrivateProfileRole($userId, $permissionKeys);
            $this->audit($actorId,$userId,[],[
                'name'=>$name,'login'=>$login,'email'=>$email,'phone'=>$phone,'mobile'=>$mobile,
                'company_ids'=>$companyIds,'default_company_id'=>$defaultCompanyId,'permission_keys'=>$permissionKeys,
                'language_code'=>$language,'theme'=>$theme,'notification_preference'=>$notification,
                'email_signature'=>$signature,'live_chat_name'=>$liveChatName,
                'spoken_languages'=>$spokenLanguages,'expertise'=>$expertise,'calendar_default_view'=>$calendarDefaultView,'calendar_show_weekends'=>$calendarShowWeekends,'default_warehouse_id'=>$defaultWarehouseId,'status'=>'invited',
            ],$ip);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($userId,$actor,$auth) ?? throw new InvalidArgumentException('User was created but could not be reloaded.');
    }

    public function update(int $id, array $input, UserContext $actor, Authorization $auth, int $actorId, string $ip): array
    {
        $allowed = ['name','login','email','phone','mobile','company_ids','default_company_id','default_warehouse_id','permission_keys','language_code','theme','notification_preference','email_signature','live_chat_name','spoken_languages','expertise','calendar_default_view','calendar_show_weekends'];
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown) throw new InvalidArgumentException('Unsupported User Profile fields: ' . implode(', ', $unknown));
        if (!$this->isVisible($id, $actor, $auth)) throw new InvalidArgumentException('User was not found in your allowed companies.');
        if ($id === $actorId && (bool)$this->isSuperuser($id)) throw new InvalidArgumentException('The active superuser profile is read-only.');

        $name = $this->text($input['name'] ?? null, 'Name', 190, true);
        $login = mb_strtolower((string)$this->text($input['login'] ?? null, 'Login', 190, true));
        $email = $this->text($input['email'] ?? null, 'Email', 190);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Enter a valid email address.');
        $phone = $this->text($input['phone'] ?? null, 'Phone', 80);
        $mobile = $this->text($input['mobile'] ?? null, 'Mobile', 80);
        $oldAccessQuery=$this->pdo->prepare('SELECT company_id,default_company FROM user_company_access WHERE user_id=:user');$oldAccessQuery->execute(['user'=>$id]);$oldAccess=$oldAccessQuery->fetchAll();$oldDefault=null;$preservedCompanyIds=[];
        foreach($oldAccess as $row){$oldCompany=(int)$row['company_id'];if((int)$row['default_company']===1)$oldDefault=$oldCompany;if(!$auth->canAccessCompany($oldCompany))$preservedCompanyIds[]=$oldCompany;}
        $companyIds = $this->ids($input['company_ids'] ?? null, 'Companies');
        foreach ($companyIds as $companyId) if (!$auth->canAccessCompany($companyId)) throw new InvalidArgumentException('You cannot grant a company that you cannot access.');
        $companyIds=array_values(array_unique(array_merge($companyIds,$preservedCompanyIds)));
        if (!$companyIds && !$this->isSuperuser($id)) throw new InvalidArgumentException('Select at least one allowed company for this user.');
        $rawDefaultCompany = $input['default_company_id'] ?? null;
        $defaultCompanyId = $rawDefaultCompany === null || $rawDefaultCompany === '' ? (in_array($oldDefault,$preservedCompanyIds,true)?$oldDefault:null) : $this->positiveId($rawDefaultCompany, 'Default Company');
        if ($defaultCompanyId !== null && !$auth->canAccessCompany($defaultCompanyId) && $defaultCompanyId!==$oldDefault) throw new InvalidArgumentException('You cannot set a default company that you cannot access.');
        if ($defaultCompanyId !== null && !in_array($defaultCompanyId, $companyIds, true)) throw new InvalidArgumentException('Default Company must be included in the allowed company list.');
        $defaultWarehouseId = $this->nullablePositiveId($input['default_warehouse_id'] ?? null, 'Default Warehouse');
        $permissionKeys = $this->permissionKeys($input['permission_keys'] ?? null);
        if ($id === $actorId && (!in_array('settings.read', $permissionKeys, true) || !in_array('settings.write', $permissionKeys, true))) {
            throw new InvalidArgumentException('Keep Settings Read and Write access on your own profile to avoid locking yourself out.');
        }
        $language = $this->text($input['language_code'] ?? 'en_US', 'Language', 12, true);
        $theme = (string)($input['theme'] ?? 'system');
        if (!in_array($theme, ['system','light','dark'], true)) throw new InvalidArgumentException('Choose System, Light, or Dark theme.');
        $notification = (string)($input['notification_preference'] ?? 'email');
        if (!in_array($notification, ['email','in_app'], true)) throw new InvalidArgumentException('Choose Email or In Odoo notifications.');
        $signature = $this->text($input['email_signature'] ?? null, 'Email Signature', 10000);
        $liveChatName = $this->text($input['live_chat_name'] ?? null, 'Live Chat Name', 120);
        $spokenLanguages = $this->stringList($input['spoken_languages'] ?? [], 'Spoken Languages', 20, 12);
        $expertise = $this->stringList($input['expertise'] ?? [], 'Expertise', 30, 80);
        $calendarDefaultView = (string)($input['calendar_default_view'] ?? 'month');
        if (!in_array($calendarDefaultView, ['day','week','month','year'], true)) throw new InvalidArgumentException('Choose Day, Week, Month, or Year as the Calendar default view.');
        $calendarShowWeekends = $this->boolean($input['calendar_show_weekends'] ?? true, 'Show Weekends');

        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare('SELECT u.contact_id,u.is_superuser,u.default_warehouse_id,c.name,c.email,c.phone,c.mobile,u.login,u.language_code,u.theme,u.notification_preference,u.email_signature,u.live_chat_name,u.spoken_languages,u.expertise,u.calendar_default_view,u.calendar_show_weekends FROM users u JOIN contacts c ON c.id=u.contact_id WHERE u.id=:id FOR UPDATE');
            $target->execute(['id' => $id]);
            $old = $target->fetch();
            if (!$old) throw new InvalidArgumentException('User was not found.');
            if ((bool)$old['is_superuser']) throw new InvalidArgumentException('Superuser access is managed outside the user profile editor.');
            $this->validateDefaultWarehouse(
                $defaultWarehouseId,
                $defaultCompanyId,
                $old['default_warehouse_id'] === null ? null : (int)$old['default_warehouse_id'],
                $oldDefault
            );
            $oldPermissionQuery = $this->pdo->prepare(
                'SELECT DISTINCT p.permission_key FROM user_role_links ur ' .
                'JOIN access_roles r ON r.id=ur.role_id AND r.active=1 ' .
                'JOIN role_permission_links rp ON rp.role_id=r.id ' .
                'JOIN access_permissions p ON p.id=rp.permission_id WHERE ur.user_id=:user ORDER BY p.permission_key'
            );
            $oldPermissionQuery->execute(['user' => $id]);
            $oldPermissionKeys = $oldPermissionQuery->fetchAll(PDO::FETCH_COLUMN);
            $oldAudit = [
                'name' => $old['name'], 'login' => $old['login'], 'email' => $old['email'],
                'phone' => $old['phone'], 'mobile' => $old['mobile'],
                'company_ids' => array_map(static fn(array $row): int => (int)$row['company_id'], $oldAccess),
                'default_company_id' => $oldDefault, 'permission_keys' => $oldPermissionKeys,
                'language_code' => $old['language_code'], 'theme' => $old['theme'],
                'notification_preference' => $old['notification_preference'],
                'email_signature' => $old['email_signature'], 'live_chat_name' => $old['live_chat_name'],
                'spoken_languages' => $old['spoken_languages'] ? json_decode((string)$old['spoken_languages'], true) : [],
                'expertise' => $old['expertise'] ? json_decode((string)$old['expertise'], true) : [],
                'calendar_default_view' => $old['calendar_default_view'], 'calendar_show_weekends' => (bool)$old['calendar_show_weekends'],
                'default_warehouse_id' => $old['default_warehouse_id'] === null ? null : (int)$old['default_warehouse_id'],
            ];
            $contactId = (int)$old['contact_id'];
            $this->pdo->prepare('UPDATE contacts SET name=:name,email=:email,phone=:phone,mobile=:mobile,updated_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['name'=>$name,'email'=>$email,'phone'=>$phone,'mobile'=>$mobile,'id'=>$contactId]);
            $this->pdo->prepare('UPDATE users SET login=:login,language_code=:language,theme=:theme,notification_preference=:notification,email_signature=:signature,live_chat_name=:chat,spoken_languages=:spoken,expertise=:expertise,calendar_default_view=:calendar_view,calendar_show_weekends=:calendar_weekends,default_warehouse_id=:warehouse WHERE id=:id')
                ->execute(['login'=>$login,'language'=>$language,'theme'=>$theme,'notification'=>$notification,'signature'=>$signature,'chat'=>$liveChatName,'spoken'=>json_encode($spokenLanguages, JSON_THROW_ON_ERROR),'expertise'=>json_encode($expertise, JSON_THROW_ON_ERROR),'calendar_view'=>$calendarDefaultView,'calendar_weekends'=>$calendarShowWeekends?1:0,'warehouse'=>$defaultWarehouseId,'id'=>$id]);

            $this->pdo->prepare('DELETE FROM user_company_access WHERE user_id=:user')->execute(['user'=>$id]);
            $companyInsert = $this->pdo->prepare('INSERT INTO user_company_access (user_id,company_id,default_company) VALUES (:user,:company,:is_default)');
            foreach ($companyIds as $companyId) $companyInsert->execute(['user'=>$id,'company'=>$companyId,'is_default'=>$defaultCompanyId===$companyId?1:0]);

            $this->savePrivateProfileRole($id, $permissionKeys);
            $newAudit = [
                'name' => $name, 'login' => $login, 'email' => $email, 'phone' => $phone, 'mobile' => $mobile,
                'company_ids' => $companyIds, 'default_company_id' => $defaultCompanyId,
                'permission_keys' => $permissionKeys, 'language_code' => $language, 'theme' => $theme,
                'notification_preference' => $notification, 'email_signature' => $signature,
                'live_chat_name' => $liveChatName, 'spoken_languages' => $spokenLanguages, 'expertise' => $expertise,
                'calendar_default_view' => $calendarDefaultView, 'calendar_show_weekends' => $calendarShowWeekends,
                'default_warehouse_id' => $defaultWarehouseId,
            ];
            $this->audit($actorId, $id, $oldAudit, $newAudit, $ip);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        return $this->find($id,$actor,$auth) ?? throw new InvalidArgumentException('User profile was saved but could not be reloaded.');
    }

    public function permissions(): array
    {
        $query = $this->pdo->query('SELECT permission_key,module_key,description FROM access_permissions ORDER BY module_key,permission_key');
        return $query->fetchAll();
    }

    private function availableCompanies(UserContext $actor): array
    {
        if ($actor->superuser) {
            $available = $this->pdo->query('SELECT id,name FROM companies WHERE active=1 ORDER BY name,id')->fetchAll();
        } elseif ($actor->companyIds) {
            $slots=[];$params=[];foreach($actor->companyIds as $i=>$companyId){$slots[]=':company_'.$i;$params['company_'.$i]=$companyId;}
            $availableQuery=$this->pdo->prepare('SELECT id,name FROM companies WHERE active=1 AND id IN ('.implode(',',$slots).') ORDER BY name,id');$availableQuery->execute($params);$available=$availableQuery->fetchAll();
        } else $available=[];
        foreach($available as &$company) $company['id']=(int)$company['id'];
        unset($company);
        return $available;
    }

    private function savePrivateProfileRole(int $userId, array $permissionKeys): void
    {
        $roleName = 'User Profile ' . $userId;
        $roleQuery = $this->pdo->prepare('SELECT id FROM access_roles WHERE company_id IS NULL AND name=:name ORDER BY id LIMIT 1 FOR UPDATE');
        $roleQuery->execute(['name'=>$roleName]);
        $roleId = $roleQuery->fetchColumn();
        if ($roleId === false) {
            $this->pdo->prepare('INSERT INTO access_roles (company_id,name,description,system_role,active) VALUES (NULL,:name,:description,0,1)')->execute(['name'=>$roleName,'description'=>'Private access profile for user #' . $userId]);
            $roleId = (int)$this->pdo->lastInsertId();
        } else {
            $roleId = (int)$roleId;
            $shared = $this->pdo->prepare('SELECT 1 FROM user_role_links WHERE role_id=:role AND user_id<>:user LIMIT 1');
            $shared->execute(['role'=>$roleId,'user'=>$userId]);
            if ($shared->fetchColumn()) throw new InvalidArgumentException('This private user access profile is linked to another account and cannot be safely changed.');
        }
        $this->pdo->prepare('DELETE FROM user_role_links WHERE user_id=:user')->execute(['user'=>$userId]);
        $this->pdo->prepare('DELETE FROM role_permission_links WHERE role_id=:role')->execute(['role'=>$roleId]);
        $this->pdo->prepare('INSERT INTO user_role_links (user_id,role_id) VALUES (:user,:role)')->execute(['user'=>$userId,'role'=>$roleId]);
        if ($permissionKeys) {
            $permissionIds = $this->permissionIds($permissionKeys);
            $grant = $this->pdo->prepare('INSERT INTO role_permission_links (role_id,permission_id) VALUES (:role,:permission)');
            foreach ($permissionIds as $permissionId) $grant->execute(['role'=>$roleId,'permission'=>$permissionId]);
        }
    }

    private function isVisible(int $id, UserContext $actor, Authorization $auth): bool
    {
        if ($actor->superuser) return true;
        if ($id === $actor->id) return true;
        if (!$actor->companyIds) return false;
        $slots=[];$params=['id'=>$id];foreach($actor->companyIds as $i=>$companyId){$slots[]=':company_'.$i;$params['company_'.$i]=$companyId;}
        $q=$this->pdo->prepare('SELECT 1 FROM user_company_access WHERE user_id=:id AND company_id IN ('.implode(',',$slots).') LIMIT 1');$q->execute($params);return(bool)$q->fetchColumn();
    }

    private function profileRoleName(int $id): string
    {
        $q=$this->pdo->prepare('SELECT r.name FROM user_role_links ur JOIN access_roles r ON r.id=ur.role_id WHERE ur.user_id=:user ORDER BY r.name LIMIT 1');$q->execute(['user'=>$id]);return(string)($q->fetchColumn()?:'No access profile');
    }

    private function isSuperuser(int $id): bool
    {
        $q=$this->pdo->prepare('SELECT is_superuser FROM users WHERE id=:id');$q->execute(['id'=>$id]);return(bool)$q->fetchColumn();
    }

    private function permissionKeys(mixed $value): array
    {
        if (!is_array($value) || count($value)>500) throw new InvalidArgumentException('Permission Keys must be a list.');
        $keys=[];foreach($value as $raw){if(!is_string($raw)||!preg_match('/^[a-z0-9_.-]{1,150}$/D',$raw))throw new InvalidArgumentException('Permission keys must be valid text keys.');$keys[$raw]=$raw;}
        $result=array_values($keys);$known=$this->pdo->query('SELECT permission_key FROM access_permissions')->fetchAll(PDO::FETCH_COLUMN);$unknown=array_diff($result,$known);if($unknown)throw new InvalidArgumentException('Unknown permission key: '.implode(', ',$unknown));return$result;
    }

    private function permissionIds(array $keys): array
    {
        $query=$this->pdo->prepare('SELECT id FROM access_permissions WHERE permission_key=:key');$ids=[];foreach($keys as $key){$query->execute(['key'=>$key]);$id=$query->fetchColumn();if($id===false)throw new InvalidArgumentException('Permission is unavailable: '.$key);$ids[]=(int)$id;}return$ids;
    }

    private function ids(mixed $value,string $label): array
    {
        if(!is_array($value)||count($value)>500)throw new InvalidArgumentException($label.' must be a list.');$ids=[];foreach($value as $raw){$id=$this->positiveId($raw,$label);$ids[$id]=$id;}return array_values($ids);
    }

    private function positiveId(mixed $value,string $label): int
    {
        $id=filter_var($value,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException($label.' must be a positive ID.');return$id;
    }

    private function nullablePositiveId(mixed $value, string $label): ?int
    {
        return $value === null || $value === '' ? null : $this->positiveId($value, $label);
    }

    private function validateDefaultWarehouse(?int $warehouseId, ?int $companyId, ?int $existingWarehouseId = null, ?int $existingCompanyId = null): void
    {
        if ($warehouseId === null) return;
        if ($companyId === null) throw new InvalidArgumentException('Choose a default company before selecting a default warehouse.');
        $query = $this->pdo->prepare('SELECT 1 FROM warehouses WHERE id=:id AND company_id=:company AND active=1 LIMIT 1');
        $query->execute(['id' => $warehouseId, 'company' => $companyId]);
        if ($query->fetchColumn()) return;
        if ($warehouseId === $existingWarehouseId && $companyId === $existingCompanyId) return;
        throw new InvalidArgumentException('Default Warehouse must be active and belong to the user’s default company.');
    }

    private function boolean(mixed $value,string $label): bool
    {
        if(!is_bool($value)&&!is_int($value)&&!is_string($value))throw new InvalidArgumentException($label.' must be true or false.');
        $parsed=filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);if($parsed===null)throw new InvalidArgumentException($label.' must be true or false.');return$parsed;
    }

    private function text(mixed $value,string $label,int $max,bool $required=false): ?string
    {
        $text=trim((string)($value??''));if($required&&$text==='')throw new InvalidArgumentException($label.' is required.');if(mb_strlen($text)>$max)throw new InvalidArgumentException($label.' must be '.$max.' characters or fewer.');return$text===''?null:$text;
    }

    private function stringList(mixed $value,string $label,int $maxItems,int $maxLength): array
    {
        if(!is_array($value)||count($value)>$maxItems)throw new InvalidArgumentException($label.' must be a list of at most '.$maxItems.' values.');$items=[];foreach($value as $item){if(!is_string($item))throw new InvalidArgumentException($label.' values must be text.');$text=trim($item);if($text===''||mb_strlen($text)>$maxLength)throw new InvalidArgumentException('Each '.$label.' value must contain 1 to '.$maxLength.' characters.');$items[$text]=$text;}return array_values($items);
    }

    private function audit(int $actor,int $target,array $old,array $new,string $remoteAddress): void
    {
        $ip=filter_var($remoteAddress,FILTER_VALIDATE_IP)?inet_pton($remoteAddress):null;
        $query=$this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (NULL,:actor,:event,:model,:record,:old,:new,:ip)');
        $query->execute(['actor'=>$actor,'event'=>'user_access_profile_updated','model'=>'user','record'=>$target,'old'=>json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'new'=>json_encode($new,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'ip'=>$ip]);
    }

    private function recordPasswordFailure(string $login, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $this->pdo->prepare('INSERT INTO login_attempts (login_hash,ip_address,succeeded) VALUES (:login,:ip,0)')
            ->execute(['login' => hash('sha256', strtolower(trim($login))), 'ip' => $ip]);
    }

    private function auditSecurityAction(int $actor, string $event, string $model, int $record, array $old, array $new, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $query = $this->pdo->prepare(
            'INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) ' .
            'VALUES (NULL,:actor,:event,:model,:record,:old,:new,:ip)'
        );
        $query->execute([
            'actor' => $actor, 'event' => $event, 'model' => $model, 'record' => $record,
            'old' => json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'new' => json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'ip' => $ip,
        ]);
    }
}
