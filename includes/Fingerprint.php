<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generates a unique visitor ID for AB test variant assignment.
 *
 * Priority:
 *   1. External provider cookie (abtf_visitor_id) — written by visitor-sync.js
 *      when the active provider is 'heap' or 'custom'. The raw value is returned
 *      as-is, so the ID that reaches Flagship matches exactly the one the team's
 *      own integration sends to AB Tasty (avoiding duplicate visitors).
 *   2. Fingerprint fallback — SHA256 of IP + User-Agent + Accept-Language. Used
 *      when provider is 'fingerprint', or on the very first visit before
 *      visitor-sync.js has written the cookie.
 *
 * Whether the external ID is hashed is decided by VisitorIdProvider::shouldHash():
 * fingerprint hashes (to protect the IP), heap/custom return the raw ID. As a
 * result the returned ID is NOT always a 64-char hex string anymore — heap
 * returns Heap's own format (e.g. a 16-digit numeric userId).
 */
class Nofliq_Fingerprint
{
    /**
     * Returns the visitor ID based on the active provider configuration.
     *
     * @return string SHA256 hex (fingerprint) or the raw external ID (heap/custom).
     */
    public function generateVisitorId(): string
    {
        if (VisitorIdProvider::usesExternalId()) {
            $externalId = $this->readVisitorCookie();

            if ($externalId !== null) {
                // Heap/custom return the RAW external ID so it matches exactly
                // the ID the team's own integration sends to AB Tasty. Hashing is
                // reserved for fingerprint, where it protects the visitor's IP.
                if (VisitorIdProvider::shouldHash()) {
                    return hash('sha256', VisitorIdProvider::getHashPrefix() . $externalId);
                }

                return $externalId;
            }

            // Cookie not yet written — fall through to fingerprint for this request.
            // visitor-sync.js will write the cookie and call IdentifyEndpoint
            // to reconcile on the same page load.
        }

        return $this->generateFingerprint();
    }

    /**
     * Recomputes the raw fingerprint for the current request (SHA-256 of
     * IP + User-Agent + Accept-Language), regardless of the active provider.
     *
     * Used by IdentifyEndpoint to verify that the caller is the visitor whose
     * fingerprint they claim: the server recomputes the fingerprint from this
     * very request and compares. A caller cannot present a fingerprint that
     * isn't the one their own request produces.
     *
     * @return string 64-char SHA256 hex string
     */
    public function computeFingerprintId(): string
    {
        return $this->generateFingerprint();
    }

    /**
     * Reads and validates the external visitor ID from the abtf_visitor_id cookie.
     * Returns null if the cookie is absent or empty.
     *
     * The raw value is provider-agnostic: heap writes a numeric string,
     * custom providers may write any non-empty string. Basic sanitization only —
     * format validation is the provider's responsibility.
     *
     * @return string|null
     */
    private function readVisitorCookie(): ?string
    {
        $cookieName = VisitorIdProvider::COOKIE_NAME;

        if (empty($_COOKIE[$cookieName])) {
            return null;
        }

        $value = sanitize_text_field(wp_unslash($_COOKIE[$cookieName]));

        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Generates a visitor ID from request headers.
     * Used when provider is 'fingerprint' or when no cookie is present yet.
     *
     * @return string 64-char SHA256 hex string
     */
    private function generateFingerprint(): string
    {
        $data = [
            'ip'              => $this->getClientIp(),
            'user_agent'      => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'unknown',
            'accept_language' => isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])) : 'unknown',
        ];

        return hash('sha256', implode('|', $data));
    }

    /**
     * Gets the real client IP respecting proxies and Cloudflare.
     *
     * @return string
     */
    private function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])))[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])))[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}