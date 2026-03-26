# Changelog

All notable changes to this project will be documented in this file.

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