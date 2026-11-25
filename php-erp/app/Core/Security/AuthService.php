<?php
declare(strict_types=1);

namespace Erp\Core\Security;

use PDO;

final class AuthService
{
    private const MAX_FAILED_LOGINS = 8;
    private const FAILURE_WINDOW_MINUTES = 15;

    public function __construct(private readonly PDO $pdo, private readonly ?string $encryptionKey = null)
    {
    }

    public function login(string $login, string $password, string $remoteAddress): bool
    {
        return $this->startLogin($login, $password, $remoteAddress)['authenticated'];
    }

    /** @return array{authenticated:bool,requires_two_factor:bool} */
    public function startLogin(string $login, string $password, string $remoteAddress): array
    {
        $normalizedLogin = strtolower(trim($login));
        if ($normalizedLogin === '' || strlen($normalizedLogin) > 190 || $password === '' || strlen($password) > 4096) {
            return ['authenticated' => false, 'requires_two_factor' => false];
        }

        $loginHash = hash('sha256', $normalizedLogin);
        $ipAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        if (!$this->attemptsAllowed($loginHash, $ipAddress)) {
            $this->recordAttempt($loginHash, $ipAddress, false);
            return ['authenticated' => false, 'requires_two_factor' => false];
        }

        $query = $this->pdo->prepare(
            'SELECT u.id, u.password_hash, u.status, u.two_factor_enabled ' .
            'FROM users u WHERE u.login = :login LIMIT 1'
        );
        $query->execute(['login' => $normalizedLogin]);
        $user = $query->fetch();
        $passwordMatches = $user && password_verify($password, (string)$user['password_hash']);
        if (!$passwordMatches || !in_array($user['status'], ['confirmed', 'active'], true)) {
            // Keep the unknown-user and wrong-password paths close in cost.
            password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            $this->recordAttempt($loginHash, $ipAddress, false);
            return ['authenticated' => false, 'requires_two_factor' => false];
        }

        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $rehash->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }

        if ((bool)$user['two_factor_enabled']) {
            $this->revokeCurrentSession();
            session_regenerate_id(true);
            unset($_SESSION['user_id']);
            $_SESSION['pending_two_factor_user_id'] = (int)$user['id'];
            $_SESSION['pending_two_factor_login_hash'] = $loginHash;
            $_SESSION['pending_two_factor_ip_hash'] = hash('sha256', $remoteAddress);
            $_SESSION['pending_two_factor_expires_at'] = time() + 300;
            unset($_SESSION['pending_two_factor_enrollment_secret']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            return ['authenticated' => false, 'requires_two_factor' => true];
        }

        $this->establishAuthenticatedSession((int)$user['id']);
        $this->recordAttempt($loginHash, $ipAddress, true);
        return ['authenticated' => true, 'requires_two_factor' => false];
    }

    public function completeTwoFactorLogin(string $code, string $remoteAddress): bool
    {
        $userId = filter_var($_SESSION['pending_two_factor_user_id'] ?? null, FILTER_VALIDATE_INT);
        $loginHash = $_SESSION['pending_two_factor_login_hash'] ?? null;
        $ipHash = $_SESSION['pending_two_factor_ip_hash'] ?? null;
        $expiresAt = filter_var($_SESSION['pending_two_factor_expires_at'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId === null || $userId < 1 || !is_string($loginHash) || !is_string($ipHash) || $expiresAt === false || $expiresAt === null || $expiresAt < time()) {
            $this->clearPendingTwoFactor();
            return false;
        }
        if (!hash_equals($ipHash, hash('sha256', $remoteAddress))) return false;

        $ipAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        if (!$this->attemptsAllowed($loginHash, $ipAddress)) {
            $this->recordAttempt($loginHash, $ipAddress, false);
            return false;
        }
        $query = $this->pdo->prepare("SELECT two_factor_secret FROM users WHERE id=:id AND status IN ('confirmed','active') AND two_factor_enabled=1 LIMIT 1");
        $query->execute(['id' => $userId]);
        $encryptedSecret = $query->fetchColumn();
        $valid = false;
        if (is_string($encryptedSecret) && $encryptedSecret !== '' && $this->encryptionKey !== null) {
            try {
                $secret = TwoFactorAuthenticator::decryptSecret($encryptedSecret, $this->encryptionKey);
                $valid = TwoFactorAuthenticator::verifyCode($secret, $code);
            } catch (\InvalidArgumentException) {
                // Recovery codes remain usable if an administrator has rotated or lost the encryption key.
            }
        }
        if (!$valid) $valid = $this->consumeRecoveryCode($userId, $code);
        if (!$valid) {
            $this->recordAttempt($loginHash, $ipAddress, false);
            return false;
        }

        $this->clearPendingTwoFactor();
        $this->establishAuthenticatedSession($userId);
        $this->recordAttempt($loginHash, $ipAddress, true);
        return true;
    }

    /** Complete the credential half of a passkey login without bypassing existing 2FA. */
    public function completePasskeyLogin(int $userId, string $remoteAddress): array
    {
        $query = $this->pdo->prepare("SELECT login,two_factor_enabled FROM users WHERE id=:id AND status IN ('confirmed','active') LIMIT 1");
        $query->execute(['id' => $userId]);
        $user = $query->fetch();
        if (!$user) return ['authenticated' => false, 'requires_two_factor' => false];
        $loginHash = hash('sha256', strtolower((string)$user['login']));
        $ipAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        if (!$this->attemptsAllowed($loginHash, $ipAddress)) {
            $this->recordAttempt($loginHash, $ipAddress, false);
            return ['authenticated' => false, 'requires_two_factor' => false];
        }
        if ((bool)$user['two_factor_enabled']) {
            $this->revokeCurrentSession();
            session_regenerate_id(true);
            unset($_SESSION['user_id']);
            $_SESSION['pending_two_factor_user_id'] = $userId;
            $_SESSION['pending_two_factor_login_hash'] = $loginHash;
            $_SESSION['pending_two_factor_ip_hash'] = hash('sha256', $remoteAddress);
            $_SESSION['pending_two_factor_expires_at'] = time() + 300;
            unset($_SESSION['pending_two_factor_enrollment_secret']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            return ['authenticated' => false, 'requires_two_factor' => true];
        }
        $this->establishAuthenticatedSession($userId);
        $this->recordAttempt($loginHash, $ipAddress, true);
        return ['authenticated' => true, 'requires_two_factor' => false];
    }

    public function passkeyLoginAttemptAllowed(string $remoteAddress): bool
    {
        $ipAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        return $this->attemptsAllowed(hash('sha256', 'webauthn-discoverable-login'), $ipAddress);
    }

    public function recordPasskeyLoginAttempt(string $remoteAddress, bool $succeeded): void
    {
        $ipAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $this->recordAttempt(hash('sha256', 'webauthn-discoverable-login'), $ipAddress, $succeeded);
    }

    public function cancelTwoFactorLogin(): void
    {
        $this->clearPendingTwoFactor();
        unset($_SESSION['pending_two_factor_enrollment_secret'], $_SESSION['pending_two_factor_enrollment_user_id'], $_SESSION['pending_two_factor_enrollment_expires_at']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public function currentUser(): ?UserContext
    {
        $userId = Session::apiUserId() ?? filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId === null || $userId < 1) {
            return null;
        }

        $query = $this->pdo->prepare(
            'SELECT u.id, u.login, u.is_superuser, u.status, c.name ' .
            'FROM users u JOIN contacts c ON c.id = u.contact_id WHERE u.id = :id LIMIT 1'
        );
        $query->execute(['id' => $userId]);
        $user = $query->fetch();
        if (!$user || !in_array($user['status'], ['confirmed', 'active'], true)) {
            if (Session::apiUserId() !== null) Session::authorizeApiUser(null);
            else $this->logout();
            return null;
        }

        if (Session::apiUserId() === null && !$this->registerCurrentSession((int)$user['id'])) {
            $this->logout();
            return null;
        }

        $companies = $this->pdo->prepare(
            'SELECT company_id, default_company FROM user_company_access WHERE user_id = :user_id ORDER BY default_company DESC, company_id'
        );
        $companies->execute(['user_id' => $userId]);
        $companyRows = $companies->fetchAll();
        $companyIds = array_map(static fn (array $row): int => (int)$row['company_id'], $companyRows);
        $defaultCompanyId = null;
        foreach ($companyRows as $row) {
            if ((int)$row['default_company'] === 1) {
                $defaultCompanyId = (int)$row['company_id'];
                break;
            }
        }

        return new UserContext(
            (int)$user['id'],
            (string)$user['name'],
            (string)$user['login'],
            (bool)$user['is_superuser'],
            $companyIds,
            $defaultCompanyId,
        );
    }

    public function authenticateApiKey(string $token): ?int
    {
        if (strlen($token) !== 68 || preg_match('/^erp_[a-f0-9]{64}$/', $token) !== 1) return null;
        $query = $this->pdo->prepare(
            "SELECT k.id,k.user_id FROM user_api_keys k JOIN users u ON u.id=k.user_id " .
            "WHERE k.key_hash=:hash AND k.revoked_at IS NULL AND (k.expires_at IS NULL OR k.expires_at>UTC_TIMESTAMP()) " .
            "AND u.status IN ('confirmed','active') LIMIT 1"
        );
        $query->execute(['hash' => hash('sha256', $token)]);
        $key = $query->fetch();
        if (!$key) return null;
        $this->pdo->prepare('UPDATE user_api_keys SET last_used_at=UTC_TIMESTAMP() WHERE id=:id AND revoked_at IS NULL')->execute(['id' => $key['id']]);
        return (int)$key['user_id'];
    }

    public function logout(): void
    {
        $this->revokeCurrentSession();
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    private function revokeCurrentSession(): void
    {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        $sessionId = session_id();
        if ($userId !== false && $userId !== null && $userId > 0 && $sessionId !== '') {
            $revoke = $this->pdo->prepare('UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE user_id = :user AND session_hash = :session_hash');
            $revoke->execute(['user' => $userId, 'session_hash' => hash('sha256', $sessionId)]);
        }
    }

    private function establishAuthenticatedSession(int $userId): void
    {
        $this->revokeCurrentSession();
        session_regenerate_id(true);
        $this->clearPendingTwoFactor();
        $_SESSION['user_id'] = $userId;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $this->pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $userId]);
    }

    private function attemptsAllowed(string $loginHash, ?string $ipAddress): bool
    {
        $attempts = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE login_hash=:login_hash AND ip_address <=> :ip_address AND succeeded=0 ' .
            'AND attempted_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . self::FAILURE_WINDOW_MINUTES . ' MINUTE)'
        );
        $attempts->bindValue(':login_hash', $loginHash);
        $attempts->bindValue(':ip_address', $ipAddress, $ipAddress === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $attempts->execute();
        return (int)$attempts->fetchColumn() < self::MAX_FAILED_LOGINS;
    }

    private function consumeRecoveryCode(int $userId, string $code): bool
    {
        $normalized = TwoFactorAuthenticator::normalizeRecoveryCode($code);
        if (preg_match('/^[A-Z2-7]{12}$/', $normalized) !== 1) return false;
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT id FROM user_two_factor_recovery_codes WHERE user_id=:user AND code_hash=:hash AND used_at IS NULL LIMIT 1 FOR UPDATE');
            $query->execute(['user' => $userId, 'hash' => hash('sha256', $normalized)]);
            $id = $query->fetchColumn();
            if ($id === false) {
                $this->pdo->commit();
                return false;
            }
            $this->pdo->prepare('UPDATE user_two_factor_recovery_codes SET used_at=UTC_TIMESTAMP() WHERE id=:id AND used_at IS NULL')->execute(['id' => $id]);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function clearPendingTwoFactor(): void
    {
        unset($_SESSION['pending_two_factor_user_id'], $_SESSION['pending_two_factor_login_hash'], $_SESSION['pending_two_factor_ip_hash'], $_SESSION['pending_two_factor_expires_at']);
    }

    private function registerCurrentSession(int $userId): bool
    {
        $sessionId = session_id();
        if ($sessionId === '') return false;
        $sessionHash = hash('sha256', $sessionId);
        $ipAddress = filter_var((string)($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP)
            ? inet_pton((string)$_SERVER['REMOTE_ADDR'])
            : null;
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $this->pdo->prepare(
            'INSERT INTO user_sessions (user_id,session_hash,user_agent,ip_address,created_at,last_seen_at) ' .
            'VALUES (:user,:session_hash,:user_agent,:ip,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ' .
            'ON DUPLICATE KEY UPDATE last_seen_at=IF(revoked_at IS NULL AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),UTC_TIMESTAMP(),last_seen_at), ' .
            'user_agent=IF(revoked_at IS NULL,VALUES(user_agent),user_agent), ip_address=IF(revoked_at IS NULL,VALUES(ip_address),ip_address)'
        )->execute(['user' => $userId, 'session_hash' => $sessionHash, 'user_agent' => $userAgent, 'ip' => $ipAddress]);
        $check = $this->pdo->prepare('SELECT user_id,revoked_at FROM user_sessions WHERE session_hash=:session_hash LIMIT 1');
        $check->execute(['session_hash' => $sessionHash]);
        $stored = $check->fetch();
        return $stored && (int)$stored['user_id'] === $userId && $stored['revoked_at'] === null;
    }

    private function recordAttempt(string $loginHash, ?string $ipAddress, bool $succeeded): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO login_attempts (login_hash, ip_address, succeeded) VALUES (:login_hash, :ip_address, :succeeded)'
        );
        $insert->bindValue(':login_hash', $loginHash);
        $insert->bindValue(':ip_address', $ipAddress, $ipAddress === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $insert->bindValue(':succeeded', $succeeded ? 1 : 0, PDO::PARAM_INT);
        $insert->execute();
    }
}
