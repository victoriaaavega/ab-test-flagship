# Changelog

All notable changes to this project will be documented in this file.

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
