<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages Flagship API credentials with a clear priority chain:
 *
 *   1. Encrypted values in wp_options (set via Settings page)
 *   2. null — triggers SimulatorAdapter fallback
 *
 * All reads are cached statically for the lifetime of the request
 * to avoid repeated decryption on every call.
 */
class CredentialsManager {

    public const OPTION_ENV_ID  = 'abtf_flagship_env_id';
    public const OPTION_API_KEY = 'abtf_flagship_api_key';

    private static ?string $envId  = null;
    private static ?string $apiKey = null;
    private static bool $loaded    = false;

    /**
     * Returns the Flagship Environment ID, or null if not configured.
     */
    public static function getEnvId(): ?string {
        self::load();
        return self::$envId;
    }

    /**
     * Returns the Flagship API Key, or null if not configured.
     */
    public static function getApiKey(): ?string {
        self::load();
        return self::$apiKey;
    }

    /**
     * Returns true if both credentials are available.
     */
    public static function hasCredentials(): bool {
        return self::getEnvId() !== null && self::getApiKey() !== null;
    }

    /**
     * Saves encrypted credentials to wp_options.
     * Returns true on success, false on failure.
     *
     * @param string $envId
     * @param string $apiKey
     * @return bool
     */
    public static function save(string $envId, string $apiKey): bool {
        $encryptedEnvId  = Encryption::encrypt($envId);
        $encryptedApiKey = Encryption::encrypt($apiKey);

        if ($encryptedEnvId === null || $encryptedApiKey === null) {
            return false;
        }

        $ok = update_option(self::OPTION_ENV_ID, $encryptedEnvId)
           && update_option(self::OPTION_API_KEY, $encryptedApiKey);

        // Bust the static cache so next read picks up the new values
        self::$loaded = false;
        self::$envId  = null;
        self::$apiKey = null;

        return $ok;
    }

    /**
     * Deletes credentials from wp_options and clears the static cache.
     */
    public static function delete(): void {
        delete_option(self::OPTION_ENV_ID);
        delete_option(self::OPTION_API_KEY);

        self::$loaded = false;
        self::$envId  = null;
        self::$apiKey = null;
    }

    /**
     * Loads credentials once per request.
     * Results are cached statically to avoid repeated decryption.
     */
    private static function load(): void {
    if (self::$loaded) {
        return;
    }

    self::$loaded = true;

    $encryptedEnvId  = get_option(self::OPTION_ENV_ID, '');
    $encryptedApiKey = get_option(self::OPTION_API_KEY, '');

    if ($encryptedEnvId !== '' && $encryptedApiKey !== '') {
        $envId  = Encryption::decrypt($encryptedEnvId);
        $apiKey = Encryption::decrypt($encryptedApiKey);

        if ($envId !== null && $apiKey !== null) {
            self::$envId  = $envId;
            self::$apiKey = $apiKey;
            return;
        }

        error_log('[AB Test] CredentialsManager: decryption failed. Credentials may be corrupted.');
    }

    self::$envId  = null;
    self::$apiKey = null;
}
}