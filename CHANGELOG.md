# Changelog

All notable changes to this project will be documented in this file.

## [1.6.0] - 2026-05-25

### Added
- VisitorIdProvider class — manages the visitor ID provider configuration stored
  in wp_options. Supports three providers: fingerprint (default, no JS dependency),
  heap (reads window.heap.userId), and custom (reads any admin-defined JS path
  such as window.myApp.user.id). Exposes getProvider(), getJsPath(),
  usesExternalId(), and getHashPrefix() so both PHP and the settings UI share
  a single source of truth.
- visitor-sync.js — generic replacement for heap-sync.js. Reads the JS path
  configured in the plugin settings, resolves it on the window object (supports
  dot-notation paths like window.myApp.user.id), writes the value to the
  abtf_visitor_id cookie, and calls the identify endpoint on the first write
  to reconcile fingerprint assignments. Fire-and-forget, non-blocking.
- Visitor ID Provider section in Settings page — three radio options
  (Fingerprint / Heap / Custom) with a conditional JS path input that appears
  only when Custom is selected. Saved via its own POST action (save_provider)
  following the same PRG pattern as credentials.

### Changed
- Fingerprint.php rewritten to use VisitorIdProvider instead of hardcoded
  Heap logic. Now reads VisitorIdProvider::COOKIE_NAME (abtf_visitor_id) when
  the active provider is heap or custom, and falls back to SHA256 fingerprint
  otherwise. The hash prefix (heap:, custom:) is sourced from
  VisitorIdProvider::getHashPrefix() so the namespace is always consistent
  with what IdentifyEndpoint produces.
- IdentifyEndpoint.php updated to accept external_visitor_id instead of
  heap_user_id. The hashed visitor ID is now built using
  VisitorIdProvider::getHashPrefix() so it matches exactly what
  Fingerprint::generateVisitorId() produces on the next page load. Validation
  relaxed to allow any non-empty string up to 255 chars (provider-agnostic).
- Settings.php extended with handleSaveProvider() write handler and
  renderPage() updated to include the new Visitor ID Provider section.
  New notice keys: provider_saved, provider_invalid, provider_js_path_required.
- ab-test-flagship.php updated to require VisitorIdProvider.php before
  Fingerprint.php. Enqueues visitor-sync.js only when the active provider
  requires JS-side ID resolution (usesExternalId() === true) — fingerprint
  sites load no extra JS. Passes visitorIdProvider and visitorIdJsPath to
  the abtfConfig object so visitor-sync.js needs no hardcoded values.
  Version bumped to 1.6.0.

### Removed
- heap-sync.js — replaced by the generic visitor-sync.js. The Heap provider
  is now just a preset that sets visitorIdJsPath to window.heap.userId.

### Migration note
- The cookie name changed from abtf_heap_id to abtf_visitor_id. Existing
  visitors in production will lose their cookie on deploy and be treated as
  first-time visitors for one page load, after which visitor-sync.js will
  write the new cookie and reconcile their assignments. No data is lost —
  IdentifyEndpoint copies assignments to the new visitor ID on reconciliation.
- Sites currently using Heap should go to AB Tests → Settings → Visitor ID
  Provider and select Heap after deploying. The default is Fingerprint.

## [1.5.0] - 2026-05-25

### Added
- IdentifyEndpoint class — POST /wp-json/abtest/v1/identify. Receives a
  fingerprint visitor ID and a Heap user ID, computes the Heap-based visitor ID
  (SHA256 of "heap:" + heap_user_id), and copies all variant assignments from
  the fingerprint visitor to the Heap visitor in both Redis and the database.
  Uses INSERT IGNORE so existing Heap assignments are never overwritten.
  Secured with the same nonce + rate limiter as EventEndpoint.
- abtf_get_cookie_domain() helper in ab-test-flagship.php — derives the shared
  cookie domain from home_url() (e.g. .castingnetworks.com in production,
  .test.test locally) so no domain is hardcoded anywhere in the codebase.
- cookieDomain and identifyUrl added to the abtfConfig object injected by
  wp_localize_script, making both values available to JavaScript without
  string manipulation or hardcoded URLs.

### Changed
- Fingerprint::generateVisitorId() now checks the abtf_heap_id cookie first.
  If a valid Heap user ID is present (numeric string, 1–20 digits), it returns
  SHA256("heap:" + heapUserId) as the visitor ID. Falls back to the original
  SHA256(IP + UA + Language) fingerprint when the cookie is absent or invalid.
  Output format is unchanged — always a 64-char hex string.
- heap-sync.js rewritten. Now reads window.heap.userId, validates it, and
  writes it to the abtf_heap_id cookie (domain from abtfConfig.cookieDomain,
  30-day TTL). On the first write (cookie previously absent), calls
  POST /abtest/v1/identify to copy fingerprint assignments to the Heap visitor
  ID — fire-and-forget, non-blocking. On subsequent page loads the cookie
  already matches and nothing is done. Removed the heap.identify() call with
  the fingerprint visitor ID — Heap's own persistent ID is now the source of
  truth, not the plugin's fingerprint.
- ExperimentRunner::run() no longer calls setHeapIdentityCookie(). The
  heap_visitor_id cookie is no longer written — abtf_heap_id written by
  heap-sync.js replaces it entirely.
- ExperimentRunner::setHeapIdentityCookie() removed.
- IdentifyEndpoint registered in plugins_loaded alongside EventEndpoint.

## [1.4.1] - 2026-05-21

### Changed
- Credentials are now stored exclusively in wp_options (encrypted). Support for
  PHP constants FLAGSHIP_ENV_ID and FLAGSHIP_API_KEY in wp-config.php has been
  removed to prevent credentials from leaking into version control.
- CredentialsManager::load() now reads only from wp_options. The PHP constants
  priority chain has been eliminated.
- Settings page always renders the editable form. The read-only mode shown when
  constants were defined in wp-config.php has been removed.
- Admin notice for missing credentials no longer references wp-config.php.
- FlagshipAdapter docblock updated to reflect that credentials come from
  CredentialsManager, not PHP constants.
- Settings docblock updated to remove reference to wp-config.php priority chain.

## [1.4.0] - 2026-05-13

### Added
- Encryption class — AES-256-CBC symmetric encryption using a key derived from
  WordPress AUTH_KEY + AUTH_SALT; credentials are never stored in plain text
- CredentialsManager class — reads Flagship credentials with a clear priority chain:
  PHP constants in wp-config.php → encrypted values in wp_options → null (SimulatorAdapter)
  Results are cached statically to avoid repeated decryption per request
- Settings page (AB Tests → Settings) — admin UI to save and remove Flagship
  credentials without touching wp-config.php; shows read-only view when constants
  are defined; API key field masked as password input
- "Remove Credentials" button in Settings page — deletes encrypted values from
  wp_options and falls back to SimulatorAdapter

### Changed
- abtf_runner() now uses CredentialsManager::hasCredentials() instead of checking
  PHP constants directly
- abtf_check_credentials() admin notice now links to the Settings page
- abtf_shutdown() now uses CredentialsManager::hasCredentials()
- FlagshipAdapter::initialize() reads credentials from CredentialsManager
- EventEndpoint::initializeFlagship() and sendHitToFlagship() read credentials
  from CredentialsManager

## [1.3.0] - 2026-05-12

### Added
- StatsRebuildJob class — aggregates wp_ab_test_assignments into wp_ab_test_stats
  using INSERT ... ON DUPLICATE KEY UPDATE to avoid empty-table windows during rebuild
- CronManager class — registers a custom 8-hour WP-Cron interval and schedules
  the abtf_rebuild_stats recurring event; unschedules cleanly on plugin deactivation
- wp_ab_test_stats table — pre-calculated totals (experiment_id, variant, total,
  last_rebuilt_at) with UNIQUE KEY on experiment_id + variant
- "Rebuild Stats" button in AB Tests admin page — triggers StatsRebuildJob manually
  without waiting for the next cron run
- Plugin deactivation hook — calls CronManager::unschedule() to remove the cron
  event from wp_cron on deactivation
- ExperimentsPage and AutoInjector classes (Step 2, shipped with this version)

### Changed
- MetaBox now reads from wp_ab_test_stats instead of running COUNT(*) live on
  wp_ab_test_assignments — eliminates slow queries at scale
- MetaBox shows last_rebuilt_at timestamp so admins know how fresh the data is
- Database TABLE_VERSION bumped to 1.2 to trigger creation of wp_ab_test_stats
  on existing installs
- cron_schedules filter registered at plugin load time (before plugins_loaded)
  to guarantee the custom interval is available when wp_schedule_event() runs

## [1.2.1] - 2026-04-09

### Fixed
- Static cache added to RateLimiter to prevent double-counting — WordPress
  calls permission_callback more than once per request internally, which
  was incrementing the Redis counter twice per hit and halving the effective limit
- Nonce verification failing with 403 for logged-in users — added
  abtf_create_public_nonce() to generate nonces in user-0 context so
  wp_verify_nonce() works correctly in REST API requests regardless of
  authentication state. Discovered during portability test with test-portability theme.
- event-tracker.js now correctly identifies WordPress error responses
  using data.code check in addition to data.success === false — WordPress
  REST API errors use {code, message} structure, causing rejections to
  log as "Hit sent" instead of "Hit rejected by server".
  Discovered during nonce expiry test.

### Tested
- Fallback to database with Redis down — variants served correctly, no visible errors
- Two simultaneous experiments on the same page — each decides independently
- Nonce expiry — 403 handled correctly with no retries
- New vs returning visitor with Redis active — returning visitor served from Redis without calling Flagship
- SimulatorAdapter without credentials — deterministic bucketing, admin notice shown, hits rejected cleanly
- Endpoint security from outside — 401 missing nonce, 403 invalid nonce, 400 invalid params
- Concurrent load with k6 — 10 virtual users, 40s, zero 500 errors, rate limiter triggered correctly

## [1.2.0] - 2026-04-08

### Added
- RateLimiter class using Redis DB 2 to cap requests per IP at 20/minute
- Atomic increment + TTL via Lua script in RateLimiter to prevent race conditions
- Rate limit check in EventEndpoint::validateRequest() after nonce validation — returns 429 on exceeded limit
- Fail-open behavior in RateLimiter when Redis is unavailable — real users are never blocked by a cache outage
- IP hashing (SHA256) in RateLimiter — raw IPs are never stored in Redis
- getClientIp() method in EventEndpoint — mirrors Fingerprint.php logic for Cloudflare + proxy support
- Static cache in RateLimiter to prevent double-counting caused by WordPress calling permission_callback multiple times per request

### Fixed
- event-tracker.js now logs a warning instead of "Hit sent" when the server rejects a request (e.g. 429)

## [1.1.0] - 2026-04-06

### Fixed
- SimulatorAdapter class mismatch — file contained FlagshipAdapter code instead of local bucketing logic
- Missing return statement in EventEndpoint::handleEvent() for the success path (PHP TypeError in production)
- Flagship re-initialization on every event request — added static guard in EventEndpoint
- Double Flagship::close() call — removed inline call from EventEndpoint, kept only in shutdown function
- abtf_shutdown() now guards against calling Flagship::close() when credentials are not configured
- Removed duplicate vendor/autoload.php require from FlagshipAdapter (already loaded in main plugin file)
- Replaced Redis KEYS command with SCAN in HitCacheRedis::lookupHits() to prevent blocking in production
- event-tracker.js now guards against undefined variant before registering a listener
- event-tracker.js retry logic now only triggers on 5xx server errors, not on 4xx client errors

### Changed
- Plugin version bumped to 1.1.0
- composer.json now includes PSR-4 classmap autoload section

## [1.0.0] - 2026-03-26

### Added
- Server-side A/B testing using AB Tasty Flagship SDK
- Redis cache for variant assignments with database fallback
- REST API endpoint for event tracking
- Nonce validation for endpoint security
- Retry logic in event-tracker.js (up to 3 attempts)
- HitCacheRedis for failed hit recovery
- Dashboard metabox showing experiment stats
- Fingerprint-based visitor ID generation
- Adapter pattern for decision engine (SimulatorAdapter, FlagshipAdapter)
- Support for multiple experiments on the same page
