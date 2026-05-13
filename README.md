# AB Test Flagship

WordPress plugin for server-side A/B testing using the AB Tasty Flagship SDK. Eliminates flicker by deciding variants in PHP before rendering HTML, with Redis caching, database fallback, and event tracking.

---

## How it works

```
User visits page
      ↓
PHP generates fingerprint (SHA256 of IP + User-Agent + Accept-Language)
      ↓
Redis has variant? → Yes → serve it (< 1ms)
                  → No  → Flagship decides → save to Redis + DB
      ↓
PHP renders HTML with correct variant (no flicker)
      ↓
JS registers click listeners
Heap syncs Visitor ID via cookie
      ↓
User clicks
      ↓
JS sends POST to REST API endpoint
      ↓
PHP validates nonce + rate limit → forwards hit to Flagship
```

---

## Requirements

- PHP 8.1+
- WordPress 6.0+
- Redis (phpredis extension)
- Composer
- AB Tasty Flagship account

---

## Installation

**1. Clone or download the plugin into your WordPress plugins directory:**

```
wp-content/plugins/ab-test-flagship/
```

**2. Install PHP dependencies:**

```bash
cd wp-content/plugins/ab-test-flagship
composer install
```

**3. Configure your Flagship credentials** using one of these methods (in priority order):

**Option A — PHP constants in `wp-config.php` (recommended for production):**
```php
define('FLAGSHIP_ENV_ID', 'your_env_id');
define('FLAGSHIP_API_KEY', 'your_api_key');
```

**Option B — Admin Settings page:**
Go to **AB Tests → Settings** and enter your credentials there. They will be encrypted using AES-256-CBC before being stored in the database.

**4. Activate the plugin** from the WordPress admin under **Plugins**.

**5. Verify** — go to **AB Tests → Settings** to confirm credentials are configured. If missing, a red notice will appear in the WordPress admin dashboard with a link to the Settings page.

---

## Credentials priority

The plugin reads credentials in this order:

1. **PHP constants** (`FLAGSHIP_ENV_ID`, `FLAGSHIP_API_KEY`) defined in `wp-config.php`
2. **Encrypted values** saved via the Settings page in `wp_options`
3. **No credentials** → plugin falls back to `SimulatorAdapter` automatically

---

## Usage in a theme

```php
<?php
$runner    = abtf_runner();
$result    = $runner->run('your_flag_key'); // flag key as defined in Flagship dashboard
$variant   = $result['variant'];
$visitorId = $result['visitorId'];
?>
```

Then in your HTML:

```html
<head>
    <?php wp_head(); ?>
</head>
<body>

    <?php if ($variant === 'control'): ?>
        <!-- original version -->
    <?php else: ?>
        <!-- variant version -->
    <?php endif; ?>

    <script>
        window.abTestData = {
            visitorId: "<?php echo esc_js($visitorId); ?>",
            experiments: {
                "your_flag_key": "<?php echo esc_js($variant); ?>"
            }
        };

        window.abTestConfig = [
            {
                experimentId: "your_flag_key",
                selector:     ".your-button",
                eventName:    "your_event_name",
                type:         "click"
            }
        ];
    </script>

    <?php wp_footer(); ?>
</body>
```

### Multiple experiments on the same page

```php
$runner   = abtf_runner();

$result1  = $runner->run('experiment_flag_1');
$variant1 = $result1['variant'];

$result2  = $runner->run('experiment_flag_2');
$variant2 = $result2['variant'];

$visitorId = $result1['visitorId']; // same visitor ID for all experiments
```

```javascript
window.abTestData = {
    visitorId: "<?php echo esc_js($visitorId); ?>",
    experiments: {
        "experiment_flag_1": "<?php echo esc_js($variant1); ?>",
        "experiment_flag_2": "<?php echo esc_js($variant2); ?>"
    }
};

window.abTestConfig = [
    {
        experimentId: "experiment_flag_1",
        selector:     ".button-one",
        eventName:    "button_one_click",
        type:         "click"
    },
    {
        experimentId: "experiment_flag_2",
        selector:     ".button-two",
        eventName:    "button_two_click",
        type:         "click"
    }
];
```

---

## Without credentials (local development)

If no credentials are configured via `wp-config.php` or the Settings page, the plugin automatically uses `SimulatorAdapter` — a local bucketing engine that makes deterministic 50/50 decisions using `crc32` without any network calls.

This means you can develop and test experiments locally without a Flagship account.

---

## Redis

The plugin uses three Redis DB indexes to keep data separated:

| DB | Purpose |
|---|---|
| 0 | Variant assignments (`ab_test:variant:{experimentId}:{visitorId}`) |
| 1 | Hit cache for failed Flagship hits (auto-retry on next request) |
| 2 | Rate limiter counters (`abtf:rate:{ip_hash}`) |

**Variant TTL:** 30 days. If Redis is unavailable, the plugin falls back to the WordPress database automatically.

---

## Event tracking endpoint

```
POST /wp-json/abtest/v1/event
```

**Headers:**
```
Content-Type: application/json
X-ABTF-Nonce: {nonce injected automatically by the plugin}
```

**Body:**
```json
{
    "visitor_id":    "64-char SHA256 hex string",
    "experiment_id": "your_flag_key",
    "event_name":    "your_event_name",
    "variant":       "control"
}
```

The nonce is injected automatically via `wp_localize_script` — themes do not need to handle it manually.

**Rate limiting:** 20 requests per IP per minute. Exceeding the limit returns `429 Too Many Requests`. The JS tracker does not retry on 4xx errors.

---

## File structure

```
ab-test-flagship/
├── ab-test-flagship.php                   ← plugin entry point
├── composer.json                          ← PHP dependencies
├── vendor/                                ← Flagship SDK (not in Git)
├── assets/
│   └── js/
│       ├── event-tracker.js               ← captures events and sends hits
│       └── heap-sync.js                   ← syncs Visitor ID with Heap Analytics
└── includes/
    ├── Fingerprint.php                    ← generates Visitor ID
    ├── RedisClient.php                    ← singleton Redis connection for variants
    ├── Database.php                       ← DB fallback when Redis is unavailable
    ├── HitCacheRedis.php                  ← caches failed Flagship hits for retry
    ├── RateLimiter.php                    ← rate limiting via Redis DB 2
    ├── ExperimentRunner.php               ← orchestrates the full experiment flow
    ├── EventEndpoint.php                  ← REST API endpoint for event tracking
    ├── Encryption.php                     ← AES-256-CBC encryption using AUTH_KEY + AUTH_SALT
    ├── CredentialsManager.php             ← reads credentials with priority chain
    ├── Settings.php                       ← admin settings page for credentials
    ├── AutoInjector.php                   ← injects experiment config into wp_footer
    ├── StatsRebuildJob.php                ← aggregates assignments into stats table
    ├── CronManager.php                    ← WP-Cron schedule for stats rebuild (every 8h)
    ├── Dashboard/
    │   ├── MetaBox.php                    ← admin dashboard widget with experiment stats
    │   └── ExperimentsPage.php            ← experiments CRUD admin page
    └── adapters/
        ├── DecisionAdapterInterface.php   ← contract for any decision engine
        ├── SimulatorAdapter.php           ← local 50/50 bucketing
        └── FlagshipAdapter.php            ← AB Tasty Flagship SDK integration
```

---

## Admin pages

**AB Tests → Experiments** — create, edit, pause, and delete experiments. Each experiment defines a flag key, CSS selector, event name, event type, and URL rules with wildcard support (e.g. `/talent/*`). Includes a Rebuild Stats button to force a stats refresh without waiting for the cron.

**AB Tests → Settings** — configure Flagship credentials. Values are encrypted with AES-256-CBC before being stored in `wp_options`. If constants are defined in `wp-config.php`, this page shows them as read-only and the database is not used.

**WordPress Dashboard widget** — shows visitor counts and percentages per variant for each experiment. Data is pre-calculated by WP-Cron every 8 hours and never runs live `COUNT(*)` queries.
