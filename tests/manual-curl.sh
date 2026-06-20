#!/usr/bin/env bash
# WP MCP Control — Manual API test suite
# Usage: SITE_URL=https://yoursite.com TOKEN=your-token ./manual-curl.sh

set -euo pipefail

SITE_URL="${SITE_URL:-}"
TOKEN="${TOKEN:-}"

if [[ -z "$SITE_URL" || -z "$TOKEN" ]]; then
  echo "Usage: SITE_URL=https://example.com TOKEN=your-token $0"
  exit 1
fi

BASE="${SITE_URL%/}/wp-json/wp-mcp/v1"
AUTH="Authorization: Bearer ${TOKEN}"
JSON="Content-Type: application/json"

pass=0
fail=0

assert_status() {
  local name="$1"
  local expected="$2"
  local actual="$3"
  if [[ "$actual" == "$expected" ]]; then
    echo "PASS: $name (HTTP $actual)"
    ((pass++))
  else
    echo "FAIL: $name (expected HTTP $expected, got $actual)"
    ((fail++))
  fi
}

echo "=== WP MCP Control API Tests ==="
echo "Base: $BASE"
echo ""

# 1. Health check
status=$(curl -s -o /tmp/wp-mcp-health.json -w "%{http_code}" -H "$AUTH" "$BASE/health")
assert_status "Health check" "200" "$status"
cat /tmp/wp-mcp-health.json | head -c 200
echo ""
echo ""

# 2. Invalid token
status=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer invalid-token" "$BASE/health")
assert_status "Invalid token" "401" "$status"

# 3. Site info
status=$(curl -s -o /tmp/wp-mcp-site.json -w "%{http_code}" -H "$AUTH" "$BASE/site-info")
assert_status "Site info" "200" "$status"

# 4. Create draft page
status=$(curl -s -o /tmp/wp-mcp-page.json -w "%{http_code}" -X POST -H "$AUTH" -H "$JSON" \
  -d '{"title":"MCP Test Page","status":"draft","content":"<p>Test content</p>"}' \
  "$BASE/pages")
assert_status "Create draft page" "201" "$status"
PAGE_ID=$(python3 -c "import json; print(json.load(open('/tmp/wp-mcp-page.json')).get('id',''))" 2>/dev/null || echo "")
echo "Created page ID: $PAGE_ID"
echo ""

if [[ -n "$PAGE_ID" && "$PAGE_ID" != "" ]]; then
  # 5. Update page
  status=$(curl -s -o /tmp/wp-mcp-update.json -w "%{http_code}" -X PUT -H "$AUTH" -H "$JSON" \
    -d '{"title":"MCP Test Page Updated","content":"<p>Updated content</p>"}' \
    "$BASE/pages/$PAGE_ID")
  assert_status "Update page" "200" "$status"

  # 6. Delete to trash
  status=$(curl -s -o /tmp/wp-mcp-delete.json -w "%{http_code}" -X DELETE -H "$AUTH" -H "$JSON" \
    -d '{"confirm":true}' \
    "$BASE/pages/$PAGE_ID")
  assert_status "Delete to trash" "200" "$status"
fi

# 7. Dry-run create
status=$(curl -s -o /tmp/wp-mcp-dryrun.json -w "%{http_code}" -X POST -H "$AUTH" -H "$JSON" \
  -H "X-WP-MCP-Dry-Run: true" \
  -d '{"title":"Dry Run Page","status":"draft"}' \
  "$BASE/pages")
assert_status "Dry-run create" "200" "$status"

# 8. Search
status=$(curl -s -o /tmp/wp-mcp-search.json -w "%{http_code}" -X POST -H "$AUTH" -H "$JSON" \
  -d '{"query":"test","post_type":["page","post"],"limit":5}' \
  "$BASE/search")
assert_status "Search content" "200" "$status"

# 9. Activity log
status=$(curl -s -o /tmp/wp-mcp-logs.json -w "%{http_code}" -H "$AUTH" "$BASE/activity-log?per_page=5")
assert_status "Activity log" "200" "$status"

# 10. Create redirect (dry-run)
status=$(curl -s -o /tmp/wp-mcp-redirect.json -w "%{http_code}" -X POST -H "$AUTH" -H "$JSON" \
  -H "X-WP-MCP-Dry-Run: true" \
  -d '{"source_path":"/mcp-test-redirect","target_url":"'"$SITE_URL"'/","status_code":301}' \
  "$BASE/redirects")
assert_status "Redirect dry-run" "200" "$status"

# 11. Export structure
status=$(curl -s -o /tmp/wp-mcp-export.json -w "%{http_code}" -H "$AUTH" "$BASE/export/structure")
assert_status "Export structure" "200" "$status"

# 12. Safe mode force delete blocked
if [[ -n "$PAGE_ID" && "$PAGE_ID" != "" ]]; then
  status=$(curl -s -o /tmp/wp-mcp-force.json -w "%{http_code}" -X DELETE -H "$AUTH" -H "$JSON" \
    -H "X-WP-MCP-Safe-Mode: true" \
    -d '{"force":true,"confirm":true}' \
    "$BASE/pages/$PAGE_ID")
  if [[ "$status" == "403" || "$status" == "400" ]]; then
    echo "PASS: Safe mode force delete blocked (HTTP $status)"
    ((pass++))
  else
    echo "FAIL: Safe mode force delete blocked (expected 403/400, got $status)"
    ((fail++))
  fi
fi

echo ""
echo "=== Results: $pass passed, $fail failed ==="
[[ "$fail" -eq 0 ]]
