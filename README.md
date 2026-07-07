# Server-Side A/B Testing

WordPress plugin for server-side A/B testing using the AB Tasty Flagship SDK. Eliminates flicker by deciding variants in PHP before rendering HTML, with Redis caching, database fallback, and event tracking.

---

## How it works

```
User visits page
      ↓
PHP generates visitor ID
  → External provider cookie present? (heap, custom) → use the RAW ID as-is
  → No cookie yet?                                   → SHA256 fingerprint (IP + UA + Language)
      ↓
Redis has variant? → Yes → serve it (< 1ms)
                  → No  → Flagship decides → save to Redis + DB
      ↓
Activate hit → Flagship (once per visitor per experiment, dedup via Redis SET NX)
      ↓
PHP renders HTML with correct variant (no flicker)
      ↓
JS registers click listeners
visitor-sync.js writes abtf_visitor_id cookie → calls /identify on first write
      ↓
User clicks
      ↓
JS sends POST to REST API endpoint
      ↓
PHP records the conversion internally (Redis DB 3) → forwards hit to Flagship
```

The activate hit exposes the visitor to their assigned variation and must precede conversion events so Flagship can attribute them in reporting. It is sent with a direct HTTP call (not the SDK pool), so a returning visitor served straight from Redis is still activated.

**Visitor ID hashing:** only the fingerprint is hashed (SHA256), because it processes the visitor's IP address and must never be stored or sent in clear. The heap and custom providers return the **raw** ID, so the value that reaches Flagship matches exactly the one the team's own integration sends to AB Tasty. This avoids counting the same person as two visitors. As a result, the visitor ID is no longer always a 64-char hex string: with heap it is Heap's own format (e.g. a numeric userId).

---

## Requirements

- PHP 8.1+
- WordPress 6.5+
- Redis (phpredis extension)
- Composer
- AB Tasty Flagship account

---

## Installation

**1. Clone or download the plugin into your WordPress plugins directory:**

```
wp-content/plugins/server-side-a-b-testing/
```

**2. Install PHP dependencies:**

```bash
cd wp-content/plugins/server-side-a-b-testing
composer install
```

**3. Configure your Flagship credentials:**

Go to **AB Tests → Settings** and enter your Environment ID and API Key. They will be encrypted using AES-256-CBC before being stored in the database.

**4. Activate the plugin** from the WordPress admin under **Plugins**.

**5. Verify** — go to **AB Tests → Settings** to confirm credentials are configured. If missing, a red notice will appear in the WordPress admin dashboard with a link to the Settings page.

---

## Without credentials (local development)

If no credentials are configured, the plugin automatically uses `SimulatorAdapter` — a local bucketing engine that makes deterministic 50/50 decisions using `crc32` without any network calls.

This means you can develop and test experiments locally without a Flagship account.

---

## Visitor ID providers

The plugin supports three strategies for identifying returning visitors, configurable under **AB Tests → Settings → Visitor ID Provider**:

| Provider | How it works | Hashed? | JS dependency |
|---|---|---|---|
| **Fingerprint** (default) | SHA256 of IP + User-Agent + Accept-Language | Yes (protects the IP) | None |
| **Heap** | Reads `window.heap.userId`, writes `abtf_visitor_id` cookie | No (raw ID) | Heap Analytics snippet |
| **Custom** | Reads any JS path (e.g. `window.myApp.user.id`), writes `abtf_visitor_id` cookie | No (raw ID) | Your analytics tool |

**Why heap/custom are not hashed:** the ID that reaches Flagship must match exactly the ID the team's own integration sends to AB Tasty (the raw Heap userId). Hashing it would produce a mismatch and count the same human as two visitors. Fingerprint is hashed because it derives from the visitor's IP, which is personal data.

**First visit flow (external provider):**
1. No cookie yet → PHP uses fingerprint to assign variant
2. JS resolves the external ID → writes `abtf_visitor_id` cookie
3. JS calls `POST /identify` → copies fingerprint assignments to the external visitor ID (fire-and-forget)

**Subsequent visits:**
1. Cookie present → PHP uses the external ID directly (raw) → variant found in Redis in < 1ms
2. JS sees cookie already up to date → does nothing

---

## Usage in a theme

The plugin injects `window.abTestData` and `window.abTestConfig` automatically via `AutoInjector` for any active experiment whose URL rules match the current page. **No theme code is required for the standard setup.**

If you need to render different HTML server-side based on the variant, use `abtf_runner()` directly:

```php
<?php
$runner    = abtf_runner();
$result    = $runner->run('your_flag_key'); // flag key as defined in Flagship dashboard
$variant   = $result['variant'];
$visitorId = $result['visitorId'];
?>
```

Then in your template:

```php
<?php if ($variant === 'control'): ?>
    <!-- original version -->
<?php else: ?>
    <!-- variant version -->
<?php endif; ?>
```

> **Note:** When using `abtf_runner()` directly, `AutoInjector` still injects `window.abTestData` and `window.abTestConfig` automatically. You do not need to write those variables manually.

### Multiple experiments on the same page

```php
$runner = abtf_runner();

$result1  = $runner->run('experiment_flag_1');
$variant1 = $result1['variant'];

$result2  = $runner->run('experiment_flag_2');
$variant2 = $result2['variant'];

$visitorId = $result1['visitorId']; // same visitor ID across all experiments in the same session
```

---

## Redis

The plugin uses four Redis DB indexes to keep data separated:

| DB | Purpose |
|---|---|
| 0 | Variant assignments (`ab_test:variant:{experimentId}:{visitorId}`) and the activation guard (`ab_test:activated:{experimentId}:{visitorId}`) |
| 1 | Hit cache for failed Flagship hits (auto-retry on next request) |
| 2 | Rate limiter counters (`abtf:rate:{ip_hash}`) |
| 3 | Conversion counters (`abtf:conv:total:` / `abtf:conv:unique:` + `{experimentId}:{variant}:{eventName}`) |

DB 3 uses an `INCR` counter for total conversions and a HyperLogLog (`PFADD`/`PFCOUNT`) for unique conversions — roughly 12 KB fixed per key regardless of traffic volume.

**Encoding note:** DB 0 stores spaces raw; DB 3 uses `rawurlencode` (space → `%20`). Each DB is consistent with itself. To delete DB 0 keys containing spaces from `redis-cli`, use `while IFS= read -r key`, not `xargs` (which breaks on spaces).

**Variant TTL:** 30 days. If Redis is unavailable, the plugin falls back to the WordPress database automatically.

---

## REST API endpoints

### POST /wp-json/abtest/v1/event

Records a conversion internally (Redis DB 3) and forwards the hit to Flagship.

**Headers:**
```
Content-Type: application/json
X-ABTF-Nonce: {nonce injected automatically by the plugin}
```

**Body:**
```json
{
    "visitor_id":    "fingerprint: 64-char SHA256 hex — heap/custom: raw provider ID",
    "experiment_id": "your_flag_key",
    "event_name":    "your_event_name",
    "variant":       "control",
    "page_url":      "https://example.com/page"
}
```

> **Note:** the `visitor_id` format depends on the active provider. With fingerprint it is a 64-char SHA256 hex string; with heap or custom it is the raw provider ID (see "Visitor ID providers").

**Response (HTTP 200):**
```json
{
    "success":    true,
    "flagship":   "sent",
    "message":    "Conversion recorded.",
    "experiment": "your_flag_key",
    "event":      "your_event_name",
    "variant":    "control"
}
```

The internal recording is the endpoint's contract: `success: true` means the conversion was counted in the live dashboard. The `flagship` field reports the secondary delivery (`sent` / `failed` / `skipped`) without suppressing a conversion the user genuinely made — the internal channel is independent of Flagship. `success: false` only occurs when the internal store (Redis) is unavailable.

**Rate limiting:** 20 requests per IP per minute. Exceeding the limit returns `429 Too Many Requests`. The JS tracker does not retry on 4xx errors (only on 5xx).

### POST /wp-json/abtest/v1/identify

Called once per user lifetime by `visitor-sync.js` on the first write of the `abtf_visitor_id` cookie. Copies all variant assignments from the fingerprint visitor ID to the external visitor ID in both Redis and the database.

**Body:**
```json
{
    "fingerprint_visitor_id": "64-char SHA256 hex string",
    "external_visitor_id":    "raw value from the JS provider"
}
```

Uses `INSERT IGNORE` — never overwrites existing assignments under the external visitor ID.

---

## Logging

The plugin uses a central `Logger` class gated by the `ABTF_LOG_LEVEL` constant in `wp-config.php`:

| Level | Logs |
|---|---|
| `error` (default) | Real errors only (Redis down, hit failed, decryption failed). Safe for production. |
| `info` | Lifecycle events (stats rebuild completed). |
| `debug` | Per-request detail (assignments, decisions). Verbose — development only. |

```php
define('ABTF_LOG_LEVEL', 'debug');   // development
define('ABTF_LOG_LEVEL', 'error');   // production (or omit — error is the default)
```

**PHP logging** writes through the `Logger` class to the PHP error log, prefixed `[AB Test]`.

**JS logging** (`event-tracker.js` and `visitor-sync.js`) is gated by the same switch: the plugin exposes `abtfConfig.debug` (true only when `ABTF_LOG_LEVEL` is `debug`) and the browser console output respects it. In production the console stays clean; only real failures use `console.error` and are always emitted. Debug messages are prefixed `[AB Test]` (event tracker) and `[AB Test Sync]` (visitor sync).

---

## File structure

```
server-side-a-b-testing/
├── server-side-a-b-testing.php            ← plugin entry point
├── composer.json                          ← PHP dependencies
├── phpstan.neon                           ← static analysis config (level 6)
├── phpstan-constants.php                  ← WordPress constants for PHPStan
├── readme.txt                             ← WordPress.org readme
├── vendor/                                ← Flagship SDK (not in Git)
├── assets/
│   └── js/
│       ├── event-tracker.js               ← captures events and sends hits (retry on 5xx only)
│       └── visitor-sync.js                ← resolves external visitor ID, writes cookie, calls /identify on first write
└── includes/
    ├── Logger.php                         ← leveled logging gated by ABTF_LOG_LEVEL
    ├── VisitorIdProvider.php              ← manages provider config (fingerprint / heap / custom)
    ├── Fingerprint.php                    ← generates visitor ID from cookie (raw) or SHA256 fingerprint
    ├── RedisClient.php                    ← singleton Redis connection for variant assignments (DB 0)
    ├── Database.php                       ← DB fallback and table definitions
    ├── HitCacheRedis.php                  ← caches failed Flagship hits for retry (DB 1)
    ├── RateLimiter.php                    ← rate limiting via Redis DB 2 (Lua atomic, fail open)
    ├── ConversionTracker.php              ← real-time conversion counters (Redis DB 3)
    ├── FlagshipActivator.php              ← sends activate hits to Flagship (decision.flagship.io/v2/activate)
    ├── ExperimentRunner.php               ← orchestrates the full experiment flow
    ├── EventEndpoint.php                  ← REST API endpoint: records conversion + forwards hit
    ├── IdentifyEndpoint.php               ← REST API endpoint for fingerprint → external ID reconciliation
    ├── Encryption.php                     ← AES-256-CBC encryption using AUTH_KEY + AUTH_SALT
    ├── CredentialsManager.php             ← reads and caches Flagship credentials from wp_options
    ├── Settings.php                       ← admin settings page (credentials + visitor ID provider)
    ├── AutoInjector.php                   ← injects abTestData and abTestConfig into wp_footer (priority 99)
    ├── StatsRebuildJob.php                ← aggregates assignments into stats table + snapshots conversions
    ├── CronManager.php                    ← WP-Cron schedule for stats rebuild (every 8h)
    ├── Dashboard/
    │   ├── MetaBox.php                    ← admin dashboard widget with pre-calculated experiment stats
    │   ├── ExperimentsPage.php            ← experiments CRUD admin page
    │   └── ReportingPage.php              ← live conversions dashboard (Redis, SQL snapshot fallback)
    └── adapters/
        ├── DecisionAdapterInterface.php   ← contract for any decision engine
        ├── SimulatorAdapter.php           ← local deterministic 50/50 bucketing (no credentials needed)
        └── FlagshipAdapter.php            ← AB Tasty Flagship SDK integration
```

---

## Admin pages

**AB Tests → Experiments** — create, edit, pause, and delete experiments. Each experiment defines a flag key, CSS selector, event name, event type, and URL rules with wildcard support (e.g. `/talent/*`). **New experiments start paused** so you can verify the configuration before they serve any variant; review it, then click Resume to activate.

**AB Tests → Reporting** — live conversions dashboard showing unique visitors, unique conversions, total conversions, conversion rate, and growth vs. the `control` baseline, per experiment and event. Reads live from Redis, falling back to the SQL snapshot when Redis is unavailable. Includes a Rebuild Stats button to force a stats refresh without waiting for the cron.

**AB Tests → Settings** — configure Flagship credentials (encrypted with AES-256-CBC before being stored in `wp_options`) and the Visitor ID Provider.

**WordPress Dashboard widget** — shows visitor counts and traffic split per variant for each experiment. Data is pre-calculated by WP-Cron every 8 hours and never runs live `COUNT(*)` queries.
