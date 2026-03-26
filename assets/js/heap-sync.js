/**
 * Heap Identity Sync
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
     * Simulates heap.identify() call
     *
     * @param {string} visitorId
     */
    function identifyVisitor(visitorId) {
        console.log('[Heap Sync] identify called with visitor ID:', visitorId);
    }

    const visitorId = getCookie('heap_visitor_id');

    if (visitorId) {
        identifyVisitor(visitorId);
    } else {
        console.warn('[Heap Sync] No visitor ID cookie found.');
    }
})();