<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generates a unique visitor ID for AB test variant assignment.
 *
 * Priority:
 *   1. External provider cookie (abtf_visitor_id) — written by visitor-sync.js
 *      when the active provider is 'heap' or 'custom'. The raw value is prefixed
 *      with the provider slug before hashing so IDs never collide across providers.
 *   2. Fingerprint fallback — SHA256 of IP + User-Agent + Accept-Language. Used
 *      when provider is 'fingerprint', or on the very first visit before
 *      visitor-sync.js has written the cookie.
 *
 * Both paths produce a 64-char hex SHA256 string, so nothing downstream changes.
 */
class Fingerprint
{
    /**
     * Returns the visitor ID based on the active provider configuration.
     *
     * @return string 64-char SHA256 hex string
     */
    public function generateVisitorId(): string
    {
        if (VisitorIdProvider::usesExternalId()) {
            $externalId = $this->readVisitorCookie();

            if ($externalId !== null) {
                $prefix = VisitorIdProvider::getHashPrefix();
                return hash('sha256', $prefix . $externalId);
            }

            // Cookie not yet written — fall through to fingerprint for this request.
            // visitor-sync.js will write the cookie and call IdentifyEndpoint
            // to reconcile on the same page load.
        }

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

        $value = sanitize_text_field($_COOKIE[$cookieName]);

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
            'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown',
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
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}