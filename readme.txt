=== Ocasio 301 Redirect Manager ===
Contributors: ocas
Tags: 301 redirects, redirect, 301 redirect, broken links, 404 redirect
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, high-performance tool to manage 301 permanent redirects and fix broken links.

== Description ==

Broken links and dead 404 pages ruin visitor trust and damage your search rankings.

Ocasio 301 Redirect Manager gives you a clean, simple way to map old URLs to new destinations. Whenever a visitor or search bot requests an old page, it sends them straight to the new address with a fast, permanent 301 header.

It features instant type-ahead search so you can find published posts and pages without manually copying long URLs.

It hooks into WordPress early on page load, running on pure PHP with zero database bloat and zero impact on your front-end speed.

== Installation ==

1. Upload the `ocasio-301-redirect-manager` folder to your `/wp-content/plugins/` directory, or install it directly through your WordPress admin screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Ocasio Plugins -> 301 Redirects** in your admin sidebar to add and manage your redirects.

== Frequently Asked Questions ==

= Does this handle trailing slash differences? =
Yes. It automatically checks both `/example` and `/example/` variations so you don't have to create duplicate rules.

= What type of redirect does this plugin use? =
It issues standard HTTP 301 Permanent Redirects, which pass search engine equity and update browser bookmarks automatically.

= Can I redirect to external websites? =
Yes. You can redirect any relative path on your domain to external URLs, affiliate links, or other websites.

= Does this slow down my website? =
No. It only checks incoming URLs in lightweight PHP memory and doesn't run complex queries on normal page loads.

== Changelog ==

= 1.0.0 =
* Initial public release.
* High-speed 301 redirect engine with trailing slash matching.
* AJAX live search autocomplete for posts and pages.
* Inline redirect editing and deletion management.
* Clean integration under Ocasio Plugins sidebar menu.
