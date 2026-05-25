/**
 * Heap Identity Sync
 *
 * Reads window.heap.userId (Heap's own persistent visitor identifier) and writes
 * it to a first-party cookie shared across all subdomains of the current domain.
 *
 * On the very first write (cookie did not exist before), calls the identify
 * endpoint to copy any variant assignments stored under the fingerprint visitor
 * ID to the Heap visitor ID. This ensures the user sees the same variant on
 * their second visit even though PHP switches from fingerprint to Heap ID.
 *
 * From the second visit onwards the cookie already exists, so this script
 * does nothing beyond confirming it is up to date.
 */

(function () {

    var COOKIE_NAME = 'abtf_heap_id';
    var COOKIE_TTL  = 60 * 60 * 24 * 30; // 30 days in seconds

    /**
     * Writes the Heap user ID to a first-party cookie readable by PHP.
     *
     * @param {string} heapUserId
     * @param {string} cookieDomain  e.g. '.castingnetworks.com' or '.test.test'
     */
    function writeHeapIdCookie(heapUserId, cookieDomain) {
        var value      = encodeURIComponent(heapUserId);
        var secure     = location.protocol === 'https:' ? '; Secure' : '';
        var domainPart = cookieDomain ? '; domain=' + cookieDomain : '';

        document.cookie = COOKIE_NAME + '=' + value
            + '; max-age=' + COOKIE_TTL
            + '; path=/'
            + domainPart
            + '; SameSite=Lax'
            + secure;

        console.log('[Heap Sync] abtf_heap_id cookie written:', heapUserId, '| domain:', cookieDomain || '(current)');
    }

    /**
     * Reads the current value of the abtf_heap_id cookie.
     *
     * @returns {string|null}
     */
    function readHeapIdCookie() {
        var match = document.cookie
            .split('; ')
            .find(function (row) { return row.startsWith(COOKIE_NAME + '='); });

        return match ? decodeURIComponent(match.split('=')[1]) : null;
    }

    /**
     * Calls the identify endpoint to copy fingerprint assignments to the Heap
     * visitor ID. Fire-and-forget — errors are logged but do not affect the user.
     *
     * @param {string} fingerprintVisitorId  window.abTestData.visitorId from this page load
     * @param {string} heapUserId
     */
    function reconcile(fingerprintVisitorId, heapUserId) {
        if (!fingerprintVisitorId || !window.abtfConfig) {
            return;
        }

        var identifyUrl = window.abtfConfig.identifyUrl;

        fetch(identifyUrl, {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'X-ABTF-Nonce':  window.abtfConfig.nonce,
            },
            body: JSON.stringify({
                fingerprint_visitor_id: fingerprintVisitorId,
                heap_user_id:           heapUserId,
            }),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                console.log('[Heap Sync] Reconciliation complete. Assignments copied:', data.copied);
            })
            .catch(function (error) {
                console.warn('[Heap Sync] Reconciliation failed (non-blocking):', error);
            });
    }

    /**
     * Main sync function.
     */
    function syncHeapId() {
        if (typeof window.heap === 'undefined' || !window.heap.userId) {
            console.warn('[Heap Sync] Heap not available or userId not set.');
            return;
        }

        var heapUserId = String(window.heap.userId);

        if (!/^[1-9]\d{0,19}$/.test(heapUserId)) {
            console.warn('[Heap Sync] Unexpected heap.userId format:', heapUserId);
            return;
        }

        var existing     = readHeapIdCookie();
        var cookieDomain = (window.abtfConfig && window.abtfConfig.cookieDomain)
            ? window.abtfConfig.cookieDomain
            : '';

        if (existing === heapUserId) {
            console.log('[Heap Sync] Cookie already up to date:', heapUserId);
            return;
        }

        // First time writing the cookie — reconcile fingerprint assignments.
        var isFirstWrite = existing === null;

        writeHeapIdCookie(heapUserId, cookieDomain);

        if (isFirstWrite && window.abTestData && window.abTestData.visitorId) {
            console.log('[Heap Sync] First write — reconciling fingerprint:', window.abTestData.visitorId);
            reconcile(window.abTestData.visitorId, heapUserId);
        }
    }

    syncHeapId();

})();