<?php
declare(strict_types=1);

namespace Erp\Core;

final class Config
{
    private function __construct(
        private readonly string $rootPath,
        private readonly string $environment,
        private readonly bool $debug,
        private readonly string $timezone,
        private readonly string $basePath,
        private readonly string $sessionPath,
        private readonly string $storagePath,
        private readonly string $dbHost,
        private readonly int $dbPort,
        private readonly string $dbName,
        private readonly string $dbUser,
        private readonly string $dbPassword,
        private readonly ?string $dataEncryptionKey,
        private readonly string $webauthnRpId,
        private readonly string $webauthnOrigin,
    ) {
    }

    public static function fromEnvironment(string $rootPath): self
    {
        self::loadEnvironmentFile($rootPath);
        $debugValue = strtolower(self::env('APP_DEBUG', 'false'));
        $port = filter_var(self::env('ERP_DB_PORT', '3306'), FILTER_VALIDATE_INT);
        $appBaseUrl = self::env('APP_BASE_URL', 'http://localhost');
        $basePath = parse_url($appBaseUrl, PHP_URL_PATH);
        if (is_string($basePath) && $basePath !== '') {
            $basePath = '/' . trim($basePath, '/');
        } else {
            // XAMPP commonly serves this project from a nested path such as
            // /ODOO/php-erp/public. Derive that path for local previews when
            // APP_BASE_URL contains only an origin or is not configured.
            $scriptName = PHP_SAPI === 'cli' ? '/' : str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
            if (!str_starts_with($scriptName, '/')) $scriptName = '/';
            $scriptDirectory = dirname($scriptName);
            $basePath = $scriptDirectory === '.' || $scriptDirectory === '/' ? '/' : '/' . trim($scriptDirectory, '/');
        }
        if ($basePath !== '/') {
            $basePath = rtrim($basePath, '/');
        }
        $sessionPath = self::env('ERP_SESSION_PATH', rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions');
        $storagePath = self::env('ERP_STORAGE_PATH', rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage');

        $encodedEncryptionKey = self::env('ERP_DATA_ENCRYPTION_KEY', '');
        $dataEncryptionKey = $encodedEncryptionKey !== '' ? base64_decode($encodedEncryptionKey, true) : false;

        $baseUrlParts = parse_url($appBaseUrl);
        $baseHost = is_array($baseUrlParts) && isset($baseUrlParts['host']) ? strtolower((string)$baseUrlParts['host']) : 'localhost';
        $baseScheme = is_array($baseUrlParts) && isset($baseUrlParts['scheme']) ? strtolower((string)$baseUrlParts['scheme']) : 'http';
        $baseOrigin = $baseScheme . '://' . $baseHost . (isset($baseUrlParts['port']) ? ':' . (int)$baseUrlParts['port'] : '');
        $webauthnRpId = strtolower(self::env('WEBAUTHN_RP_ID', $baseHost));
        $webauthnOrigin = rtrim(self::env('WEBAUTHN_ORIGIN', $baseOrigin), '/');

        return new self(
            rtrim($rootPath, DIRECTORY_SEPARATOR),
            self::env('APP_ENV', 'production'),
            in_array($debugValue, ['1', 'true', 'yes', 'on'], true),
            self::env('APP_TIMEZONE', 'Asia/Dhaka'),
            $basePath,
            $sessionPath,
            rtrim($storagePath, DIRECTORY_SEPARATOR),
            self::env('ERP_DB_HOST', ''),
            $port !== false && $port > 0 ? $port : 3306,
            self::env('ERP_DB_NAME', ''),
            self::env('ERP_DB_USER', ''),
            self::env('ERP_DB_PASSWORD', ''),
            is_string($dataEncryptionKey) && strlen($dataEncryptionKey) === 32 ? $dataEncryptionKey : null,
            $webauthnRpId,
            $webauthnOrigin,
        );
    }

    public function rootPath(): string { return $this->rootPath; }
    public function environment(): string { return $this->environment; }
    public function debug(): bool { return $this->debug; }
    public function timezone(): string { return $this->timezone; }
    public function basePath(): string { return $this->basePath; }
    public function sessionPath(): string { return $this->sessionPath; }
    public function storagePath(): string { return $this->storagePath; }
    public function dbHost(): string { return $this->dbHost; }
    public function dbPort(): int { return $this->dbPort; }
    public function dbName(): string { return $this->dbName; }
    public function dbUser(): string { return $this->dbUser; }
    public function dbPassword(): string { return $this->dbPassword; }
    public function dataEncryptionKey(): ?string { return $this->dataEncryptionKey; }
    public function webauthnRpId(): string { return $this->webauthnRpId; }
    public function webauthnOrigin(): string { return $this->webauthnOrigin; }

    public function hasDatabaseCredentials(): bool
    {
        return $this->dbHost !== '' && $this->dbName !== '' && $this->dbUser !== '';
    }

    private static function loadEnvironmentFile(string $rootPath): void
    {
        $path = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/D', $line, $match) !== 1) {
                continue;
            }

            $key = $match[1];
            if (getenv($key) !== false || array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER)) {
                continue;
            }

            $value = trim($match[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        if ($value === false && array_key_exists($name, $_ENV)) {
            $value = is_scalar($_ENV[$name]) ? (string)$_ENV[$name] : false;
        }
        if ($value === false && array_key_exists($name, $_SERVER)) {
            $value = is_scalar($_SERVER[$name]) ? (string)$_SERVER[$name] : false;
        }
        return $value === false ? $default : trim($value);
    }
}
