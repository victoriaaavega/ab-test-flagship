<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generates a unique visitor ID for AB test variant assignment.
 *
 * Priority:
 *   1. Heap persistent user ID (abtf_heap_id cookie) — set by heap-sync.js on the
 *      first page load after Heap initializes. Survives IP changes, browser updates,
 *      and is shared across subdomains (www / app) via domain=.castingnetworks.com.
 *   2. Fingerprint fallback — SHA256 of IP + User-Agent + Accept-Language. Used only
 *      on the very first visit, before heap-sync.js has had a chance to write the cookie.
 *
 * Both paths produce a 64-char hex SHA256 string, so nothing downstream changes.
 */
class Fingerprint {

    /**
     * Returns the visitor ID, preferring the Heap persistent ID when available.
     *
     * @return string 64-char SHA256 hex string
     */
    public function generateVisitorId(): string {
        $heapId = $this->readHeapCookie();

        if ($heapId !== null) {
            // Prefix with 'heap:' to ensure the hash never collides with a fingerprint.
            return hash('sha256', 'heap:' . $heapId);
        }

        return $this->generateFingerprint();
    }

    /**
     * Reads and validates the Heap user ID from the abtf_heap_id cookie.
     * Returns null if the cookie is absent or its value is not a valid Heap ID.
     *
     * Heap user IDs are large positive integers (up to ~19 digits).
     *
     * @return string|null
     */
    private function readHeapCookie(): ?string {
        if (empty($_COOKIE['abtf_heap_id'])) {
            return null;
        }

        $value = sanitize_text_field($_COOKIE['abtf_heap_id']);

        // Heap IDs are numeric strings, 1–20 digits, no leading zeros.
        if (!preg_match('/^[1-9]\d{0,19}$/', $value)) {
            error_log('[AB Test] Fingerprint: invalid abtf_heap_id value, falling back to fingerprint.');
            return null;
        }

        return $value;
    }

    /**
     * Generates a visitor ID from request headers.
     * Used only when no Heap cookie is present (first visit).
     *
     * @return string 64-char SHA256 hex string
     */
    private function generateFingerprint(): string {
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
    private function getClientIp(): string {
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