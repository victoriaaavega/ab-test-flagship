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
 *
 * Conversion recording is INDEPENDENT of Flagship. The internal conversion
 * counters (ConversionTracker, Redis DB 3) feed the live Reporting dashboard,
 * whose whole purpose is to avoid Flagship's reporting delay. A click is a real
 * user action: it is recorded internally regardless of whether the secondary
 * hit to Flagship succeeds, fails, or is skipped for lack of credentials.
 *
 * The response therefore reports two independent facts:
 *   - success: did we record the conversion internally? (the endpoint's contract)
 *   - flagship: did the secondary hit reach Flagship? ('sent'|'failed'|'skipped')
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
     * The internal conversion is recorded FIRST and independently of Flagship.
     * That recording is the endpoint's real contract — it is what the live
     * Reporting dashboard reads. The hit to Flagship is a best-effort secondary
     * delivery: its outcome is reported in the 'flagship' field but never
     * suppresses a conversion that the user genuinely made.
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
        // Do NOT fall back to home_url(): attributing a conversion to the home
        // page when the real page is unknown corrupts per-page reporting in
        // Flagship. An absent URL is forwarded as an absent 'dl' field instead
        // (see sendHitToFlagship) — a missing value is more honest than a wrong
        // one. In practice event-tracker.js always sends window.location.href.
        $pageUrl      = $request->get_param('page_url') ?: null;

        Logger::debug("Event received. Experiment: {$experimentId}, Visitor: {$visitorId}, Event: {$eventName}, Variant: {$variant}");

        // 1. Record the conversion internally FIRST — this is independent of
        //    Flagship and feeds the live Reporting dashboard. Fails silently if
        //    Redis is unavailable (fail-open); $recorded reflects the outcome.
        $recorded = $this->conversionTracker->record($experimentId, $variant, $eventName, $visitorId);

        if (!$recorded) {
            // Redis down (or record failed). The conversion could not be stored
            // in the live counters. We still attempt Flagship below so the data
            // is not lost entirely, but we report the internal failure honestly.
            Logger::error("ConversionTracker did not record event. Experiment: {$experimentId}, Event: {$eventName} (Redis may be down).");
        }

        // 2. Best-effort secondary delivery to Flagship. Never blocks or
        //    invalidates the internal recording above.
        $flagshipResult = $this->sendHitToFlagship($visitorId, $eventName, $variant, $pageUrl);
        $flagshipStatus = $this->flagshipStatusLabel($flagshipResult);

        // The endpoint's contract is the internal recording. success === true
        // means the conversion is counted in the live dashboard.
        return new WP_REST_Response([
            'success'    => $recorded,
            'flagship'   => $flagshipStatus, // 'sent' | 'failed' | 'skipped'
            'message'    => $recorded
                ? 'Conversion recorded.'
                : 'Conversion could not be recorded internally (storage unavailable).',
            'experiment' => $experimentId,
            'event'      => $eventName,
            'variant'    => $variant,
        ], 200);
    }

    /**
     * Maps the Flagship send result to a short status label for the response.
     *
     *   'skipped' — credentials not configured, nothing was attempted.
     *   'sent'    — Flagship accepted the hit (2xx).
     *   'failed'  — network error or non-2xx response.
     *
     * @param array{success: bool, message: string} $result
     * @return string
     */
    private function flagshipStatusLabel(array $result): string
    {
        if ($result['success']) {
            return 'sent';
        }

        // Distinguish "no credentials" (an expected configuration state, not an
        // error) from a genuine delivery failure.
        if (str_contains($result['message'], 'credentials')) {
            return 'skipped';
        }

        return 'failed';
    }

    /**
     * Sends an EVENT hit to Flagship via the Universal Collect API.
     * Uses wp_remote_post() — no SDK initialization required.
     *
     * @param string      $visitorId
     * @param string      $eventName  Maps to the KPI name in the Flagship dashboard (ea)
     * @param string      $variant    Stored as the event label (el)
     * @param string|null $pageUrl    The page where the event occurred (dl).
     *                                When null, 'dl' is omitted from the payload
     *                                rather than sent as a misleading fallback.
     * @return array{success: bool, message: string}
     */
    private function sendHitToFlagship(
        string $visitorId,
        string $eventName,
        string $variant,
        ?string $pageUrl
    ): array {
        $envId = CredentialsManager::getEnvId();

        if ($envId === null) {
            Logger::error('Flagship credentials not found. Hit not sent.');
            return ['success' => false, 'message' => 'Flagship credentials not configured.'];
        }

        $payload = [
            't'   => 'EVENT',
            'cid' => $envId,
            'vid' => $visitorId,
            'ea'  => $eventName,
            'ec'  => 'Action Tracking',
            'el'  => $variant,
            'ev'  => 1,
            'ds'  => 'APP',
        ];

        // Only include the document location when we actually know it.
        if ($pageUrl !== null && $pageUrl !== '') {
            $payload['dl'] = $pageUrl;
        }

        $body = wp_json_encode($payload);

        // wp_json_encode() returns false if the payload cannot be serialized.
        // Our payload is a flat array of scalars so this should never happen,
        // but guard anyway: sending body=false would silently post an empty
        // hit to Flagship. Abort with a clear error instead.
        if ($body === false) {
            Logger::error('EventEndpoint: failed to JSON-encode the hit payload. Hit not sent.');
            return ['success' => false, 'message' => 'Failed to encode hit payload.'];
        }

        $response = wp_remote_post(self::FLAGSHIP_EVENTS_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            Logger::error("Flagship hit failed (wp_error): {$message}");
            return ['success' => false, 'message' => "Network error: {$message}"];
        }

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            $body = wp_remote_retrieve_body($response);
            Logger::error("Flagship hit failed. Status: {$statusCode}, Body: {$body}");
            return ['success' => false, 'message' => "Flagship returned status {$statusCode}."];
        }

        $loggedUrl = $pageUrl ?? '(none)';
        Logger::debug("Hit sent to Flagship. Visitor: {$visitorId}, Event: {$eventName}, Variant: {$variant}, Page: {$loggedUrl}");

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