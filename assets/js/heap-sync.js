/**
 * Heap Identity Sync
 *
 * Reads the visitor ID cookie set by PHP and calls heap.identify()
 * to sync the server-side visitor ID with Heap Analytics.
 */

(function () {

    /**
     * Reads a cookie value by name
     *
     * @param {string} name
     * @returns {string|null}
     */
    function getCookie(name) {
        const match = document.cookie
            .split('; ')
            .find((row) => row.startsWith(name + '='));

        return match ? decodeURIComponent(match.split('=')[1]) : null;
    }

    /**
     * Calls heap.identify() with the visitor ID
     * Waits for Heap to be available if it hasn't loaded yet
     *
     * @param {string} visitorId
     */
    function identifyVisitor(visitorId) {
        if (typeof window.heap !== 'undefined' && typeof window.heap.identify === 'function') {
            window.heap.identify(visitorId);
            console.log('[Heap Sync] identify called with visitor ID:', visitorId);
        } else {
            console.warn('[Heap Sync] Heap not available. Visitor ID not synced:', visitorId);
        }
    }

    const visitorId = getCookie('heap_visitor_id');

    if (visitorId) {
        identifyVisitor(visitorId);
    } else {
        console.warn('[Heap Sync] No visitor ID cookie found.');
    }

})();