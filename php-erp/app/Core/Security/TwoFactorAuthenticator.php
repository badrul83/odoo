<?php
declare(strict_types=1);

namespace Erp\Core\Security;

use InvalidArgumentException;

final class TwoFactorAuthenticator
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function encryptSecret(string $secret, string $key): string
    {
        self::assertKey($key);
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, $key));
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'ERP-2FA-v1');
        if (!is_string($cipher) || strlen($tag) !== 16) throw new InvalidArgumentException('The authenticator secret could not be encrypted.');
        return 'v2:' . base64_encode($nonce . $tag . $cipher);
    }

    public static function decryptSecret(string $encrypted, string $key): string
    {
        self::assertKey($key);
        $version = substr($encrypted, 0, 3);
        $payload = base64_decode(substr($encrypted, 3), true);
        if (!is_string($payload)) throw new InvalidArgumentException('The stored authenticator secret could not be read.');
        if ($version === 'v1:') {
            if (!function_exists('sodium_crypto_secretbox_open')) throw new InvalidArgumentException('Enable Sodium in PHP to read this authenticator secret.');
            $minimum = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + 1;
            if (strlen($payload) < $minimum) throw new InvalidArgumentException('The stored authenticator secret could not be read.');
            $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        } elseif ($version === 'v2:') {
            if (!function_exists('openssl_decrypt') || strlen($payload) < 29) throw new InvalidArgumentException('The stored authenticator secret could not be read.');
            $nonce = substr($payload, 0, 12);
            $tag = substr($payload, 12, 16);
            $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'ERP-2FA-v1');
        } else {
            throw new InvalidArgumentException('The stored authenticator secret has an unsupported format.');
        }
        if (!is_string($plain)) throw new InvalidArgumentException('The authenticator encryption key does not match this ERP installation.');
        return $plain;
    }

    public static function verifyCode(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $normalized = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $normalized)) return false;
        $timestamp ??= time();
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::codeAt($secret, $timestamp + ($offset * 30)), $normalized)) return true;
        }
        return false;
    }

    public static function codeAt(string $secret, int $timestamp): string
    {
        $key = self::base32Decode($secret);
        $counter = intdiv($timestamp, 30);
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $digest = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
        $offset = ord($digest[19]) & 0x0f;
        $part = unpack('Nnumber', substr($digest, $offset, 4));
        $number = ($part['number'] & 0x7fffffff) % 1000000;
        return str_pad((string)$number, 6, '0', STR_PAD_LEFT);
    }

    public static function recoveryCodes(int $count = 10): array
    {
        if ($count < 1 || $count > 20) throw new InvalidArgumentException('Recovery code count is out of range.');
        $codes = [];
        for ($index = 0; $index < $count; $index++) {
            $bytes = random_bytes(12);
            $code = '';
            for ($byte = 0; $byte < strlen($bytes); $byte++) $code .= self::BASE32[ord($bytes[$byte]) & 31];
            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4);
        }
        return $codes;
    }

    public static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(str_replace(['-', ' ', "\t", "\r", "\n"], '', trim($code)));
    }

    private static function base32Encode(string $bytes): string
    {
        $buffer = 0;
        $bits = 0;
        $output = '';
        for ($index = 0, $length = strlen($bytes); $index < $length; $index++) {
            $buffer = ($buffer << 8) | ord($bytes[$index]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $output .= self::BASE32[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) $output .= self::BASE32[($buffer << (5 - $bits)) & 31];
        return $output;
    }

    private static function base32Decode(string $encoded): string
    {
        $clean = strtoupper(preg_replace('/[=\s-]+/', '', trim($encoded)) ?? '');
        if ($clean === '' || preg_match('/^[A-Z2-7]+$/', $clean) !== 1) throw new InvalidArgumentException('The authenticator secret is invalid.');
        $buffer = 0;
        $bits = 0;
        $output = '';
        for ($index = 0, $length = strlen($clean); $index < $length; $index++) {
            $buffer = ($buffer << 5) | strpos(self::BASE32, $clean[$index]);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 255);
            }
            $buffer &= (1 << $bits) - 1;
        }
        return $output;
    }

    private static function assertKey(string $key): void
    {
        if ((!function_exists('sodium_crypto_secretbox') && !function_exists('openssl_encrypt')) || strlen($key) !== 32) {
            throw new InvalidArgumentException('Set a valid 32-byte ERP_DATA_ENCRYPTION_KEY before using authenticator-based two-factor authentication.');
        }
    }
}
