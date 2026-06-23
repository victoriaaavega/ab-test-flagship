/**
 * AB Test Event Tracker
 *
 * Reads the experiment configuration defined in window.abTestConfig and registers
 * event listeners for each element. When an event is detected, it sends a hit to
 * the WordPress REST API endpoint which records the conversion internally and
 * forwards it to Flagship.
 *
 * Endpoint response shape (HTTP 200, our own payload):
 *   { success: bool, flagship: 'sent'|'failed'|'skipped', message: string, ... }
 *     - success  → the conversion was recorded internally (the real contract).
 *     - flagship → outcome of the secondary, best-effort delivery to Flagship.
 *                  A 'failed'/'skipped' flagship value does NOT mean the
 *                  conversion was lost — it was still counted internally.
 *
 * WordPress-level errors (nonce, rate limit) use a different shape: { code, message }
 * with a 4xx status, and must still be treated as a server rejection (no retry).
 * Server errors (5xx) trigger the retry logic.
 */

(function () {

    /**
     * Sends a hit event to the WordPress REST API endpoint.
     * Retries up to 3 times if the request fails with a server error (5xx).
     *
     * @param {string} experimentId
     * @param {string} eventName
     * @param {string} variant
     * @param {number} attempt
     */
    function sendHit(experimentId, eventName, variant, attempt = 1) {
        const MAX_ATTEMPTS   = 3;
        const RETRY_DELAY_MS = 1000;
        const { visitorId }  = window.abTestData;
        const { apiUrl, nonce } = window.abtfConfig;

        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-ABTF-Nonce': nonce
            },
            body: JSON.stringify({
                visitor_id:    visitorId,
                experiment_id: experimentId,
                event_name:    eventName,
                variant:       variant,
                page_url:      window.location.href,
            })
        })
            .then(function (response) {
                if (response.status >= 500) {
                    throw new Error('Server error: ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                // WordPress-level rejection (nonce, rate limit, validation):
                // these carry a `code` field and arrive with a 4xx status.
                // Not retried — the client cannot fix them by retrying.
                if (data.code) {
                    console.warn('[AB Test] Hit rejected by server:', data.message);
                    return;
                }

                // Our own payload. success === false means the conversion could
                // NOT be recorded internally (e.g. Redis down) — a real problem
                // worth surfacing, distinct from a server rejection.
                if (data.success === false) {
                    console.warn('[AB Test] Conversion not recorded internally:', data.message);
                    return;
                }

                // Conversion recorded. Report the secondary Flagship outcome for
                // visibility, without treating a Flagship miss as a failure of
                // the conversion itself.
                switch (data.flagship) {
                    case 'sent':
                        console.log('[AB Test] Conversion recorded. Flagship: sent.', data);
                        break;
                    case 'failed':
                        console.warn('[AB Test] Conversion recorded, but Flagship delivery failed (counted internally).', data);
                        break;
                    case 'skipped':
                        console.log('[AB Test] Conversion recorded. Flagship: skipped (no credentials).', data);
                        break;
                    default:
                        console.log('[AB Test] Conversion recorded.', data);
                }
            })
            .catch(function (error) {
                console.error('[AB Test] Failed to send hit (attempt ' + attempt + '):', error);

                if (attempt < MAX_ATTEMPTS) {
                    console.log('[AB Test] Retrying in ' + RETRY_DELAY_MS + 'ms...');
                    setTimeout(function () {
                        sendHit(experimentId, eventName, variant, attempt + 1);
                    }, RETRY_DELAY_MS);
                } else {
                    console.error('[AB Test] Max attempts reached. Hit lost for event: ' + eventName);
                }
            });
    }

    /**
     * Registers event listeners for all experiments defined in window.abTestConfig.
     */
    function registerListeners() {
        if (!window.abTestConfig || !window.abTestData || !window.abtfConfig) {
            console.warn('[AB Test] abTestConfig, abTestData or abtfConfig not found.');
            return;
        }

        window.abTestConfig.forEach(function (config) {
            const element = document.querySelector(config.selector);

            if (!element) {
                console.warn('[AB Test] Element not found for selector:', config.selector);
                return;
            }

            const variant = window.abTestData.experiments[config.experimentId];

            if (variant === undefined || variant === null) {
                console.warn('[AB Test] Variant not found for experiment:', config.experimentId);
                return;
            }

            const eventType = config.type || 'click';

            element.addEventListener(eventType, function () {
                console.log('[AB Test] Event detected:', eventType, 'on:', config.selector);
                sendHit(config.experimentId, config.eventName, variant);
            });

            console.log('[AB Test] Listener registered for:', config.selector, '| experiment:', config.experimentId, '| variant:', variant);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', registerListeners);
    } else {
        registerListeners();
    }

})();