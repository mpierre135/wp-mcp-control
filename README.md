# WP MCP Control — WordPress Plugin

Secure REST API for managing WordPress from **Cursor**, **Claude Desktop**, and other MCP-compatible IDEs.

Pairs with the companion MCP server: [wp-mcp-control-server](https://github.com/mpierre135/wp-mcp-control-server)

**Current version:** 2.0.0

## Install

### From GitHub

```bash
cd /path/to/wordpress/wp-content/plugins
git clone https://github.com/mpierre135/wp-mcp-control.git
```

Then activate **WP MCP Control** in WordPress Admin → Plugins.

### Manual upload

1. Download or zip this folder
2. Upload to `wp-content/plugins/wp-mcp-control/`
3. Activate in **Plugins**

## Configure

1. Go to **Settings → WP MCP Control**
2. Click **Generate Token** and copy it immediately (shown once)
3. Recommended: keep **Safe Mode** ON

## MCP Server Setup

Install the [MCP server](https://github.com/mpierre135/wp-mcp-control-server) on your computer and point it at this site:

```json
{
  "mcpServers": {
    "wp-mcp-control": {
      "command": "node",
      "args": ["/path/to/wp-mcp-control-server/dist/index.js"],
      "env": {
        "WP_MCP_SITE_URL": "https://your-site.com",
        "WP_MCP_TOKEN": "your-token",
        "WP_MCP_SAFE_MODE": "true",
        "WP_MCP_DRY_RUN": "false"
      }
    }
  }
}
```

## REST API

Base URL: `https://your-site.com/wp-json/wp-mcp/v1/`

Auth header: `Authorization: Bearer <token>`

| Endpoint | Description |
|----------|-------------|
| `GET /health` | Health check |
| `GET /site-info` | Site metadata |
| `GET/POST /pages` | Page CRUD |
| `GET/POST /posts` | Post CRUD |
| `POST /media/upload-url` | Upload media |
| `POST /search` | Unified search |
| `POST /batch` | Batch operations |
| `GET /activity-log` | Audit log |
| `POST /restore` | Restore snapshot |
| `GET/POST /redirects` | Redirect management |
| `GET /elementor/widgets` | Elementor widget catalog |
| `GET /elementor/pages/{id}` | Elementor structure |
| `PUT /elementor/pages/{id}/text` | Find & replace text |
| `PUT /elementor/pages/{id}/button` | Update buttons |
| `POST /elementor/pages/duplicate` | Duplicate Elementor page |
| `GET /blueprint` | Site blueprint for agents |
| `GET /audit` | Site content audit |
| `POST /cache/purge` | Purge LiteSpeed/other cache |
| `GET/PUT /seo/pages/{id}` | AIOSEO meta |
| `GET/PUT /acf/posts/{id}` | ACF fields |
| `GET/POST /woocommerce/*` | Products, orders, bookings |
| `GET/PUT /blocks/pages/{id}` | Gutenberg blocks |
| `GET/POST /post-types/{type}` | Custom post types |
| `GET /users`, `GET /comments` | Users and comments |
| `GET /revisions/posts/{id}` | Post revisions |

## v2.0 Adapters

Auto-detected when plugins are active:

- **ACF** — field catalog + read/write
- **All in One SEO** — page SEO + audit
- **WooCommerce** — products, full order write, refunds, bookings read
- **Ninja Forms** — forms, notifications, submissions (PII masked)
- **LiteSpeed Cache** — purge support

- `wp_elementor_find_parent`, `wp_elementor_regenerate_css`

See full API docs in the [MCP server README](https://github.com/mpierre135/wp-mcp-control-server#tool-catalog) (MCP tools map 1:1 to endpoints).

## Elementor Support (v1.2.0+)

- Widget catalog with sanitization for core + JupiterX/Raven widgets
- Semantic updates: text, buttons, images
- Layout ops: insert widget/section, remove, clone, duplicate page
- Container and section/column layout modes
- Snapshots before all writes; `confirm: true` required in safe mode for structural changes

Requires Elementor active on the site.

## Security

- Bcrypt-hashed API tokens (plaintext shown once on generation)
- Safe mode blocks destructive operations by default
- Dry-run header support (`X-WP-MCP-Dry-Run: true`)
- Rate limiting, optional IP allowlist
- Snapshots before updates/deletes
- Activity logging for all mutating operations

## Requirements

- WordPress 6.0+
- PHP 7.4+

## License

GPL v2 or later
