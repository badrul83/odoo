<?php
declare(strict_types=1);

namespace Erp\Core\Security;

use RuntimeException;

final class DataCipher
{
    public function __construct(private readonly ?string $key)
    {
    }

    public function encrypt(string $value): string
    {
        $this->assertAvailable();
        if (function_exists('openssl_encrypt') && function_exists('openssl_cipher_iv_length')) {
            $nonceLength = openssl_cipher_iv_length('aes-256-gcm');
            if ($nonceLength === false || $nonceLength < 1) {
                throw new RuntimeException('AES-GCM is not available in this PHP build.');
            }
            $nonce = random_bytes($nonceLength);
            $tag = '';
            $encrypted = openssl_encrypt($value, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
            if (!is_string($encrypted) || strlen($tag) !== 16) {
                throw new RuntimeException('Bank data could not be encrypted.');
            }
            return 'GCM1' . $nonce . $tag . $encrypted;
        }

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'SBX1' . $nonce . sodium_crypto_secretbox($value, $nonce, $this->key);
        }
        throw new RuntimeException('Enable the PHP openssl or sodium extension to encrypt bank data.');
    }

    public function decrypt(string $ciphertext): string
    {
        $this->assertAvailable();
        if (str_starts_with($ciphertext, 'GCM1')) {
            $nonceLength = openssl_cipher_iv_length('aes-256-gcm');
            if ($nonceLength === false || strlen($ciphertext) < 4 + $nonceLength + 16) {
                throw new RuntimeException('Encrypted value is invalid.');
            }
            $nonce = substr($ciphertext, 4, $nonceLength);
            $tag = substr($ciphertext, 4 + $nonceLength, 16);
            $value = openssl_decrypt(substr($ciphertext, 4 + $nonceLength + 16), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, '');
            if (!is_string($value)) {
                throw new RuntimeException('Encrypted value could not be decrypted. Check the data key.');
            }
            return $value;
        }

        $hasVersionPrefix = str_starts_with($ciphertext, 'SBX1');
        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new RuntimeException('This bank value uses sodium encryption; enable PHP sodium to read it.');
        }
        $payload = $hasVersionPrefix ? substr($ciphertext, 4) : $ciphertext;
        if (strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Encrypted value is invalid.');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $value = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if (!is_string($value)) {
            throw new RuntimeException('Encrypted value could not be decrypted. Check the data key.');
        }
        return $value;
    }

    private function assertAvailable(): void
    {
        if (!is_string($this->key) || strlen($this->key) !== 32) {
            throw new RuntimeException('Bank data encryption is not configured. Set a 32-byte base64 ERP_DATA_ENCRYPTION_KEY outside the public web root.');
        }
        if (!function_exists('openssl_encrypt') && !function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('Enable the PHP openssl or sodium extension to encrypt bank data.');
        }
    }
}
