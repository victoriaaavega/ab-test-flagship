<?php

if (!defined('ABSPATH')) {
    exit;
}

class EventEndpoint {

    public function __construct() {
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    public function registerRoute(): void {
        register_rest_route('abtest/v1', '/event', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleEvent'],
            'permission_callback' => [$this, 'validateRequest'],
            'args'                => [
                'visitor_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn($value) => !empty($value) && strlen($value) <= 64,
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
     * Validates that the request comes from the site using WordPress nonce
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function validateRequest(WP_REST_Request $request): bool|WP_Error {
        $nonce = $request->get_header('X-WP-Nonce');

        if (empty($nonce)) {
            return new WP_Error(
                'missing_nonce',
                'Nonce is required.',
                ['status' => 401]
            );
        }

        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error(
                'invalid_nonce',
                'Invalid or expired nonce.',
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Handles incoming event requests
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handleEvent(WP_REST_Request $request): WP_REST_Response {
        $visitorId    = $request->get_param('visitor_id');
        $experimentId = $request->get_param('experiment_id');
        $eventName    = $request->get_param('event_name');
        $variant      = $request->get_param('variant');

        error_log("[AB Test] Event received. Experiment: {$experimentId}, Visitor: {$visitorId}, Event: {$eventName}, Variant: {$variant}");

        $result = $this->sendHitToFlagship($visitorId, $experimentId, $eventName, $variant);

        if (!$result['success']) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result['message'],
            ], 500);
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
     * Sends a hit to Flagship
     *
     * @param string $visitorId
     * @param string $experimentId
     * @param string $eventName
     * @param string $variant
     * @return array{success: bool, message: string}
     */
    private function sendHitToFlagship(string $visitorId, string $experimentId, string $eventName, string $variant): array {
        error_log("[AB Test] Hit would be sent to Flagship. Visitor: {$visitorId}, Experiment: {$experimentId}, Event: {$eventName}, Variant: {$variant}");

        return [
            'success' => true,
            'message' => 'Hit sent successfully.',
        ];
    }
}

new EventEndpoint();