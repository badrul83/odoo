<?php
declare(strict_types=1);

namespace Erp\Core\Security;

use Erp\Core\Config;

final class Session
{
    private static ?int $apiUserId = null;

    public static function start(Config $config): void
    {
        self::$apiUserId = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionPath = $config->sessionPath();
        if (!is_dir($sessionPath) && !@mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
            throw new \RuntimeException('The private session directory could not be created.');
        }
        $writeProbePath = rtrim($sessionPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.write-check-' . bin2hex(random_bytes(8));
        $writeProbe = @fopen($writeProbePath, 'x');
        if (!is_resource($writeProbe)) {
            throw new \RuntimeException('The private session directory is not writable.');
        }
        fclose($writeProbe);
        @unlink($writeProbePath);
        if (session_save_path($sessionPath) === false) {
            throw new \RuntimeException('The private session directory could not be selected.');
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        session_name('ERPSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $config->basePath(),
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        if (self::$apiUserId !== null) return true;
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function authorizeApiUser(?int $userId): void
    {
        self::$apiUserId = $userId !== null && $userId > 0 ? $userId : null;
    }

    public static function apiUserId(): ?int
    {
        return self::$apiUserId;
    }
}
