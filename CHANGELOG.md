# Changelog

All notable changes to this project will be documented in this file.

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
