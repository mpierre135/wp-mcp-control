=== WP MCP Control ===
Contributors: wpmcpcontrol
Tags: mcp, rest-api, cursor, ai, content-management
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure MCP integration for managing WordPress from Cursor, Claude Desktop, and other MCP-compatible IDEs.

== Description ==

WP MCP Control exposes a secure REST API and companion MCP server that lets AI-powered IDEs manage your WordPress site safely.

**Features:**

* Create, edit, delete, draft, and publish pages and posts
* Upload and manage media
* Manage categories, tags, and navigation menus
* Search site content
* Activity logging and snapshot restore
* Built-in URL redirects
* Safe mode and dry-run toggles
* Rate limiting and IP allowlist
* Token-based authentication

== Installation ==

1. Upload the `wp-mcp-control` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to Settings → WP MCP Control
4. Generate an API token
5. Install the MCP server: https://github.com/mpierre135/wp-mcp-control-server

== Frequently Asked Questions ==

= Is this safe to use on production? =

Safe mode is enabled by default. It blocks permanent deletions, plugin/theme changes, and other destructive operations. Always test on staging first.

= What IDEs are supported? =

Any MCP-compatible client: Cursor, Claude Desktop, Agent Max, and others.

== Changelog ==

= 2.2.0 =
* Fix Elementor page duplicate: safe JSON pipeline preserves heading text ($400 vs $400n corruption)
* POST /elementor/pages/create-blank — empty canvas with header/footer template
* POST /elementor/pages/{id}/clear — remove all canvas sections for rebuilds
* POST /elementor/pages/{id}/repair-json — re-save corrupted Elementor JSON
* Expanded widget catalog: counter, accordion, price-table, gallery, star-rating, image-box, Raven/JupiterX equivalents

= 2.1.0 =
* Custom MCP outbound webhooks with full event catalog and HMAC signing
* WooCommerce native webhook CRUD (product/order topics)
* Ninja Forms webhook action management
* Webhook delivery log, test ping, SSRF protection
* 17 new MCP webhook tools

= 2.0.0 =
* Full platform expansion: blueprint, site audit, cache purge
* Gutenberg block editing, CPT/taxonomy CRUD, revisions and snapshot diff
* Plugin adapters: ACF, AIOSEO, WooCommerce (full order write), Ninja Forms, LiteSpeed cache
* Users, comments, widgets, plugin allowlist, cron events
* Elementor: find parent, regenerate CSS, Jet/Raven catalog entries
* 65+ new MCP tools (v2.0.0 server)

= 1.2.0 =
* Elementor Phase 2–3: widget catalog, button/image updates, insert/remove/clone, duplicate page
* JupiterX/Raven widget catalog entries
* Batch API support for /elementor routes

= 1.1.0 =
* Elementor Phase 1: read structure, update heading/text-editor

= 1.0.0 =
* Initial release
