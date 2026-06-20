# WP MCP Control — MCP Smoke Test

## Prerequisites

1. WordPress plugin installed and activated
2. API token generated in **Settings → WP MCP Control**
3. MCP server built: `cd mcp-server && npm install && npm run build`
4. `.env` file in `mcp-server/` with `WP_MCP_SITE_URL` and `WP_MCP_TOKEN`

## Cursor Setup

Add to `~/.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "wp-mcp-control": {
      "command": "node",
      "args": ["/absolute/path/to/WP MCP Control/mcp-server/dist/index.js"],
      "env": {
        "WP_MCP_SITE_URL": "https://your-staging-site.com",
        "WP_MCP_TOKEN": "your-token-here",
        "WP_MCP_SAFE_MODE": "true",
        "WP_MCP_DRY_RUN": "false"
      }
    }
  }
}
```

Restart Cursor after saving.

## Test Prompts

Run these in Cursor chat after the MCP server is connected:

### 1. Health Check

> Run `wp_health_check` and show me the results.

Expected: JSON with `status: ok`, plugin version, safe_mode, dry_run.

### 2. List Pages

> Run `wp_list_pages` with status "any" and list all pages.

Expected: Array of pages with id, title, slug, status, url.

### 3. Create Draft Page

> Use `wp_create_page` to create a draft page titled "MCP Smoke Test" with content "Hello from MCP".

Expected: New page with status draft and an ID.

### 4. Activity Log

> Run `wp_get_activity_log` and show the last 5 entries.

Expected: Log entries for page.create and other actions.

### 5. Dry Run Test

Set `WP_MCP_DRY_RUN=true` in MCP config, restart Cursor, then:

> Use `wp_create_page` to create a page titled "Should Not Exist".

Verify the page does NOT appear in WordPress admin. Reset `WP_MCP_DRY_RUN=false` after.

## Troubleshooting

| Issue | Fix |
|-------|-----|
| MCP server not listed | Check absolute path in mcp.json, run `npm run build` |
| 401 errors | Regenerate token, update env |
| Connection refused | Verify site URL and SSL |
| Tool not found | Restart Cursor after config change |
