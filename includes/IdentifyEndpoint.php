<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles visitor identity reconciliation between fingerprint and Heap visitor IDs.
 *
 * Called once per user lifetime — on the first page load where heap-sync.js
 * writes the abtf_heap_id cookie for the first time.
 *
 * Copies all variant assignments stored under the fingerprint visitor ID to the
 * Heap-based visitor ID in both the database and Redis, so that from the next
 * page load onwards PHP finds the correct variant under the Heap ID without
 * making a new decision.
 *
 * Endpoint: POST /wp-json/abtest/v1/identify
 */
class IdentifyEndpoint
{
    private RateLimiter $rateLimiter;

    public function __construct()
    {
        $this->rateLimiter = new RateLimiter();
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    public function registerRoute(): void
    {
        register_rest_route('abtest/v1', '/identify', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleRequest'],
            'permission_callback' => [$this, 'validateRequest'],
            'args'                => [
                'fingerprint_visitor_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($v) => !empty($v) && strlen($v) === 64 && ctype_xdigit($v),
                ],
                'heap_user_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($v) => !empty($v) && preg_match('/^[1-9]\d{0,19}$/', $v),
                ],
            ],
        ]);
    }

    /**
     * Validates nonce and rate limit — same security pattern as EventEndpoint.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function validateRequest(WP_REST_Request $request): bool|WP_Error
    {
        $nonce = $request->get_header('X-ABTF-Nonce');

        if (empty($nonce)) {
            return new WP_Error('missing_nonce', 'Nonce is required.', ['status' => 401]);
        }

        if (!wp_verify_nonce($nonce, 'abtf_track_event')) {
            return new WP_Error('invalid_nonce', 'Invalid or expired nonce.', ['status' => 403]);
        }

        $ip = $this->getClientIp();

        if (!$this->rateLimiter->isAllowed($ip)) {
            return new WP_Error('rate_limit_exceeded', 'Too many requests.', ['status' => 429]);
        }

        return true;
    }

    /**
     * Copies all variant assignments from the fingerprint visitor ID to the
     * Heap-based visitor ID in both the database and Redis.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleRequest(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $fingerprintVisitorId = $request->get_param('fingerprint_visitor_id');
        $heapUserId           = $request->get_param('heap_user_id');
        $heapVisitorId        = hash('sha256', 'heap:' . $heapUserId);

        // If both IDs resolve to the same hash, nothing to do.
        if ($fingerprintVisitorId === $heapVisitorId) {
            return new WP_REST_Response(['success' => true, 'copied' => 0], 200);
        }

        $table = $wpdb->prefix . 'ab_test_assignments';

        // Fetch all assignments stored under the fingerprint visitor ID.
        $assignments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT experiment_id, variant FROM {$table} WHERE visitor_id = %s",
                $fingerprintVisitorId
            )
        );

        if (empty($assignments)) {
            error_log("[AB Test] IdentifyEndpoint: no assignments found for fingerprint {$fingerprintVisitorId}.");
            return new WP_REST_Response(['success' => true, 'copied' => 0], 200);
        }

        $database = new Database();
        $redis    = new RedisClient();
        $copied   = 0;

        foreach ($assignments as $row) {
            // INSERT IGNORE ensures we never overwrite an existing Heap ID assignment.
            $database->saveVariant($row->experiment_id, $heapVisitorId, $row->variant);

            if ($redis->isAvailable()) {
                $redis->saveVariant($row->experiment_id, $heapVisitorId, $row->variant);
            }

            $copied++;
        }

        error_log("[AB Test] IdentifyEndpoint: copied {$copied} assignment(s) from fingerprint {$fingerprintVisitorId} to Heap visitor {$heapVisitorId}.");

        return new WP_REST_Response(['success' => true, 'copied' => $copied], 200);
    }

    /**
     * Gets the real client IP respecting Cloudflare and common proxies.
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