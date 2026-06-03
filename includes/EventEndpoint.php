<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and handles the AB Test event tracking REST API endpoint.
 * Receives click events from JavaScript and forwards them to Flagship
 * using the Universal Collect API directly — no SDK initialization needed.
 *
 * Endpoint: POST /wp-json/abtest/v1/event
 */
class EventEndpoint
{
    private const FLAGSHIP_EVENTS_URL = 'https://events.flagship.io';
    private const REQUEST_TIMEOUT     = 5; // seconds

    private RateLimiter $rateLimiter;
    private ConversionTracker $conversionTracker;

    public function __construct()
    {
        $this->rateLimiter       = new RateLimiter();
        $this->conversionTracker = new ConversionTracker();
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    public function registerRoute(): void
    {
        register_rest_route('abtest/v1', '/event', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleEvent'],
            'permission_callback' => [$this, 'validateRequest'],
            'args'                => [
                'visitor_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($v) => !empty($v) && strlen($v) === 64 && ctype_xdigit($v),
                ],
                'experiment_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($v) => !empty($v) && strlen($v) <= 100,
                ],
                'event_name' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($v) => !empty($v) && strlen($v) <= 100,
                ],
                'variant' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($v) => !empty($v) && strlen($v) <= 100,
                ],
                'page_url' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'validate_callback' => fn($v) => strlen($v) <= 2000,
                ],
            ],
        ]);
    }

    /**
     * Validates that the request comes from the site using a WordPress nonce,
     * and that the client IP has not exceeded the rate limit.
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
            return new WP_Error('rate_limit_exceeded', 'Too many requests. Please slow down.', ['status' => 429]);
        }

        return true;
    }

    /**
     * Handles incoming event requests.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleEvent(WP_REST_Request $request): WP_REST_Response
    {
        $visitorId    = $request->get_param('visitor_id');
        $experimentId = $request->get_param('experiment_id');
        $eventName    = $request->get_param('event_name');
        $variant      = $request->get_param('variant');
        $pageUrl      = $request->get_param('page_url') ?: home_url('/');

        error_log("[AB Test] Event received. Experiment: {$experimentId}, Visitor: {$visitorId}, Event: {$eventName}, Variant: {$variant}");

        $result = $this->sendHitToFlagship($visitorId, $eventName, $variant, $pageUrl);

        if (!$result['success']) {
            // Return 200 when credentials are simply not configured — not a server error.
            $statusCode = str_contains($result['message'], 'credentials') ? 200 : 500;

            return new WP_REST_Response([
                'success' => false,
                'message' => $result['message'],
            ], $statusCode);
        }

        // Record the conversion in Redis for real-time internal reporting.
        // Independent of Flagship — fails silently if Redis is unavailable.
        $this->conversionTracker->record($experimentId, $variant, $eventName, $visitorId);

        return new WP_REST_Response([
            'success'    => true,
            'message'    => 'Hit sent successfully.',
            'experiment' => $experimentId,
            'event'      => $eventName,
            'variant'    => $variant,
        ], 200);
    }

    /**
     * Sends an EVENT hit to Flagship via the Universal Collect API.
     * Uses wp_remote_post() — no SDK initialization required.
     *
     * @param string $visitorId
     * @param string $eventName  Maps to the KPI name in the Flagship dashboard (ea)
     * @param string $variant    Stored as the event label (el)
     * @param string $pageUrl    The page where the event occurred (dl)
     * @return array{success: bool, message: string}
     */
    private function sendHitToFlagship(
        string $visitorId,
        string $eventName,
        string $variant,
        string $pageUrl
    ): array {
        $envId = CredentialsManager::getEnvId();

        if ($envId === null) {
            error_log('[AB Test] Flagship credentials not found. Hit not sent.');
            return ['success' => false, 'message' => 'Flagship credentials not configured.'];
        }

        $payload = [
            't'   => 'EVENT',
            'cid' => $envId,
            'vid' => $visitorId,
            'dl'  => $pageUrl,
            'ea'  => $eventName,
            'ec'  => 'Action Tracking',
            'el'  => $variant,
            'ev'  => 1,
            'ds'  => 'APP',
        ];

        $response = wp_remote_post(self::FLAGSHIP_EVENTS_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            error_log("[AB Test] Flagship hit failed (wp_error): {$message}");
            return ['success' => false, 'message' => "Network error: {$message}"];
        }

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            $body = wp_remote_retrieve_body($response);
            error_log("[AB Test] Flagship hit failed. Status: {$statusCode}, Body: {$body}");
            return ['success' => false, 'message' => "Flagship returned status {$statusCode}."];
        }

        error_log("[AB Test] Hit sent to Flagship. Visitor: {$visitorId}, Event: {$eventName}, Variant: {$variant}, Page: {$pageUrl}");

        return ['success' => true, 'message' => 'Hit sent successfully.'];
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