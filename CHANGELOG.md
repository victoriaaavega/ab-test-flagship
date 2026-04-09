# Changelog

All notable changes to this project will be documented in this file.

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
