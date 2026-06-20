#!/usr/bin/env bash
# WP MCP Control v2.0+ smoke tests (dry-run where possible)
# Usage: TOKEN=xxx ./v2-smoke-test.sh

set -uo pipefail

SITE_URL="${SITE_URL:-https://chrisheadshots.com}"
TOKEN="${TOKEN:-}"
PAGE_ID="${PAGE_ID:-43}"

if [[ -z "$TOKEN" ]]; then
  echo "Usage: TOKEN=your-token $0"
  exit 1
fi

BASE="${SITE_URL%/}/wp-json/wp-mcp/v1"
AUTH="Authorization: Bearer ${TOKEN}"
DRY="X-WP-MCP-Dry-Run: true"
JSON="Content-Type: application/json"

pass=0
fail=0

check() {
  local name="$1"
  local code="$2"
  if [[ "$code" == "200" || "$code" == "201" ]]; then
    echo "PASS: $name (HTTP $code)"
    pass=$((pass + 1))
  else
    echo "FAIL: $name (HTTP $code)"
    fail=$((fail + 1))
  fi
}

echo "=== WP MCP Control Smoke Tests ==="

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/health")
check "health" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/blueprint")
check "blueprint" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/webhooks/topics")
check "webhook topics" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/audit")
check "site audit" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/audit/security")
check "security posture" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/meta/catalog")
check "meta catalog" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/post-types")
check "post types" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/taxonomies")
check "taxonomies" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/blocks/pages/$PAGE_ID")
check "blocks structure" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/seo/pages/$PAGE_ID")
check "page seo" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/elementor/pages/$PAGE_ID/parent")
check "elementor parent" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" -H "$DRY" -X POST \
  -H "$JSON" -d '{"confirm":true}' "$BASE/cache/purge")
check "cache purge dry-run" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/cron/events")
check "cron events" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/plugins/conflicts")
check "plugin conflicts" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/users")
check "users list" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/comments")
check "comments list" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/widgets/sidebars")
check "widget sidebars" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/revisions/posts/$PAGE_ID")
check "revisions list" "$code"

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/woocommerce/products?per_page=1" 2>/dev/null || echo "503")
if [[ "$code" == "200" || "$code" == "503" ]]; then
  echo "PASS: woocommerce products (HTTP $code)"
  pass=$((pass + 1))
else
  echo "FAIL: woocommerce products (HTTP $code)"
  fail=$((fail + 1))
fi

code=$(curl -s -o /dev/null -w "%{http_code}" -H "$AUTH" "$BASE/woocommerce/webhooks" 2>/dev/null || echo "503")
if [[ "$code" == "200" || "$code" == "503" ]]; then
  echo "PASS: woocommerce webhooks (HTTP $code)"
  pass=$((pass + 1))
else
  echo "FAIL: woocommerce webhooks (HTTP $code)"
  fail=$((fail + 1))
fi

echo ""
echo "Results: $pass passed, $fail failed"
exit $([[ "$fail" -eq 0 ]] && echo 0 || echo 1)
