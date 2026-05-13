<?php

if (!defined('ABSPATH')) {
    exit;
}

use Flagship\Flagship;
use Flagship\Hit\Event;
use Flagship\Enum\EventCategory;
use Flagship\Config\FlagshipConfig;
use Flagship\Enum\LogLevel;
use Flagship\Enum\CacheStrategy;

/**
 * Registers and handles the AB Test event tracking REST API endpoint.
 * Receives click events from JavaScript and forwards them to Flagship.
 *
 * Endpoint: POST /wp-json/abtest/v1/event
 */
class EventEndpoint
{
    private static bool $flagshipInitialized = false;
    private RateLimiter $rateLimiter;

    public function __construct()
    {
        $this->rateLimiter = new RateLimiter();
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
                    'validate_callback' => fn($value) => !empty($value) && strlen($value) === 64 && ctype_xdigit($value),
                ],
                'experiment_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($value) => !empty($value) && strlen($value) <= 100,
                ],
                'event_name' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($value) => !empty($value) && strlen($value) <= 100,
                ],
                'variant' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($value) => !empty($value) && strlen($value) <= 100,
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
        // Validate nonce
        $nonce = $request->get_header('X-ABTF-Nonce');

        if (empty($nonce)) {
            return new WP_Error(
                'missing_nonce',
                'Nonce is required.',
                ['status' => 401]
            );
        }

        if (!wp_verify_nonce($nonce, 'abtf_track_event')) {
            return new WP_Error(
                'invalid_nonce',
                'Invalid or expired nonce.',
                ['status' => 403]
            );
        }

        // Check rate limit
        $ip = $this->getClientIp();

        if (!$this->rateLimiter->isAllowed($ip)) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Too many requests. Please slow down.',
                ['status' => 429]
            );
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

        error_log("[AB Test] Event received. Experiment: {$experimentId}, Visitor: {$visitorId}, Event: {$eventName}, Variant: {$variant}");

        $result = $this->sendHitToFlagship($visitorId, $eventName, $variant);

        if (!$result['success']) {
            $statusCode = str_contains($result['message'], 'credentials') ? 200 : 500;

            return new WP_REST_Response([
                'success' => false,
                'message' => $result['message'],
            ], $statusCode);
        }

        return new WP_REST_Response([
            'success'    => true,
            'message'    => 'Hit sent successfully.',
            'experiment' => $experimentId,
            'event'      => $eventName,
            'variant'    => $variant,
        ], 200);
    }

    /**
     * Initializes the Flagship SDK once per request lifecycle.
     */
    private function initializeFlagship(): void
    {
        if (self::$flagshipInitialized) {
            return;
        }

        $envId  = CredentialsManager::getEnvId();
        $apiKey = CredentialsManager::getApiKey();

        if ($envId === null || $apiKey === null) {
            error_log('[AB Test] EventEndpoint: credentials not found.');
            return;
        }

        Flagship::start(
            $envId,
            $apiKey,
            FlagshipConfig::decisionApi()
                ->setLogLevel(LogLevel::ERROR)
                ->setHitCacheImplementation(new HitCacheRedis())
                ->setCacheStrategy(CacheStrategy::BATCHING_AND_CACHING_ON_FAILURE)
        );

        self::$flagshipInitialized = true;
    }

    /**
     * Sends a hit to Flagship using the PHP SDK.
     * Flagship::close() is NOT called here — it is handled by the shutdown function
     * registered in ab-test-flagship.php, which batches all hits at the end of the request.
     *
     * @param string $visitorId
     * @param string $eventName
     * @param string $variant
     * @return array{success: bool, message: string}
     */
    private function sendHitToFlagship(string $visitorId, string $eventName, string $variant): array
    {
        if (!CredentialsManager::hasCredentials()) {
            error_log('[AB Test] Flagship credentials not found. Hit not sent.');
            return ['success' => false, 'message' => 'Flagship credentials not configured.'];
        }

        try {
            $this->initializeFlagship();

            $visitor = Flagship::newVisitor($visitorId, true)->build();
            $visitor->fetchFlags();

            $visitor->sendHit(
                (new Event(EventCategory::ACTION_TRACKING, $eventName))
                    ->setLabel($variant)
                    ->setValue(1)
            );

            error_log("[AB Test] Hit queued for Flagship. Visitor: {$visitorId}, Event: {$eventName}, Variant: {$variant}");

            return ['success' => true, 'message' => 'Hit sent successfully.'];
        } catch (\Exception $e) {
            error_log('[AB Test] Flagship hit error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send hit to Flagship.'];
        }
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
