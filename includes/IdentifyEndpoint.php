<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles visitor identity reconciliation between fingerprint and external visitor IDs.
 *
 * Called once per user lifetime — on the first page load where visitor-sync.js
 * writes the abtf_visitor_id cookie for the first time.
 *
 * Copies all variant assignments stored under the fingerprint visitor ID to the
 * external visitor ID in both the database and Redis, so that from the next
 * page load onwards PHP finds the correct variant under the external ID without
 * making a new decision.
 *
 * The destination visitor ID is built the same way Fingerprint.php builds it
 * on the next page load: raw for heap/custom, hashed only for fingerprint
 * (decided by VisitorIdProvider::shouldHash()). This guarantees the copy lands
 * on the exact key the next lookup will read from.
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
                    // Same safe pattern as EventEndpoint: bounded length and a
                    // restricted character set, not tied to one ID format.
                    'validate_callback' => fn($v) => is_string($v)
                        && $v !== ''
                        && strlen($v) <= 255
                        && (bool) preg_match('/^[A-Za-z0-9_:-]+$/', $v),
                ],
                'external_visitor_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($v) => !empty($v) && strlen($v) <= 255,
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
     * external visitor ID in both the database and Redis.
     *
     * The external visitor ID is hashed with the active provider prefix so it
     * matches exactly what Fingerprint::generateVisitorId() produces on the
     * next page load.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleRequest(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $fingerprintVisitorId = $request->get_param('fingerprint_visitor_id');
        $externalVisitorId    = $request->get_param('external_visitor_id');

        // Build the destination visitor ID exactly the way Fingerprint.php does
        // on the next page load, so the reconciliation writes to the same key
        // the lookup will later read from. Heap/custom use the raw ID; only
        // fingerprint hashes (to protect the IP). These two must stay in sync.
        $destinationVisitorId = VisitorIdProvider::shouldHash()
            ? hash('sha256', VisitorIdProvider::getHashPrefix() . $externalVisitorId)
            : $externalVisitorId;

        // If both IDs are already the same, nothing to do.
        if ($fingerprintVisitorId === $destinationVisitorId) {
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
            // INSERT IGNORE ensures we never overwrite an existing external ID assignment.
            $database->saveVariant($row->experiment_id, $destinationVisitorId, $row->variant);

            if ($redis->isAvailable()) {
                // FIXME(frozen): RedisClient has no saveVariant(); the correct
                // method is saveAssignment(). The Flagship IDs are passed as null
                // because this endpoint copies from the SQL assignments table,
                // which only stores the variant — not variationGroupId/variationId.
                // Consequence: a reconciled visitor is stored in Redis without
                // Flagship IDs, so ExperimentRunner will skip their activate hit
                // on the next visit. Acceptable for now (they still see the right
                // variant); revisit when the visitor-ID flow is unfrozen and the
                // team decides whether to keep fingerprint. Copying the full
                // assignment from Redis (which has the IDs) is the proper fix.
                $redis->saveAssignment($row->experiment_id, $destinationVisitorId, $row->variant, null, null);
            }

            $copied++;
        }

        error_log("[AB Test] IdentifyEndpoint: copied {$copied} assignment(s) from fingerprint {$fingerprintVisitorId} to external visitor {$destinationVisitorId}.");

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