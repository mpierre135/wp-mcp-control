#!/usr/bin/env bash
# Elementor Phase 1–3 tests — run after uploading plugin v1.2.0+
# Usage: SITE_URL=https://yoursite.com TOKEN=xxx PAGE_ID=43 SOURCE_ID=591 ./elementor-test.sh

set -euo pipefail

SITE_URL="${SITE_URL:-https://chrisheadshots.com}"
TOKEN="${TOKEN:-}"
PAGE_ID="${PAGE_ID:-43}"
SOURCE_ID="${SOURCE_ID:-591}"

if [[ -z "$TOKEN" ]]; then
  echo "Usage: TOKEN=your-token PAGE_ID=43 SOURCE_ID=591 $0"
  exit 1
fi

BASE="${SITE_URL%/}/wp-json/wp-mcp/v1"
AUTH="Authorization: Bearer ${TOKEN}"
DRY="X-WP-MCP-Dry-Run: true"

echo "=== Elementor Phase 1–3 Tests (page $PAGE_ID) ==="

echo ""
echo "0. Widget catalog"
curl -s -H "$AUTH" "$BASE/elementor/widgets" | python3 -m json.tool | head -30

echo ""
echo "1. List elements"
curl -s -H "$AUTH" "$BASE/elementor/pages/$PAGE_ID/elements" | python3 -m json.tool | head -40

echo ""
echo "2. Find buttons"
curl -s -H "$AUTH" "$BASE/elementor/pages/$PAGE_ID/widgets?widget_type=button" | python3 -m json.tool | head -20

echo ""
echo "3. Dry-run text update"
curl -s -X PUT -H "$AUTH" -H "Content-Type: application/json" \
  -H "$DRY" \
  -d '{"widget_type":"heading","match_text":"CHRIS HEADSHOTS","new_text":"CHRIS HEADSHOTS"}' \
  "$BASE/elementor/pages/$PAGE_ID/text" | python3 -m json.tool

echo ""
echo "4. Dry-run button update"
curl -s -X PUT -H "$AUTH" -H "Content-Type: application/json" \
  -H "$DRY" \
  -d '{"match_text":"Book","new_text":"Book Now"}' \
  "$BASE/elementor/pages/$PAGE_ID/button" | python3 -m json.tool

echo ""
echo "5. Dry-run insert section (CTA preset via API)"
curl -s -X POST -H "$AUTH" -H "Content-Type: application/json" \
  -H "$DRY" \
  -d '{"position":"end","confirm":true,"children":[{"widget_type":"heading","settings":{"title":"Test CTA"}},{"widget_type":"button","settings":{"text":"Click"}}]}' \
  "$BASE/elementor/pages/$PAGE_ID/sections" | python3 -m json.tool

echo ""
echo "6. Dry-run duplicate page"
curl -s -X POST -H "$AUTH" -H "Content-Type: application/json" \
  -H "$DRY" \
  -d "{\"source_id\":$SOURCE_ID,\"title\":\"Test Duplicate\",\"confirm\":true}" \
  "$BASE/elementor/pages/duplicate" | python3 -m json.tool

echo ""
echo "7. Find insert parent"
curl -s -H "$AUTH" "$BASE/elementor/pages/$PAGE_ID/parent" | python3 -m json.tool

echo ""
echo "8. Dry-run regenerate CSS"
curl -s -X POST -H "$AUTH" -H "Content-Type: application/json" \
  -H "$DRY" \
  -d '{"confirm":true}' \
  "$BASE/elementor/pages/$PAGE_ID/regenerate" | python3 -m json.tool

echo ""
echo "Done. To apply real changes, remove X-WP-MCP-Dry-Run header and set confirm:true when safe mode is on."
