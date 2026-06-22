<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sends activate hits to Flagship via the Decision API's /activate endpoint.
 *
 * An activate hit tells Flagship that a visitor has been exposed to a specific
 * variation. It MUST be sent for a visitor before their conversion events can
 * be attributed to the campaign in reporting.
 *
 * This is done with a direct HTTP call (wp_remote_post) rather than relying on
 * the SDK's batching pool: the pool only flushes on Flagship::close(), and a
 * returning visitor served straight from Redis never re-triggers the SDK's
 * exposure, so the activate would otherwise be missed.
 *
 * Endpoint: POST https://decision.flagship.io/v2/activate  → 204 No Content
 */
class FlagshipActivator
{
    private const ACTIVATE_URL    = 'https://decision.flagship.io/v2/activate';
    private const REQUEST_TIMEOUT = 5; // seconds

    /**
     * Sends an activate hit for a visitor + variation.
     *
     * @param string $visitorId        The visitor ID (vid)
     * @param string $variationGroupId The variation group ID (caid)
     * @param string $variationId      The variation ID (vaid)
     * @return bool True on success (HTTP 2xx), false otherwise
     */
    public function activate(string $visitorId, string $variationGroupId, string $variationId): bool
    {
        $envId = CredentialsManager::getEnvId();

        if ($envId === null) {
            Logger::error('FlagshipActivator: no credentials, activate skipped.');
            return false;
        }

        $payload = [
            'vid'  => $visitorId,
            'cid'  => $envId,
            'caid' => $variationGroupId,
            'vaid' => $variationId,
        ];

        $body = wp_json_encode($payload);

        // Guard against an unserializable payload (see EventEndpoint). A flat
        // array of strings should always encode, but a false body would send
        // an empty activate that Flagship cannot attribute.
        if ($body === false) {
            Logger::error('FlagshipActivator: failed to JSON-encode the activate payload. Activate not sent.');
            return false;
        }

        $response = wp_remote_post(self::ACTIVATE_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            Logger::error('FlagshipActivator failed (wp_error): ' . $response->get_error_message());
            return false;
        }

        $statusCode = wp_remote_retrieve_response_code($response);

        // The activate endpoint returns 204 No Content on success.
        if ($statusCode < 200 || $statusCode >= 300) {
            $body = wp_remote_retrieve_body($response);
            Logger::error("FlagshipActivator failed. Status: {$statusCode}, Body: {$body}");
            return false;
        }

        Logger::debug("Visitor activated. Visitor: {$visitorId}, caid: {$variationGroupId}, vaid: {$variationId}");

        return true;
    }
}