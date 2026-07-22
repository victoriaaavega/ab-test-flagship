=== Nofliq Server-Side A/B Testing ===
Contributors: victoriavegab
Tags: a/b testing, split testing, experiments, server-side, flagship
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

No-flicker server-side A/B testing. Decides experiment variants in PHP before rendering. Works on its own or with AB Tasty Flagship.

== Description ==

Server-side A/B testing for WordPress that eliminates flicker.

Most A/B testing tools decide which variant to show in the browser, using JavaScript that runs after the page loads. This causes the "flash of original content" (FOOC): the visitor briefly sees the original version before the variant replaces it. It looks broken and it skews results.

This plugin takes a different approach. It decides the variant in PHP, on the server, **before** the HTML is sent to the browser, and exposes the decision to your theme so the correct variant is rendered from the first byte. The visitor only ever sees the assigned variant, with no flash and no layout shift.

= Two decision engines =

The plugin has two modes, chosen in the settings:

* **Local** (no account needed): the plugin decides variants itself, on your server, with deterministic bucketing. No data leaves your site. Ideal for evaluation or for running simple experiments without any third-party service.
* **Flagship**: the plugin uses AB Tasty Flagship as the decision engine for remote targeting, segmentation and the Flagship dashboard. This mode sends data to AB Tasty's servers (see External services below) and requires a Flagship account.

Flagship is the default because it adds targeting, segmentation and centralized reporting that a local engine cannot provide. Local mode is fully functional on its own.

= How rendering works =

This plugin decides and exposes the variant; your theme renders it. The decision is made available to the page as `window.abTestData`, and your theme (or a small snippet) reads it to render the assigned variant server-side via the `abtf_runner()` helper. This is the standard server-side pattern used by tools like LaunchDarkly, Optimizely and Split: the plugin decides, your code applies. A minimal integration example is provided in the FAQ.

= Key features =

* Server-side variant decision in PHP, before the page is rendered.
* No flicker and no flash of original content.
* Works standalone (Local mode) or with AB Tasty Flagship.
* Optional Redis caching for fast repeat visits at scale.
* Internal conversion tracking and reporting inside the WordPress admin.
* Experiments start paused so you can verify configuration before going live.

== External services ==

This plugin can connect to AB Tasty Flagship, a third-party experimentation service, **only when the Decision Engine is set to "Flagship" in the plugin settings**. In "Local" mode the plugin makes no external requests and no data leaves your site.

When Flagship mode is active, the plugin contacts the following AB Tasty endpoints:

**1. Flagship Decision API - https://decision.flagship.io**

* What it is: AB Tasty's decision server, which returns the variant a visitor should see for a given campaign.
* What is sent and when: when a visitor loads a page with an active experiment, the plugin sends the visitor ID and the campaign (flag) key to obtain the assigned variation. It also posts an "activate" hit to `https://decision.flagship.io/v2/activate` (visitor ID and the variation identifiers) the first time a visitor is assigned, so the visitor is counted in Flagship's reporting.

**2. Flagship Collect API - https://events.flagship.io**

* What it is: AB Tasty's event collection endpoint.
* What is sent and when: when a visitor triggers a tracked goal (e.g. a click on a configured element), the plugin sends the visitor ID, the event name, the variant, and the page URL so the conversion is recorded in Flagship.

The visitor ID is either a fingerprint hash (SHA-256 of IP + User-Agent + Accept-Language) or an ID provided by your analytics tool, depending on the configured Visitor ID Provider.

Use of AB Tasty Flagship is subject to AB Tasty's terms and privacy policy:

* Terms of Use: https://www.abtasty.com/terms-of-use/
* Privacy Policy: https://www.abtasty.com/privacy-policy/

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **AB Tests - Settings** and choose a Decision Engine. For Local mode, no further configuration is required. For Flagship mode, enter your AB Tasty Flagship Environment ID and API Key.
4. Create an experiment under **AB Tests - Experiments**. New experiments start paused.
5. Review the configuration, then click Resume to activate the experiment.

== Frequently Asked Questions ==

= Do I need an AB Tasty Flagship account? =

No. In Local mode the plugin decides variants on your own server and needs no third-party account. You only need a Flagship account if you choose Flagship mode, which adds remote targeting, segmentation and the Flagship dashboard.

= Does it require Redis? =

Redis is not strictly required, but it is recommended at scale. The plugin uses Redis to cache variant assignments and to store conversion counters for fast reporting. Without Redis the plugin still works: variant assignments fall back to the database, and in Local mode conversions are recorded in the database as well.

= Why do new experiments start paused? =

Starting paused lets you verify the configuration (selector, URLs, flag key, variants) before the experiment serves any variant. Only active experiments are applied, so a paused start prevents assignments from being cached before you are ready.

= How does it avoid flicker? =

The variant is decided in PHP before the HTML is generated, so your theme can render the correct variant from the first byte. There is no JavaScript swap after load, which is what causes flicker in client-side tools.

= Does the plugin change my page content automatically? =

No. The plugin decides and exposes the variant; your theme renders it. This keeps the plugin theme-agnostic and avoids the flicker that DOM-manipulation approaches cause. See the next question for a minimal integration example.

= How do I render the variant in my theme? =

The plugin exposes the decided variant through the `abtf_runner()` helper. Call it in your theme where you want to render the variant, before output. For example:

`<?php
$runner  = abtf_runner();
$result  = $runner->run( 'your_flag_key' );
$variant = $result['variant'];

if ( $variant === 'control' ) {
    // render the control version
} else {
    // render the variant version
}
?>`

The decision is made in PHP before the HTML is sent, so there is no flicker. The same variant is also available in JavaScript as `window.abTestData` for client-side needs.

== Changelog ==

= 1.9.0 =
* Added a Local decision engine so the plugin works fully without any third-party account.
* Added an explicit Decision Engine selector (Flagship or Local) in settings.
* Documented the AB Tasty Flagship external services used in Flagship mode.
* Internal improvements and code-quality fixes.

= 1.8.0 =
* Initial release.