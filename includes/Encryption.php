<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles symmetric encryption and decryption of sensitive values.
 *
 * Uses AES-256-CBC with a key derived from WordPress AUTH_KEY and AUTH_SALT,
 * which are unique per installation and never committed to version control.
 *
 * This is not meant to protect against a full server compromise — if an attacker
 * has access to wp-config.php they already have everything. The goal is to ensure
 * credentials are never stored in plain text in the database.
 */
class Encryption {

    private const CIPHER    = 'AES-256-CBC';
    private const IV_LENGTH = 16;

    /**
     * Encrypts a plain text string.
     * Returns a base64-encoded string containing IV + ciphertext.
     *
     * @param string $value Plain text to encrypt
     * @return string|null Encrypted string, or null on failure
     */
    public static function encrypt(string $value): ?string {
        $key = self::deriveKey();

        if ($key === null) {
            error_log('[AB Test] Encryption: AUTH_KEY or AUTH_SALT not defined.');
            return null;
        }

        $iv         = random_bytes(self::IV_LENGTH);
        $ciphertext = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            error_log('[AB Test] Encryption: openssl_encrypt failed.');
            return null;
        }

        return base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypts a previously encrypted string.
     *
     * @param string $encrypted Base64-encoded IV + ciphertext
     * @return string|null Decrypted plain text, or null on failure
     */
    public static function decrypt(string $encrypted): ?string {
        $key = self::deriveKey();

        if ($key === null) {
            error_log('[AB Test] Encryption: AUTH_KEY or AUTH_SALT not defined.');
            return null;
        }

        $decoded = base64_decode($encrypted, strict: true);

        if ($decoded === false || strlen($decoded) <= self::IV_LENGTH) {
            error_log('[AB Test] Encryption: invalid encrypted payload.');
            return null;
        }

        $iv         = substr($decoded, 0, self::IV_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH);

        $plain = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            error_log('[AB Test] Encryption: openssl_decrypt failed.');
            return null;
        }

        return $plain;
    }

    /**
     * Derives a 32-byte key from WordPress AUTH_KEY and AUTH_SALT.
     * These constants are unique per installation and defined in wp-config.php.
     *
     * @return string|null 32-byte binary key, or null if constants are missing
     */
    private static function deriveKey(): ?string {
        if (!defined('AUTH_KEY') || !defined('AUTH_SALT')) {
            return null;
        }

        return hash('sha256', AUTH_KEY . AUTH_SALT, binary: true);
    }
}