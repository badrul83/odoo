<?php
declare(strict_types=1);

namespace Erp\Core\Security;

use Erp\Core\Config;
use InvalidArgumentException;
use PDO;
use PDOException;

/** WebAuthn ceremonies for ES256 discoverable credentials and verified none attestation. */
final class WebAuthnService
{
    private const CHALLENGE_TTL = 180;
    private const ALLOWED_TRANSPORTS = ['usb', 'nfc', 'ble', 'internal', 'hybrid'];

    public function __construct(private readonly PDO $pdo, private readonly Config $config) {}

    public function passkeys(UserContext $user): array
    {
        $query = $this->pdo->prepare('SELECT id,display_name,transports,created_at,last_used_at FROM user_passkeys WHERE user_id=:user ORDER BY created_at DESC,id DESC');
        $query->execute(['user' => $user->id]);
        return ['passkeys' => array_map(static function (array $row): array {
            $transports = $row['transports'] ? json_decode((string)$row['transports'], true) : [];
            return ['id' => (int)$row['id'], 'display_name' => (string)$row['display_name'], 'transports' => is_array($transports) ? $transports : [], 'created_at' => $row['created_at'], 'last_used_at' => $row['last_used_at']];
        }, $query->fetchAll())];
    }

    public function beginRegistration(string $currentPassword, UserContext $user, string $remoteAddress): array
    {
        $this->assertConfiguration();
        $query = $this->pdo->prepare("SELECT login,password_hash,status,webauthn_user_handle FROM users WHERE id=:id LIMIT 1");
        $query->execute(['id' => $user->id]);
        $record = $query->fetch();
        if (!$record || !in_array($record['status'], ['confirmed', 'active'], true) || !$this->verifyOwnPassword($currentPassword, $record, $user->id, $remoteAddress)) {
            throw new InvalidArgumentException('The current password is incorrect or the user is inactive.');
        }
        $challenge = self::base64url(random_bytes(32));
        $userHandle = is_string($record['webauthn_user_handle']) && strlen($record['webauthn_user_handle']) === 32 ? $record['webauthn_user_handle'] : random_bytes(32);
        $existing = $this->pdo->prepare('SELECT credential_id FROM user_passkeys WHERE user_id=:user ORDER BY id');
        $existing->execute(['user' => $user->id]);
        $exclude = [];
        foreach ($existing->fetchAll(PDO::FETCH_COLUMN) as $credentialId) $exclude[] = ['type' => 'public-key', 'id' => (string)$credentialId];
        $_SESSION['webauthn_challenge'] = ['purpose' => 'registration', 'challenge' => $challenge, 'user_id' => $user->id, 'user_handle' => self::base64url($userHandle), 'expires_at' => time() + self::CHALLENGE_TTL];
        return ['challenge' => $challenge, 'rp' => ['id' => $this->config->webauthnRpId(), 'name' => 'DEPO ERP'], 'user' => ['id' => self::base64url($userHandle), 'name' => (string)$record['login'], 'displayName' => $user->name], 'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]], 'timeout' => 60000, 'attestation' => 'none', 'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'], 'excludeCredentials' => $exclude];
    }

    public function finishRegistration(array $input, UserContext $user, string $remoteAddress): array
    {
        $challenge = $this->takeChallenge('registration');
        $this->assertKeys($input, ['id', 'raw_id', 'type', 'response', 'display_name'], 'Passkey registration');
        $credentialId = self::decodeBase64url($input['raw_id'] ?? null, 'Passkey credential');
        if (strlen($credentialId) < 1 || strlen($credentialId) > 1023 || ($input['type'] ?? null) !== 'public-key' || !is_string($input['id'] ?? null) || !hash_equals((string)$input['id'], self::base64url($credentialId))) throw new InvalidArgumentException('Passkey registration response is invalid.');
        $response = $input['response'] ?? null;
        if (!is_array($response)) throw new InvalidArgumentException('Passkey registration response is invalid.');
        $this->assertKeys($response, ['client_data_json', 'attestation_object', 'transports'], 'Passkey response');
        $clientDataJson = self::decodeBase64url($response['client_data_json'] ?? null, 'Passkey client data');
        $attestationBytes = self::decodeBase64url($response['attestation_object'] ?? null, 'Passkey attestation');
        $this->validateClientData($clientDataJson, 'webauthn.create', (string)$challenge['challenge']);
        $attestation = WebAuthnCbor::decode($attestationBytes);
        if (!is_array($attestation) || ($attestation['fmt'] ?? null) !== 'none' || !is_array($attestation['attStmt'] ?? null) || $attestation['attStmt'] !== [] || !is_string($attestation['authData'] ?? null)) throw new InvalidArgumentException('This passkey attestation format is not supported.');
        $authData = $attestation['authData'];
        if (strlen($authData) < 55 || !hash_equals(hash('sha256', $this->config->webauthnRpId(), true), substr($authData, 0, 32))) throw new InvalidArgumentException('Passkey relying-party validation failed.');
        $flags = ord($authData[32]);
        if (($flags & 0x01) === 0 || ($flags & 0x04) === 0 || ($flags & 0x40) === 0 || (($flags & 0x10) !== 0 && ($flags & 0x08) === 0)) throw new InvalidArgumentException('The passkey did not confirm user presence and verification.');
        $signCount = self::readUint32(substr($authData, 33, 4));
        $credentialLength = unpack('nvalue', substr($authData, 53, 2))['value'];
        if ($credentialLength !== strlen($credentialId) || !hash_equals($credentialId, substr($authData, 55, $credentialLength))) throw new InvalidArgumentException('Passkey credential identifiers do not match.');
        $coseOffset = 55 + $credentialLength;
        $coseKey = WebAuthnCbor::decodeAt($authData, $coseOffset);
        $publicKey = self::publicKeyFromCose($coseKey);
        self::validateExtensions($authData, $flags, $coseOffset);
        $name = trim((string)($input['display_name'] ?? 'Passkey'));
        if ($name === '') $name = 'Passkey';
        if (mb_strlen($name) > 120) throw new InvalidArgumentException('Passkey name must be 120 characters or fewer.');
        $transports = $response['transports'] ?? [];
        if (!is_array($transports) || count($transports) > 5) throw new InvalidArgumentException('Passkey transport values are invalid.');
        foreach ($transports as $transport) if (!is_string($transport) || !in_array($transport, self::ALLOWED_TRANSPORTS, true)) throw new InvalidArgumentException('Passkey transport values are invalid.');
        $transports = array_values(array_unique($transports));

        $this->pdo->beginTransaction();
        try {
            $owner = $this->pdo->prepare('SELECT webauthn_user_handle FROM users WHERE id=:user AND status IN (\'confirmed\',\'active\') LIMIT 1 FOR UPDATE');
            $owner->execute(['user' => $user->id]);
            $storedHandle = $owner->fetchColumn();
            $expectedHandle = self::decodeBase64url((string)$challenge['user_handle'], 'Passkey user handle');
            if ($storedHandle !== false && $storedHandle !== null && !hash_equals((string)$storedHandle, $expectedHandle)) throw new InvalidArgumentException('Passkey registration changed in another request. Start again.');
            if ($storedHandle === false) throw new InvalidArgumentException('The user account is no longer available.');
            if ($storedHandle === null) $this->pdo->prepare('UPDATE users SET webauthn_user_handle=:handle WHERE id=:user AND webauthn_user_handle IS NULL')->execute(['handle' => $expectedHandle, 'user' => $user->id]);
            $insert = $this->pdo->prepare('INSERT INTO user_passkeys (user_id,credential_id,credential_id_hash,public_key_pem,sign_count,transports,display_name) VALUES (:user,:credential,:hash,:public_key,:counter,:transports,:name)');
            $insert->bindValue(':user', $user->id, PDO::PARAM_INT);
            $insert->bindValue(':credential', self::base64url($credentialId));
            $insert->bindValue(':hash', hash('sha256', $credentialId, true), PDO::PARAM_LOB);
            $insert->bindValue(':public_key', $publicKey);
            $insert->bindValue(':counter', $signCount, PDO::PARAM_INT);
            $insert->bindValue(':transports', $transports ? json_encode($transports, JSON_THROW_ON_ERROR) : null, $transports ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insert->bindValue(':name', $name);
            $insert->execute();
            $id = (int)$this->pdo->lastInsertId();
            $this->audit($user->id, 'user_passkey_registered', $id, ['display_name' => $name, 'transports' => $transports], $remoteAddress);
            $this->pdo->commit();
            return ['passkey' => ['id' => $id, 'display_name' => $name, 'transports' => $transports]];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($error instanceof PDOException && str_starts_with((string)($error->errorInfo[0] ?? $error->getCode()), '23')) throw new InvalidArgumentException('This passkey is already registered.');
            throw $error;
        }
    }

    public function removePasskey(int $id, string $currentPassword, UserContext $user, string $remoteAddress): void
    {
        $this->assertCurrentPassword($currentPassword, $user->id, $remoteAddress);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT display_name FROM user_passkeys WHERE id=:id AND user_id=:user LIMIT 1 FOR UPDATE');
            $query->execute(['id' => $id, 'user' => $user->id]);
            $name = $query->fetchColumn();
            if (!is_string($name)) throw new InvalidArgumentException('Passkey was not found.');
            $this->pdo->prepare('DELETE FROM user_passkeys WHERE id=:id AND user_id=:user')->execute(['id' => $id, 'user' => $user->id]);
            $this->audit($user->id, 'user_passkey_removed', $id, ['display_name' => $name], $remoteAddress);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function beginLogin(AuthService $auth, string $remoteAddress): array
    {
        $this->assertConfiguration();
        if (!$auth->passkeyLoginAttemptAllowed($remoteAddress)) throw new InvalidArgumentException('Too many passkey attempts. Wait before trying again.');
        $challenge = self::base64url(random_bytes(32));
        $_SESSION['webauthn_challenge'] = ['purpose' => 'login', 'challenge' => $challenge, 'expires_at' => time() + self::CHALLENGE_TTL];
        return ['challenge' => $challenge, 'rpId' => $this->config->webauthnRpId(), 'timeout' => 60000, 'userVerification' => 'required'];
    }

    public function finishLogin(array $input, AuthService $auth, string $remoteAddress): array
    {
        try {
            if (!$auth->passkeyLoginAttemptAllowed($remoteAddress)) throw new InvalidArgumentException('Passkey attempts are temporarily blocked.');
            $result = $this->verifyLogin($input, $auth, $remoteAddress);
            $auth->recordPasskeyLoginAttempt($remoteAddress, true);
            return $result;
        } catch (InvalidArgumentException $error) {
            $auth->recordPasskeyLoginAttempt($remoteAddress, false);
            throw new InvalidArgumentException('Passkey sign-in failed. Try again or use your password.');
        }
    }

    private function verifyLogin(array $input, AuthService $auth, string $remoteAddress): array
    {
        $challenge = $this->takeChallenge('login');
        $this->assertKeys($input, ['id', 'raw_id', 'type', 'response'], 'Passkey assertion');
        $credentialId = self::decodeBase64url($input['raw_id'] ?? null, 'Passkey credential');
        if (strlen($credentialId) < 1 || strlen($credentialId) > 1023 || ($input['type'] ?? null) !== 'public-key' || !is_string($input['id'] ?? null) || !hash_equals((string)$input['id'], self::base64url($credentialId))) throw new InvalidArgumentException('Passkey assertion is invalid.');
        $response = $input['response'] ?? null;
        if (!is_array($response)) throw new InvalidArgumentException('Passkey assertion is invalid.');
        $this->assertKeys($response, ['client_data_json', 'authenticator_data', 'signature', 'user_handle'], 'Passkey assertion response');
        $clientDataJson = self::decodeBase64url($response['client_data_json'] ?? null, 'Passkey client data');
        $authData = self::decodeBase64url($response['authenticator_data'] ?? null, 'Passkey authenticator data');
        $signature = self::decodeBase64url($response['signature'] ?? null, 'Passkey signature');
        $handle = self::decodeBase64url($response['user_handle'] ?? null, 'Passkey user handle');
        if (strlen($handle) !== 32 || strlen($authData) < 37 || strlen($authData) > 65536) throw new InvalidArgumentException('Passkey assertion is invalid.');
        $this->validateClientData($clientDataJson, 'webauthn.get', (string)$challenge['challenge']);
        if (!hash_equals(hash('sha256', $this->config->webauthnRpId(), true), substr($authData, 0, 32))) throw new InvalidArgumentException('Passkey relying-party validation failed.');
        $flags = ord($authData[32]);
        if (($flags & 0x01) === 0 || ($flags & 0x04) === 0 || (($flags & 0x10) !== 0 && ($flags & 0x08) === 0)) throw new InvalidArgumentException('The passkey did not confirm user presence and verification.');
        self::validateExtensions($authData, $flags, 37);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT pk.id,pk.user_id,pk.public_key_pem,pk.sign_count,u.webauthn_user_handle FROM user_passkeys pk JOIN users u ON u.id=pk.user_id WHERE pk.credential_id_hash=:hash AND u.status IN (\'confirmed\',\'active\') LIMIT 1 FOR UPDATE');
            $query->bindValue(':hash', hash('sha256', $credentialId, true), PDO::PARAM_LOB);
            $query->execute();
            $key = $query->fetch();
            if (!$key || !is_string($key['webauthn_user_handle']) || !hash_equals($key['webauthn_user_handle'], $handle)) throw new InvalidArgumentException('Passkey was not found.');
            $signedData = $authData . hash('sha256', $clientDataJson, true);
            $verified = openssl_verify($signedData, $signature, (string)$key['public_key_pem'], OPENSSL_ALGO_SHA256);
            if ($verified !== 1) throw new InvalidArgumentException('Passkey signature verification failed.');
            $signCount = self::readUint32(substr($authData, 33, 4));
            $storedCount = (int)$key['sign_count'];
            if (($signCount !== 0 || $storedCount !== 0) && $signCount <= $storedCount) throw new InvalidArgumentException('Passkey counter validation failed.');
            $this->pdo->prepare('UPDATE user_passkeys SET sign_count=:counter,last_used_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['counter' => $signCount, 'id' => $key['id']]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        $login = $auth->completePasskeyLogin((int)$key['user_id'], $remoteAddress);
        if (!$login['authenticated'] && !$login['requires_two_factor']) throw new InvalidArgumentException('Passkey sign-in failed.');
        return ['authenticated' => $login['authenticated'], 'requires_two_factor' => $login['requires_two_factor']];
    }

    private function takeChallenge(string $purpose): array
    {
        $challenge = $_SESSION['webauthn_challenge'] ?? null;
        unset($_SESSION['webauthn_challenge']);
        if (!is_array($challenge) || ($challenge['purpose'] ?? null) !== $purpose || !is_string($challenge['challenge'] ?? null) || (int)($challenge['expires_at'] ?? 0) < time()) throw new InvalidArgumentException('Passkey challenge expired. Start again.');
        return $challenge;
    }

    private function validateClientData(string $json, string $type, string $challenge): void
    {
        if ($json === '' || strlen($json) > 8192) throw new InvalidArgumentException('Passkey client data is invalid.');
        try { $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new InvalidArgumentException('Passkey client data is invalid.'); }
        if (!is_array($data) || ($data['type'] ?? null) !== $type || ($data['challenge'] ?? null) !== $challenge || ($data['origin'] ?? null) !== $this->config->webauthnOrigin() || ($data['crossOrigin'] ?? false) !== false || array_key_exists('topOrigin', $data)) throw new InvalidArgumentException('Passkey origin or challenge validation failed.');
    }

    private function assertConfiguration(): void
    {
        $origin = parse_url($this->config->webauthnOrigin());
        $rpId = $this->config->webauthnRpId();
        if (!is_array($origin) || !isset($origin['scheme'], $origin['host']) || !in_array(strtolower((string)$origin['scheme']), ['https', 'http'], true) || isset($origin['user']) || isset($origin['pass']) || isset($origin['query']) || isset($origin['fragment']) || isset($origin['path']) && $origin['path'] !== '' || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/iD', $rpId)) throw new InvalidArgumentException('Passkeys need a valid WEBAUTHN_ORIGIN and WEBAUTHN_RP_ID configuration.');
        $host = strtolower((string)$origin['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1'], true);
        if (strtolower((string)$origin['scheme']) !== 'https' && !$loopback) throw new InvalidArgumentException('Passkeys require HTTPS except on localhost.');
        if ($host !== $rpId && !str_ends_with($host, '.' . $rpId)) throw new InvalidArgumentException('WEBAUTHN_RP_ID must match the site host or a parent domain.');
    }

    private function assertCurrentPassword(string $password, int $userId, string $remoteAddress): void
    {
        $query = $this->pdo->prepare("SELECT login,password_hash,status FROM users WHERE id=:id LIMIT 1");
        $query->execute(['id' => $userId]);
        $user = $query->fetch();
        if (!$user || !in_array($user['status'], ['confirmed','active'], true) || !$this->verifyOwnPassword($password, $user, $userId, $remoteAddress)) throw new InvalidArgumentException('The current password is incorrect or the user is inactive.');
    }

    private function verifyOwnPassword(string $password, array $user, int $userId, string $remoteAddress): bool
    {
        if ($password === '' || strlen($password) > 4096) return false;
        $loginHash = hash('sha256', strtolower((string)$user['login']));
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $attempts = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE login_hash=:login AND ip_address <=> :ip AND succeeded=0 AND attempted_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)');
        $attempts->bindValue(':login', $loginHash);
        $attempts->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $attempts->execute();
        if ((int)$attempts->fetchColumn() >= 8) throw new InvalidArgumentException('Too many password attempts. Wait before trying again.');
        $valid = password_verify($password, (string)$user['password_hash']);
        $record = $this->pdo->prepare('INSERT INTO login_attempts (login_hash,ip_address,succeeded) VALUES (:login,:ip,:succeeded)');
        $record->bindValue(':login', $loginHash);
        $record->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $record->bindValue(':succeeded', $valid ? 1 : 0, PDO::PARAM_INT);
        $record->execute();
        return $valid;
    }

    private function audit(int $actor, string $event, int $record, array $new, string $remoteAddress): void
    {
        $ip = filter_var($remoteAddress, FILTER_VALIDATE_IP) ? inet_pton($remoteAddress) : null;
        $query = $this->pdo->prepare('INSERT INTO audit_events (company_id,user_id,event_key,model_key,record_id,old_values,new_values,ip_address) VALUES (NULL,:actor,:event,\'user_passkey\',:record,NULL,:new,:ip)');
        $query->bindValue(':actor', $actor, PDO::PARAM_INT);
        $query->bindValue(':event', $event);
        $query->bindValue(':record', $record, PDO::PARAM_INT);
        $query->bindValue(':new', json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $query->bindValue(':ip', $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $query->execute();
    }

    private static function publicKeyFromCose(mixed $key): string
    {
        if (!is_array($key) || ($key[1] ?? null) !== 2 || ($key[3] ?? null) !== -7 || ($key[-1] ?? null) !== 1 || !is_string($key[-2] ?? null) || !is_string($key[-3] ?? null) || strlen($key[-2]) !== 32 || strlen($key[-3]) !== 32) throw new InvalidArgumentException('Only ES256 P-256 passkeys are supported.');
        $algorithm = hex2bin('301306072a8648ce3d020106082a8648ce3d030107');
        $bitString = "\x00\x04" . $key[-2] . $key[-3];
        $bitStringDer = "\x03" . self::derLength(strlen($bitString)) . $bitString;
        $spki = "\x30" . self::derLength(strlen($algorithm . $bitStringDer)) . $algorithm . $bitStringDer;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (openssl_pkey_get_public($pem) === false) throw new InvalidArgumentException('Passkey public key is invalid.');
        return $pem;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) return chr($length);
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function validateExtensions(string $authData, int $flags, int $offset): void
    {
        if (($flags & 0x80) !== 0) {
            $extensions = WebAuthnCbor::decodeAt($authData, $offset);
            if (!is_array($extensions)) throw new InvalidArgumentException('Passkey extensions are invalid.');
        }
        if ($offset !== strlen($authData)) throw new InvalidArgumentException('Passkey authenticator data has unexpected trailing bytes.');
    }

    private static function readUint32(string $bytes): int
    {
        if (strlen($bytes) !== 4) throw new InvalidArgumentException('Passkey counter is invalid.');
        return (int)unpack('Nvalue', $bytes)['value'];
    }

    private function assertKeys(array $value, array $allowed, string $label): void
    {
        if (array_diff(array_keys($value), $allowed)) throw new InvalidArgumentException($label . ' contains unsupported fields.');
    }

    private static function decodeBase64url(mixed $value, string $label): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 90000 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) throw new InvalidArgumentException($label . ' is invalid.');
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (!is_string($decoded) || self::base64url($decoded) !== $value) throw new InvalidArgumentException($label . ' is invalid.');
        return $decoded;
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
