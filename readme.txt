=== Nofliq Server-Side A/B Testing ===
Contributors: victoriavegab
Tags: a/b testing, split testing, experiments, server-side, flagship
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

No-flicker server-side A/B testing. Decides experiment variants in PHP before rendering, and integrates with AB Tasty Flagship.

== Description ==

Server-side A/B testing for WordPress that eliminates flicker.

Most A/B testing tools decide which variant to show in the browser, using JavaScript that runs after the page loads. This causes the "flash of original content" (FOOC): the visitor briefly sees the original version before the variant replaces it. It looks broken and it skews results.

This plugin takes a different approach. It decides the variant in PHP, on the server, **before** the HTML is sent to the browser. The visitor only ever sees the assigned variant, with no flash and no layout shift.

It integrates with AB Tasty Flagship as the decision engine: Flagship holds your campaigns and variations, and this plugin queries the decision server-side, caches the assignment, and applies it before render.

= Key features =

* Server-side variant decision in PHP, before the page is rendered.
* No flicker and no flash of original content.
* Integrates with AB Tasty Flagship for campaign and variation management.
* Optional Redis caching for fast repeat visits.
* Internal conversion tracking and reporting inside the WordPress admin.
* Experiments start paused so you can verify configuration before going live.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **AB Tests - Settings** and enter your AB Tasty Flagship Environment ID and API Key.
4. Create an experiment under **AB Tests - Experiments**. New experiments start paused.
5. Review the configuration, then click Resume to activate the experiment.

== Frequently Asked Questions ==

= Do I need an AB Tasty Flagship account? =

Yes. This plugin uses AB Tasty Flagship as its decision engine, so you need a Flagship account with your campaigns and variations configured. You provide the Environment ID and API Key in the plugin settings.

= Does it require Redis? =

Redis is not strictly required, but it is strongly recommended. The plugin uses Redis to cache variant assignments and speed up repeat visits. Without Redis, the plugin still works but performs more decision requests.

= Why do new experiments start paused? =

Starting paused lets you verify the configuration (selector, URLs, flag key) before the experiment serves any variant. Only active experiments are applied, so a paused start prevents assignments from being cached before you are ready.

= How does it avoid flicker? =

The variant is decided in PHP before the HTML is generated, so the browser receives the correct variant from the first byte. There is no JavaScript swap after load, which is what causes flicker in client-side tools.

== Changelog ==

= 1.8.0 =
* Initial public release.
