<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generates a unique visitor ID based on request parameters
 */
class Fingerprint {

    /**
     * Generates a unique visitor ID based on request parameters
     *
     * @return string SHA256 hash used as the visitor ID
     */
    public function generateVisitorId(): string {
        $data = [
            'ip'              => $this->getClientIp(),
            'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown',
        ];

        return hash('sha256', implode('|', $data));
    }

    /**
     * Gets the real client IP respecting proxies and Cloudflare
     *
     * @return string Client IP address
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