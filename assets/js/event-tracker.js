/**
 * AB Test Event Tracker
 *
 * Reads the experiment configuration defined in window.abTestConfig and registers
 * event listeners for each element. When an event is detected, it sends a hit to
 * the WordPress REST API endpoint which forwards it to Flagship.
 */

(function () {

    /**
     * Sends a hit event to the WordPress REST API endpoint.
     * Retries up to 3 times if the request fails with a server error.
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
                if (data.success === false || data.code) {
                    console.warn('[AB Test] Hit rejected by server:', data.message);
                    return;
                }
                console.log('[AB Test] Hit sent:', data);
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