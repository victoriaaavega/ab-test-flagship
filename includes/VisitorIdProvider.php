<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the visitor ID provider configuration.
 *
 * Determines how the plugin resolves a persistent visitor ID:
 *
 *   - fingerprint: SHA256 of IP + User-Agent + Accept-Language. No JS dependency.
 *   - heap:        Reads window.heap.userId via JS, writes cookie abtf_visitor_id.
 *   - custom:      Reads an admin-defined JS path (e.g. window.myApp.userId),
 *                  writes cookie abtf_visitor_id.
 *
 * PHP (Fingerprint.php) reads the cookie written by visitor-sync.js and uses
 * the provider prefix to build a namespaced SHA256 that never collides across
 * providers.
 *
 * All reads are cached statically for the lifetime of the request.
 */
class VisitorIdProvider
{
    public const OPTION_PROVIDER = 'abtf_visitor_id_provider';
    public const OPTION_JS_PATH  = 'abtf_visitor_id_js_path';

    public const PROVIDER_FINGERPRINT = 'fingerprint';
    public const PROVIDER_HEAP        = 'heap';
    public const PROVIDER_CUSTOM      = 'custom';

    public const VALID_PROVIDERS = [
        self::PROVIDER_FINGERPRINT,
        self::PROVIDER_HEAP,
        self::PROVIDER_CUSTOM,
    ];

    /**
     * Cookie name written by visitor-sync.js and read by Fingerprint.php.
     * Generic across all external providers (heap, custom).
     */
    public const COOKIE_NAME = 'abtf_visitor_id';

    private static ?string $provider = null;
    private static ?string $jsPath   = null;
    private static bool    $loaded   = false;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns the active provider slug.
     * Defaults to 'fingerprint' if nothing is configured.
     *
     * @return string
     */
    public static function getProvider(): string
    {
        self::load();
        return self::$provider ?? self::PROVIDER_FINGERPRINT;
    }

    /**
     * Returns the JS path configured for heap or custom providers.
     * Returns null when provider is fingerprint.
     *
     * @return string|null
     */
    public static function getJsPath(): ?string
    {
        self::load();
        return self::$jsPath;
    }

    /**
     * Returns true when the active provider relies on a JS-written cookie
     * (i.e. anything other than fingerprint).
     *
     * @return bool
     */
    public static function usesExternalId(): bool
    {
        return self::getProvider() !== self::PROVIDER_FINGERPRINT;
    }

    /**
     * Returns the hash prefix used to namespace visitor IDs per provider.
     * Ensures a heap ID and a custom ID with the same raw value never collide.
     *
     * @return string  e.g. 'heap:' or 'custom:'
     */
    public static function getHashPrefix(): string
    {
        $provider = self::getProvider();

        return match ($provider) {
            self::PROVIDER_HEAP   => 'heap:',
            self::PROVIDER_CUSTOM => 'custom:',
            default               => '',
        };
    }

    /**
     * Saves the provider configuration to wp_options.
     * For heap, js_path is hardcoded. For custom, it must be provided.
     *
     * @param string      $provider One of the PROVIDER_* constants
     * @param string|null $jsPath   Required when provider is 'custom'
     * @return bool
     */
    public static function save(string $provider, ?string $jsPath = null): bool
    {
        if (!in_array($provider, self::VALID_PROVIDERS, true)) {
            return false;
        }

        if ($provider === self::PROVIDER_HEAP) {
            $jsPath = 'window.heap.userId';
        }

        if ($provider === self::PROVIDER_CUSTOM && empty($jsPath)) {
            return false;
        }

        if ($provider === self::PROVIDER_FINGERPRINT) {
            $jsPath = null;
        }

        update_option(self::OPTION_PROVIDER, $provider);
        update_option(self::OPTION_JS_PATH, $jsPath ?? '');

        // Bust static cache
        self::$loaded   = false;
        self::$provider = null;
        self::$jsPath   = null;

        return true;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Loads configuration once per request from wp_options.
     */
    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        $provider = get_option(self::OPTION_PROVIDER, self::PROVIDER_FINGERPRINT);
        $jsPath   = get_option(self::OPTION_JS_PATH, '');

        self::$provider = in_array($provider, self::VALID_PROVIDERS, true)
            ? $provider
            : self::PROVIDER_FINGERPRINT;

        self::$jsPath = $jsPath !== '' ? $jsPath : null;
    }
}