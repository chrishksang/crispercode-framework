<?php

declare(strict_types=1);

namespace CrisperCode\Service;

/**
 * Service for encrypting and decrypting data using AES-256-GCM.
 *
 * @package CrisperCode\Service
 */
class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12; // Recommended for GCM

    /**
     * Encrypts data using AES-256-GCM.
     *
     * @param string $data The plaintext data.
     * @param string $key The encryption key (32 bytes).
     * @return array{content: string, iv: string} Encrypted data (with appended tag) and IV.
     * @throws \RuntimeException If encryption fails.
     */
    public function encrypt(string $data, string $key): array
    {
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException('Key must be 32 bytes long.');
        }

        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $data,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // AAD
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return [
            'content' => $ciphertext . $tag,
            'iv' => $iv,
        ];
    }

    /**
     * Decrypts data using AES-256-GCM.
     *
     * @param string $encryptedData The encrypted data (ciphertext + tag).
     * @param string $key The encryption key.
     * @param string $iv The initialization vector.
     * @return string The decrypted plaintext.
     * @throws \RuntimeException If decryption fails.
     */
    public function decrypt(string $encryptedData, string $key, string $iv): string
    {
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException('Key must be 32 bytes long.');
        }

        if (strlen($encryptedData) < self::TAG_LENGTH) {
            throw new \InvalidArgumentException('Encrypted data is too short to contain authentication tag.');
        }

        $tag = substr($encryptedData, -self::TAG_LENGTH);
        $ciphertext = substr($encryptedData, 0, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed. Data may be tampered with or key is incorrect.');
        }

        return $plaintext;
    }

    /**
     * Generates a secure random key.
     *
     * @return string 32-byte binary key
     */
    public function generateKey(): string
    {
        return openssl_random_pseudo_bytes(32);
    }

    /**
     * Derives a 32-byte encryption key from a password and salt using PBKDF2.
     *
     * @param string $password The user's password.
     * @param string $salt A unique salt (e.g. user ID or email).
     * @return string The derived binary key.
     */
    public function deriveKey(string $password, string $salt): string
    {
        return hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
    }
}
