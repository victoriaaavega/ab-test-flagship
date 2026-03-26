/**
 * AB Test Event Tracker
 *
 * Reads the experiment configuration defined in window.abTestConfig and registers click listeners for each element,
 * when a click is detected, it sends a hit to the WordPress REST API endpoint which forwards it to Flagship
 */

(function () {

    /**
     * Sends a hit event to the WordPress REST API endpoint
     * Retries up to 3 times if the request fails
     *
     * @param {string} experimentId
     * @param {string} eventName
     * @param {string} variant
     * @param {number} attempt
     */
    function sendHit(experimentId, eventName, variant, attempt = 1) {
        const MAX_ATTEMPTS = 3;
        const RETRY_DELAY_MS = 1000;
        const { visitorId, apiUrl, nonce } = window.abTestData;

        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            body: JSON.stringify({
                visitor_id:    visitorId,
                experiment_id: experimentId,
                event_name:    eventName,
                variant:       variant
            })
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Server responded with status: ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
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
     * Registers event listeners for all experiments defined in window.abTestConfig
     */
    function registerListeners() {
        if (!window.abTestConfig || !window.abTestData) {
            console.warn('[AB Test] abTestConfig or abTestData not found.');
            return;
        }

        window.abTestConfig.forEach(function (config) {
            const element = document.querySelector(config.selector);

            if (!element) {
                console.warn('[AB Test] Element not found for selector:', config.selector);
                return;
            }

            const variant = window.abTestData.experiments[config.experimentId];
            const eventType = config.type || 'click';

            element.addEventListener(eventType, function () {
                console.log('[AB Test] Event detected:', eventType, 'on:', config.selector);
                sendHit(config.experimentId, config.eventName, variant);
            });

            console.log('[AB Test] Listener registered for:', config.selector);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', registerListeners);
    } else {
        registerListeners();
    }

})();