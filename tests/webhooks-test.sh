#!/usr/bin/env bash
# WP MCP Control v2.1 webhook tests
# Usage: TOKEN=xxx ./webhooks-test.sh

set -euo pipefail

SITE_URL="${SITE_URL:-https://chrisheadshots.com}"
TOKEN="${TOKEN:-}"

if [[ -z "$TOKEN" ]]; then
  echo "Usage: TOKEN=your-token $0"
  exit 1
fi

BASE="${SITE_URL%/}/wp-json/wp-mcp/v1"
AUTH="Authorization: Bearer ${TOKEN}"
DRY="X-WP-MCP-Dry-Run: true"
JSON="Content-Type: application/json"

echo "=== WP MCP Control v2.1 Webhook Tests ==="

echo ""
echo "1. Topic catalog"
curl -s -H "$AUTH" "$BASE/webhooks/topics" | python3 -m json.tool | head -25

echo ""
echo "2. List custom webhooks"
curl -s -H "$AUTH" "$BASE/webhooks" | python3 -m json.tool

echo ""
echo "3. Dry-run create custom webhook"
curl -s -X POST -H "$AUTH" -H "$DRY" -H "$JSON" \
  -d '{"name":"Test Webhook","url":"https://example.com/hook","topics":["page.updated"],"confirm":true}' \
  "$BASE/webhooks" | python3 -m json.tool

echo ""
echo "4. WooCommerce webhooks list"
curl -s -H "$AUTH" "$BASE/woocommerce/webhooks" | python3 -m json.tool | head -20

echo ""
echo "5. Dry-run create WC webhook"
curl -s -X POST -H "$AUTH" -H "$DRY" -H "$JSON" \
  -d '{"name":"Test Order Hook","delivery_url":"https://example.com/wc","topic":"order.created","confirm":true}' \
  "$BASE/woocommerce/webhooks" | python3 -m json.tool

echo ""
echo "Done."
