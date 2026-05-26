/**
 * AB Test Visitor ID Sync
 *
 * Reads a visitor ID from the source configured in the plugin settings
 * (window.heap.userId, a custom JS path, etc.) and writes it to a first-party
 * cookie shared across all subdomains of the current domain.
 *
 * On the very first write (cookie did not exist before), calls the identify
 * endpoint to copy any variant assignments stored under the fingerprint visitor
 * ID to the external visitor ID. This ensures the user sees the same variant
 * on their second visit even though PHP switches from fingerprint to external ID.
 *
 * From the second visit onwards the cookie already exists and matches, so this
 * script does nothing beyond confirming it is up to date.
 *
 * Configuration is injected by PHP via wp_localize_script as abtfConfig:
 *   abtfConfig.visitorIdProvider  — 'heap' | 'custom'
 *   abtfConfig.visitorIdJsPath    — e.g. 'window.heap.userId' or 'window.myApp.user.id'
 *   abtfConfig.cookieDomain       — e.g. '.castingnetworks.com'
 *   abtfConfig.identifyUrl        — REST endpoint URL
 *   abtfConfig.nonce              — WordPress nonce
 */

(function () {

    var COOKIE_NAME = 'abtf_visitor_id';
    var COOKIE_TTL  = 60 * 60 * 24 * 30; // 30 days in seconds

    // -------------------------------------------------------------------------
    // Cookie helpers
    // -------------------------------------------------------------------------

    /**
     * Writes the visitor ID to a first-party cookie readable by PHP.
     *
     * @param {string} visitorId
     * @param {string} cookieDomain  e.g. '.castingnetworks.com' or '.test.test'
     */
    function writeCookie(visitorId, cookieDomain) {
        var value      = encodeURIComponent(visitorId);
        var secure     = location.protocol === 'https:' ? '; Secure' : '';
        var domainPart = cookieDomain ? '; domain=' + cookieDomain : '';

        document.cookie = COOKIE_NAME + '=' + value
            + '; max-age=' + COOKIE_TTL
            + '; path=/'
            + domainPart
            + '; SameSite=Lax'
            + secure;

        console.log('[AB Test Sync] Cookie written:', visitorId, '| domain:', cookieDomain || '(current)');
    }

    /**
     * Reads the current value of the abtf_visitor_id cookie.
     *
     * @returns {string|null}
     */
    function readCookie() {
        var match = document.cookie
            .split('; ')
            .find(function (row) { return row.startsWith(COOKIE_NAME + '='); });

        return match ? decodeURIComponent(match.split('=')[1]) : null;
    }

    // -------------------------------------------------------------------------
    // JS path resolver
    // -------------------------------------------------------------------------

    /**
     * Resolves a dot-notation path on the window object.
     * e.g. 'window.heap.userId' → window.heap.userId
     *      'window.myApp.user.id' → window.myApp.user.id
     *
     * Returns null if any segment in the path is undefined or null.
     *
     * @param {string} path
     * @returns {string|null}
     */
    function resolveJsPath(path) {
        var normalized = path.replace(/^window\./, '');
        var segments   = normalized.split('.');
        var current    = window;

        for (var i = 0; i < segments.length; i++) {
            if (current === null || current === undefined) {
                return null;
            }
            current = current[segments[i]];
        }

        if (current === null || current === undefined || current === '') {
            return null;
        }

        return String(current);
    }

    // -------------------------------------------------------------------------
    // Identify endpoint
    // -------------------------------------------------------------------------

    /**
     * Calls the identify endpoint to copy fingerprint assignments to the
     * external visitor ID. Fire-and-forget — errors are logged but do not
     * affect the user experience.
     *
     * @param {string} fingerprintVisitorId  window.abTestData.visitorId from this page load
     * @param {string} externalId            raw value read from the JS path
     */
    function reconcile(fingerprintVisitorId, externalId) {
        if (!fingerprintVisitorId || !window.abtfConfig) {
            return;
        }

        fetch(window.abtfConfig.identifyUrl, {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'X-ABTF-Nonce':  window.abtfConfig.nonce,
            },
            body: JSON.stringify({
                fingerprint_visitor_id: fingerprintVisitorId,
                external_visitor_id:    externalId,
            }),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                console.log('[AB Test Sync] Reconciliation complete. Assignments copied:', data.copied);
            })
            .catch(function (error) {
                console.warn('[AB Test Sync] Reconciliation failed (non-blocking):', error);
            });
    }

    // -------------------------------------------------------------------------
    // Main
    // -------------------------------------------------------------------------

    function sync() {
        if (!window.abtfConfig) {
            console.warn('[AB Test Sync] abtfConfig not found.');
            return;
        }

        var jsPath = window.abtfConfig.visitorIdJsPath;

        if (!jsPath) {
            // Provider is fingerprint — nothing to sync.
            return;
        }

        var externalId = resolveJsPath(jsPath);

        if (!externalId) {
            console.warn('[AB Test Sync] Could not resolve visitor ID from path:', jsPath);
            return;
        }

        var existing     = readCookie();
        var cookieDomain = window.abtfConfig.cookieDomain || '';

        if (existing === externalId) {
            console.log('[AB Test Sync] Cookie already up to date:', externalId);
            return;
        }

        var isFirstWrite = existing === null;

        writeCookie(externalId, cookieDomain);

        if (isFirstWrite && window.abTestData && window.abTestData.visitorId) {
            console.log('[AB Test Sync] First write — reconciling fingerprint:', window.abTestData.visitorId);
            reconcile(window.abTestData.visitorId, externalId);
        }
    }

    /**
     * Waits for window.abTestData to be available before syncing.
     * AutoInjector writes it in wp_footer at priority 99, which fires
     * after the script tag but before the closing </body>. Polling ensures
     * abTestData.visitorId is available when sync() runs.
     * Polls every 50ms for up to 3 seconds then syncs without reconciliation.
     */
    function waitForAbTestData(callback) {
        var attempts   = 0;
        var maxAttempts = 60; // 3 seconds at 50ms intervals

        var interval = setInterval(function () {
            attempts++;

            if (window.abTestData && window.abTestData.visitorId) {
                clearInterval(interval);
                callback();
                return;
            }

            if (attempts >= maxAttempts) {
                clearInterval(interval);
                console.warn('[AB Test Sync] abTestData not available after 3s. Syncing without reconciliation.');
                sync();
            }
        }, 50);
    }

    waitForAbTestData(sync);

})();