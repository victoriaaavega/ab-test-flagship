<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves which decision engine drives variant assignment.
 *
 * Two modes, chosen explicitly by the administrator in Settings:
 *
 *   - flagship (default): variants are decided by AB Tasty Flagship. Requires
 *     credentials. Gives remote targeting, segmentation and the Flagship
 *     dashboard.
 *   - local: variants are decided by the plugin itself, using deterministic
 *     bucketing. No network calls, no credentials, no data leaves the site.
 *
 * The mode is a deliberate choice, never inferred from whether credentials
 * happen to be present: silently falling back to local decisions would let a
 * site run experiments nobody asked for. Flagship is the default so a fresh
 * install never decides locally by accident.
 */
class DecisionMode
{
    public const OPTION_MODE = 'abtf_decision_engine';

    public const MODE_FLAGSHIP = 'flagship';
    public const MODE_LOCAL    = 'local';

    private const DEFAULT_MODE = self::MODE_FLAGSHIP;

    /** @var array<int, string> */
    private const VALID_MODES = [self::MODE_FLAGSHIP, self::MODE_LOCAL];

    private static ?string $mode = null;

    /**
     * Returns the configured mode, falling back to flagship when unset or
     * when the stored value is not recognised.
     */
    public static function get(): string
    {
        if (self::$mode !== null) {
            return self::$mode;
        }

        $stored = (string) get_option(self::OPTION_MODE, self::DEFAULT_MODE);

        self::$mode = in_array($stored, self::VALID_MODES, true)
            ? $stored
            : self::DEFAULT_MODE;

        return self::$mode;
    }

    /**
     * True when the plugin decides variants by itself, with no Flagship call.
     */
    public static function isLocal(): bool
    {
        return self::get() === self::MODE_LOCAL;
    }

    /**
     * True when variants are decided by Flagship.
     */
    public static function isFlagship(): bool
    {
        return self::get() === self::MODE_FLAGSHIP;
    }

    /**
     * Persists the mode. Rejects unknown values rather than storing them.
     *
     * @return bool True when saved, false when the mode is not recognised.
     */
    public static function save(string $mode): bool
    {
        if (!in_array($mode, self::VALID_MODES, true)) {
            return false;
        }

        update_option(self::OPTION_MODE, $mode);

        // Bust the request-scoped cache so the next read sees the new value.
        self::$mode = null;

        return true;
    }

    /**
     * Human-readable label for admin notices and badges.
     */
    public static function label(string $mode = ''): string
    {
        $mode = $mode !== '' ? $mode : self::get();

        return $mode === self::MODE_LOCAL ? 'Local' : 'Flagship';
    }
}