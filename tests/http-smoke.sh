#!/usr/bin/env bash
set -Eeuo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp_dir="$(mktemp -d)"
port="${TEST_PORT:-18765}"
server_pid=""

cleanup() {
    if [[ -n "${server_pid}" ]]; then
        kill "${server_pid}" 2>/dev/null || true
        wait "${server_pid}" 2>/dev/null || true
    fi
    if [[ -n "${tmp_dir}" && "${tmp_dir}" == /tmp/* ]]; then
        rm -rf -- "${tmp_dir}"
    fi
}
trap cleanup EXIT

cookie_value() {
    local jar="$1"
    local name="$2"
    awk -v cookie_name="${name}" '$6 == cookie_name { value = $7 } END { print value }' "${jar}"
}

set +e
APP_ENV=production APP_SECRET=invalid php -r \
    "define('GUESTBOOK_APP', true); require '${repo_dir}/config.php';" \
    >"${tmp_dir}/invalid-config.log" 2>&1
config_exit=$?
set -e
[[ "${config_exit}" == 78 ]]

set +e
APP_ENV=testing \
APP_SECRET=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
ANALYTICS_WEBSITE_ID=not-a-uuid \
php -r "define('GUESTBOOK_APP', true); require '${repo_dir}/config.php';" \
    >"${tmp_dir}/invalid-analytics.log" 2>&1
analytics_config_exit=$?
set -e
[[ "${analytics_config_exit}" == 78 ]]

export APP_ENV=testing
export APP_SECRET=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
ADMIN_PASSWORD_HASH="$(php -r 'echo password_hash("smoke-test-password", PASSWORD_BCRYPT, ["cost" => 4]);')"
export ADMIN_PASSWORD_HASH
export APP_ORIGIN="http://127.0.0.1:${port}"
export APP_STORAGE_DIR="${tmp_dir}/storage"
export ANALYTICS_WEBSITE_ID=c4e3a772-26b3-47e7-afec-b82097684551
mkdir -p "${APP_STORAGE_DIR}"
cli_session_dir="${tmp_dir}/cli-sessions"
mkdir -p "${cli_session_dir}"
web_session_dir="${tmp_dir}/web-sessions"
mkdir -p "${web_session_dir}"

php -d "session.save_path=${cli_session_dir}" \
    "${repo_dir}/scripts/migrate.php" >"${tmp_dir}/migration.log"
if find "${cli_session_dir}" -type f -print -quit | grep --quiet .; then
    echo 'CLI migration unexpectedly created a PHP session.' >&2
    exit 1
fi
php -d "session.save_path=${web_session_dir}" \
    -S "127.0.0.1:${port}" -t "${repo_dir}" "${repo_dir}/tests/router.php" \
    >"${tmp_dir}/server.log" 2>&1 &
server_pid=$!

for _ in {1..40}; do
    if curl --silent --fail --output /dev/null "${APP_ORIGIN}/api/v1/messages"; then
        break
    fi
    sleep 0.1
done

api_headers="${tmp_dir}/api-headers.txt"
api_body="${tmp_dir}/api-body.json"
api_status="$(curl --silent --show-error \
    --dump-header "${api_headers}" \
    --output "${api_body}" \
    --write-out '%{http_code}' \
    --header 'Origin: https://another.example' \
    "${APP_ORIGIN}/api/v1/messages")"
[[ "${api_status}" == 200 ]]
grep --ignore-case --quiet '^Content-Type: application/json; charset=utf-8' "${api_headers}"
grep --ignore-case --quiet '^Cache-Control: public, max-age=30, stale-while-revalidate=60' "${api_headers}"
grep --ignore-case --quiet '^Vary: Accept-Encoding' "${api_headers}"
if grep --ignore-case --quiet '^Set-Cookie:' "${api_headers}"; then
    echo 'Stateless API unexpectedly emitted a cookie.' >&2
    exit 1
fi
if grep --ignore-case --quiet '^Access-Control-Allow-Origin:' "${api_headers}"; then
    echo 'Same-origin API unexpectedly emitted a CORS grant.' >&2
    exit 1
fi
api_etag="$(sed -n 's/^ETag: \(.*\)\r$/\1/ip' "${api_headers}")"
[[ "${api_etag}" =~ ^\"[0-9a-f]{64}\"$ ]]
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '$v=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);$ok=($v["api_version"]??null)==="1.0"&&($v["data"]??null)===[]&&($v["pagination"]["has_more"]??null)===false;exit($ok?0:1);' <"${api_body}"

conditional_headers="${tmp_dir}/api-conditional-headers.txt"
conditional_body="${tmp_dir}/api-conditional-body.txt"
conditional_status="$(curl --silent --show-error \
    --dump-header "${conditional_headers}" \
    --output "${conditional_body}" \
    --write-out '%{http_code}' \
    --header "If-None-Match: ${api_etag}" \
    "${APP_ORIGIN}/api/v1/messages")"
[[ "${conditional_status}" == 304 ]]
[[ ! -s "${conditional_body}" ]]
if grep --ignore-case --quiet '^Set-Cookie:' "${conditional_headers}"; then
    echo 'Conditional API request unexpectedly emitted a cookie.' >&2
    exit 1
fi

[[ "$(curl --silent --show-error --head --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/api/v1/messages")" == 200 ]]
[[ "$(curl --silent --show-error --output "${tmp_dir}/api-error.json" --write-out '%{http_code}' "${APP_ORIGIN}/api/v1/messages?limit=0")" == 400 ]]
grep --quiet '"code":"invalid_limit"' "${tmp_dir}/api-error.json"
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/api/v1/messages?cursor=invalid")" == 400 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/api/v1/messages?unknown=1")" == 400 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --data 'action=post' "${APP_ORIGIN}/api/v1/messages")" == 405 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/not-a-route")" == 404 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/archive/2026/00")" == 404 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/archive/2026/13")" == 404 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/archive")" == 404 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/archive?unexpected=1")" == 400 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --data 'x=1' "${APP_ORIGIN}/archive")" == 405 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/archive-index.php")" == 404 ]]

sitemap_headers="${tmp_dir}/sitemap-headers.txt"
sitemap_body="${tmp_dir}/sitemap.xml"
[[ "$(curl --silent --show-error --dump-header "${sitemap_headers}" --output "${sitemap_body}" --write-out '%{http_code}' "${APP_ORIGIN}/sitemap.xml")" == 200 ]]
grep --ignore-case --quiet '^Content-Type: application/xml; charset=utf-8' "${sitemap_headers}"
grep --ignore-case --quiet '^Cache-Control: public, max-age=30, stale-while-revalidate=60' "${sitemap_headers}"
if grep --ignore-case --quiet '^Set-Cookie:' "${sitemap_headers}"; then
    echo 'Sitemap unexpectedly emitted a cookie.' >&2
    exit 1
fi
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '$xml=simplexml_load_file($argv[1]);exit($xml===false?1:0);' "${sitemap_body}"
grep --fixed-strings --quiet "<loc>${APP_ORIGIN}/</loc>" "${sitemap_body}"
for info_path in about privacy moderation; do
    grep --fixed-strings --quiet "<loc>${APP_ORIGIN}/${info_path}</loc>" "${sitemap_body}"
done
sitemap_etag="$(sed -n 's/^ETag: \(.*\)\r$/\1/ip' "${sitemap_headers}")"
[[ "${sitemap_etag}" =~ ^\"[0-9a-f]{64}\"$ ]]
[[ "$(curl --silent --show-error --output "${tmp_dir}/sitemap-conditional.xml" \
    --write-out '%{http_code}' --header "If-None-Match: ${sitemap_etag}" \
    "${APP_ORIGIN}/sitemap.xml")" == 304 ]]
[[ ! -s "${tmp_dir}/sitemap-conditional.xml" ]]
[[ "$(curl --silent --show-error --head --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/sitemap.xml")" == 200 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/sitemap.xml?unexpected=1")" == 400 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --data 'x=1' "${APP_ORIGIN}/sitemap.xml")" == 405 ]]

feed_headers="${tmp_dir}/feed-headers.txt"
feed_body="${tmp_dir}/feed.xml"
[[ "$(curl --silent --show-error --dump-header "${feed_headers}" --output "${feed_body}" --write-out '%{http_code}' "${APP_ORIGIN}/feed.xml")" == 200 ]]
grep --ignore-case --quiet '^Content-Type: application/rss+xml; charset=utf-8' "${feed_headers}"
grep --ignore-case --quiet '^X-Robots-Tag: noindex' "${feed_headers}"
if grep --ignore-case --quiet '^Set-Cookie:' "${feed_headers}"; then
    echo 'RSS feed unexpectedly emitted a cookie.' >&2
    exit 1
fi
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '$xml=simplexml_load_file($argv[1]);exit($xml===false?1:0);' "${feed_body}"

json_feed_headers="${tmp_dir}/json-feed-headers.txt"
json_feed_body="${tmp_dir}/feed.json"
[[ "$(curl --silent --show-error --dump-header "${json_feed_headers}" --output "${json_feed_body}" --write-out '%{http_code}' "${APP_ORIGIN}/feed.json")" == 200 ]]
grep --ignore-case --quiet '^Content-Type: application/feed+json; charset=utf-8' "${json_feed_headers}"
grep --ignore-case --quiet '^Cache-Control: public, max-age=30, stale-while-revalidate=60' "${json_feed_headers}"
grep --ignore-case --quiet '^X-Robots-Tag: noindex' "${json_feed_headers}"
if grep --ignore-case --quiet '^Set-Cookie:' "${json_feed_headers}"; then
    echo 'JSON Feed unexpectedly emitted a cookie.' >&2
    exit 1
fi
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '
    $v=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);
    $ok=($v["version"]??null)==="https://jsonfeed.org/version/1.1"
        &&($v["home_page_url"]??null)===$argv[2]."/"
        &&($v["feed_url"]??null)===$argv[2]."/feed.json"
        &&($v["items"]??null)===[];
    exit($ok?0:1);
' "${json_feed_body}" "${APP_ORIGIN}"
json_feed_etag="$(sed -n 's/^ETag: \(.*\)\r$/\1/ip' "${json_feed_headers}")"
[[ "${json_feed_etag}" =~ ^\"[0-9a-f]{64}\"$ ]]
[[ "$(curl --silent --show-error --output "${tmp_dir}/json-feed-conditional.json" \
    --write-out '%{http_code}' --header "If-None-Match: ${json_feed_etag}" \
    "${APP_ORIGIN}/feed.json")" == 304 ]]
[[ ! -s "${tmp_dir}/json-feed-conditional.json" ]]
[[ "$(curl --silent --show-error --head --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/feed.json")" == 200 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/feed.json?unexpected=1")" == 400 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --data 'x=1' "${APP_ORIGIN}/feed.json")" == 405 ]]

robots_body="$(curl --silent --show-error --fail "${APP_ORIGIN}/robots.txt")"
grep --fixed-strings --quiet 'Sitemap: https://php.micutu.com/sitemap.xml' <<<"${robots_body}"
favicon_headers="${tmp_dir}/favicon-headers.txt"
favicon_body="${tmp_dir}/favicon.svg"
curl --silent --show-error --fail --dump-header "${favicon_headers}" \
    --output "${favicon_body}" "${APP_ORIGIN}/assets/favicon.svg"
grep --ignore-case --quiet '^Content-Type: image/svg+xml' "${favicon_headers}"
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '$xml=simplexml_load_file($argv[1]);exit($xml!==false&&$xml->getName()==="svg"?0:1);' "${favicon_body}"
social_image_headers="${tmp_dir}/social-image-headers.txt"
social_image_body="${tmp_dir}/social-card.png"
curl --silent --show-error --fail --dump-header "${social_image_headers}" \
    --output "${social_image_body}" "${APP_ORIGIN}/assets/social-card.png"
grep --ignore-case --quiet '^Content-Type: image/png' "${social_image_headers}"
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '$v=getimagesize($argv[1]);exit(is_array($v)&&$v[0]===1200&&$v[1]===630&&($v["mime"]??null)==="image/png"?0:1);' "${social_image_body}"
[[ "$(sha256sum "${social_image_body}" | cut -d' ' -f1)" == \
    ec387ed5b4788647dd9ca9bdfef8f2f38cfe36c4393735f94eaa13fcb23de3e9 ]]

for info_path in about privacy moderation; do
    info_headers="${tmp_dir}/${info_path}-headers.txt"
    info_body="${tmp_dir}/${info_path}.html"
    [[ "$(curl --silent --show-error --dump-header "${info_headers}" --output "${info_body}" \
        --write-out '%{http_code}' "${APP_ORIGIN}/${info_path}")" == 200 ]]
    grep --ignore-case --quiet '^Content-Type: text/html; charset=utf-8' "${info_headers}"
    grep --ignore-case --quiet '^Cache-Control: public, max-age=30, stale-while-revalidate=60, no-transform' "${info_headers}"
    grep --ignore-case --quiet '^Vary: Accept-Encoding' "${info_headers}"
    if grep --ignore-case --quiet '^Set-Cookie:' "${info_headers}"; then
        echo "Public ${info_path} page unexpectedly emitted a cookie." >&2
        exit 1
    fi
    grep --fixed-strings --quiet "<link rel=\"canonical\" href=\"${APP_ORIGIN}/${info_path}\">" "${info_body}"
    grep --fixed-strings --quiet 'itemtype="https://schema.org/BreadcrumbList"' "${info_body}"
    grep --fixed-strings --quiet 'aria-label="Site information"' "${info_body}"
    grep --fixed-strings --quiet 'href="/privacy"' "${info_body}"
done
grep --fixed-strings --quiet 'itemtype="https://schema.org/AboutPage"' "${tmp_dir}/about.html"
grep --fixed-strings --quiet 'itemtype="https://schema.org/WebPage"' "${tmp_dir}/privacy.html"
grep --fixed-strings --quiet 'This is a public guestbook.' "${tmp_dir}/privacy.html"
grep --fixed-strings --quiet 'Reports contain no free-text narrative' "${tmp_dir}/moderation.html"
privacy_etag="$(sed -n 's/^ETag: \(.*\)\r$/\1/ip' "${tmp_dir}/privacy-headers.txt")"
[[ "${privacy_etag}" =~ ^\"[0-9a-f]{64}\"$ ]]
[[ "$(curl --silent --show-error --output "${tmp_dir}/privacy-conditional.html" \
    --write-out '%{http_code}' --header "If-None-Match: ${privacy_etag}" \
    "${APP_ORIGIN}/privacy")" == 304 ]]
[[ ! -s "${tmp_dir}/privacy-conditional.html" ]]
[[ "$(curl --silent --show-error --head --output /dev/null \
    --write-out '%{http_code}' "${APP_ORIGIN}/privacy")" == 200 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    "${APP_ORIGIN}/privacy?unexpected=1")" == 400 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    --data 'x=1' "${APP_ORIGIN}/privacy")" == 405 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    "${APP_ORIGIN}/public-info.php")" == 404 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' \
    "${APP_ORIGIN}/session-policy.php")" == 404 ]]
if find "${web_session_dir}" -type f -print -quit | grep --quiet .; then
    echo 'Sessionless public routes unexpectedly created a PHP session.' >&2
    exit 1
fi
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${APP_ORIGIN}/archive/2026/10")" == 404 ]]
openapi_body="$(curl --silent --show-error --fail "${APP_ORIGIN}/openapi.json")"
grep --quiet '"/api/v1/messages"' <<<"${openapi_body}"

forged_session_id='attackerchosenid0123456789abcdef'
forged_cookie_jar="${tmp_dir}/forged-cookies.txt"
forged_headers="${tmp_dir}/forged-headers.txt"
curl --silent --show-error --output /dev/null \
    --dump-header "${forged_headers}" \
    --cookie "gbsid=${forged_session_id}" \
    --cookie-jar "${forged_cookie_jar}" \
    "${APP_ORIGIN}/"
strict_session_id="$(cookie_value "${forged_cookie_jar}" gbsid)"
[[ -n "${strict_session_id}" && "${strict_session_id}" != "${forged_session_id}" ]]
[[ ! -e "${web_session_dir}/sess_${forged_session_id}" ]]
grep --ignore-case --quiet '^Set-Cookie: gbsid=' "${forged_headers}"

cookie_jar="${tmp_dir}/cookies.txt"
html="$(curl --silent --show-error --cookie-jar "${cookie_jar}" "${APP_ORIGIN}/")"
if ! find "${web_session_dir}" -type f -print -quit | grep --quiet .; then
    echo 'Interactive HTML request did not create its expected PHP session.' >&2
    exit 1
fi
csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${html}" | head -n 1)"
[[ "${csrf}" =~ ^[0-9a-f]{64}$ ]]
grep --quiet 'data-website-id="c4e3a772-26b3-47e7-afec-b82097684551"' <<<"${html}"
grep --quiet 'src="/assets/analytics.js?v=[0-9]\+"' <<<"${html}"
grep --fixed-strings --quiet 'data-host-url="https://analytics.micutu.com"' <<<"${html}"
grep --quiet 'data-domains="php.micutu.com"' <<<"${html}"
grep --quiet 'data-do-not-track="true"' <<<"${html}"
grep --quiet 'data-exclude-search="true"' <<<"${html}"
grep --quiet 'data-exclude-hash="true"' <<<"${html}"
grep --quiet 'data-performance="true"' <<<"${html}"
grep --fixed-strings --quiet '<title>Micuțu Guestbook · Leave a public message</title>' <<<"${html}"
grep --fixed-strings --quiet "<link rel=\"canonical\" href=\"${APP_ORIGIN}/\">" <<<"${html}"
grep --fixed-strings --quiet '<meta name="robots" content="index, follow,' <<<"${html}"
grep --fixed-strings --quiet '<meta property="og:site_name" content="Micuțu Guestbook">' <<<"${html}"
grep --fixed-strings --quiet '<meta property="og:type" content="website">' <<<"${html}"
grep --fixed-strings --quiet "<meta property=\"og:image\" content=\"${APP_ORIGIN}/assets/social-card.png?v=" <<<"${html}"
grep --fixed-strings --quiet '<meta property="og:image:type" content="image/png">' <<<"${html}"
grep --fixed-strings --quiet '<meta property="og:image:width" content="1200">' <<<"${html}"
grep --fixed-strings --quiet '<meta property="og:image:height" content="630">' <<<"${html}"
grep --fixed-strings --quiet '<meta property="og:image:alt" content="An open guestbook surrounded by friendly message cards.">' <<<"${html}"
grep --fixed-strings --quiet '<meta name="twitter:card" content="summary_large_image">' <<<"${html}"
grep --fixed-strings --quiet "<meta name=\"twitter:image\" content=\"${APP_ORIGIN}/assets/social-card.png?v=" <<<"${html}"
grep --fixed-strings --quiet '<meta name="twitter:image:alt" content="An open guestbook surrounded by friendly message cards.">' <<<"${html}"
grep --fixed-strings --quiet 'itemtype="https://schema.org/WebSite"' <<<"${html}"
grep --fixed-strings --quiet 'href="/feed.xml"' <<<"${html}"
grep --fixed-strings --quiet 'type="application/feed+json"' <<<"${html}"
grep --fixed-strings --quiet 'href="/feed.json"' <<<"${html}"
grep --fixed-strings --quiet 'href="/assets/favicon.svg"' <<<"${html}"
analytics_asset="$(curl --silent --show-error --fail "${APP_ORIGIN}/assets/analytics.js")"
[[ "$(sha256sum <<<"${analytics_asset}" | cut -d' ' -f1)" == \
    be444c289ac019af8486b50fe2bbf2fdb2890812fb945dc78940b6781a68ac52 ]]

request_code() {
    curl --silent --show-error --output /dev/null --write-out '%{http_code}' "$@"
}

[[ "$(request_code -X PUT "${APP_ORIGIN}/")" == 405 ]]
[[ "$(request_code "${APP_ORIGIN}/index.php/extra")" == 404 ]]
[[ "$(request_code "${APP_ORIGIN}/?q%5B%5D=nested")" == 400 ]]
[[ "$(request_code "${APP_ORIGIN}/?page%5B%5D=2")" == 400 ]]

sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "WITH RECURSIVE n(x) AS (VALUES(1) UNION ALL SELECT x + 1 FROM n WHERE x < 11)
     INSERT INTO messages (author, content, created_at, status)
     SELECT 'Archive fixture', 'Temporary archive fixture ' || x,
            printf('2025-07-%02d 12:00:00', x), 'published' FROM n;"
archive_index_headers="${tmp_dir}/archive-index-headers.txt"
archive_index_body="${tmp_dir}/archive-index.html"
[[ "$(curl --silent --show-error --dump-header "${archive_index_headers}" \
    --output "${archive_index_body}" --write-out '%{http_code}' "${APP_ORIGIN}/archive")" == 200 ]]
grep --ignore-case --quiet '^Content-Type: text/html; charset=utf-8' "${archive_index_headers}"
grep --ignore-case --quiet '^Cache-Control: public, max-age=30, stale-while-revalidate=60, no-transform' "${archive_index_headers}"
if grep --ignore-case --quiet '^Set-Cookie:' "${archive_index_headers}"; then
    echo 'Sessionless archive index unexpectedly emitted a cookie.' >&2
    exit 1
fi
grep --fixed-strings --quiet "<link rel=\"canonical\" href=\"${APP_ORIGIN}/archive\">" "${archive_index_body}"
grep --fixed-strings --quiet 'itemtype="https://schema.org/CollectionPage"' "${archive_index_body}"
grep --fixed-strings --quiet 'itemtype="https://schema.org/ItemList"' "${archive_index_body}"
grep --fixed-strings --quiet 'href="/archive/2025/07"' "${archive_index_body}"
grep --fixed-strings --quiet '11 messages' "${archive_index_body}"
if grep --fixed-strings --quiet 'Temporary archive fixture' "${archive_index_body}"; then
    echo 'Archive index exposed message content.' >&2
    exit 1
fi
archive_index_etag="$(sed -n 's/^ETag: \(.*\)\r$/\1/ip' "${archive_index_headers}")"
[[ "${archive_index_etag}" =~ ^\"[0-9a-f]{64}\"$ ]]
[[ "$(curl --silent --show-error --output "${tmp_dir}/archive-index-conditional.html" \
    --write-out '%{http_code}' --header "If-None-Match: ${archive_index_etag}" \
    "${APP_ORIGIN}/archive")" == 304 ]]
[[ ! -s "${tmp_dir}/archive-index-conditional.html" ]]
[[ "$(request_code --head "${APP_ORIGIN}/archive")" == 200 ]]
archive_page_one="$(curl --silent --show-error --fail "${APP_ORIGIN}/archive/2025/07")"
grep --fixed-strings --quiet "<link rel=\"canonical\" href=\"${APP_ORIGIN}/archive/2025/07\">" <<<"${archive_page_one}"
grep --fixed-strings --quiet "<link rel=\"next\" href=\"${APP_ORIGIN}/archive/2025/07?page=2\">" <<<"${archive_page_one}"
grep --fixed-strings --quiet 'Temporary archive fixture 11' <<<"${archive_page_one}"
if grep --fixed-strings --quiet 'Temporary archive fixture 1</p>' <<<"${archive_page_one}"; then
    echo 'Archive pagination exposed a record from the next page.' >&2
    exit 1
fi
archive_page_two="$(curl --silent --show-error --fail "${APP_ORIGIN}/archive/2025/07?page=2")"
grep --fixed-strings --quiet "<link rel=\"canonical\" href=\"${APP_ORIGIN}/archive/2025/07?page=2\">" <<<"${archive_page_two}"
grep --fixed-strings --quiet "<link rel=\"prev\" href=\"${APP_ORIGIN}/archive/2025/07\">" <<<"${archive_page_two}"
grep --fixed-strings --quiet 'Temporary archive fixture 1</p>' <<<"${archive_page_two}"
[[ "$(request_code "${APP_ORIGIN}/archive/2025/07?page=3")" == 404 ]]
sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "DELETE FROM messages WHERE author = 'Archive fixture';"
[[ "$(request_code "${APP_ORIGIN}/archive/2025/07")" == 404 ]]
[[ "$(request_code "${APP_ORIGIN}/archive")" == 404 ]]

cross_origin_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header 'Origin: https://attacker.example' \
    --data-urlencode "csrf=${csrf}" \
    --data-urlencode 'action=post' \
    --data-urlencode 'author=Alice' \
    --data-urlencode 'message=blocked' \
    "${APP_ORIGIN}/")"
[[ "${cross_origin_code}" == 303 ]]

invalid_post_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${csrf}" \
    --data-urlencode 'action=post' \
    --data-urlencode 'website=' \
    --data-urlencode 'author=Remember me' \
    --data-urlencode 'message=   ' \
    "${APP_ORIGIN}/")"
[[ "${invalid_post_code}" == 303 ]]
validation_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
grep --quiet 'value="Remember me"' <<<"${validation_html}"

post_headers="${tmp_dir}/post-headers.txt"
post_code="$(request_code \
    --cookie "${cookie_jar}" \
    --cookie-jar "${cookie_jar}" \
    --dump-header "${post_headers}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${csrf}" \
    --data-urlencode 'action=post' \
    --data-urlencode 'website=' \
    --data-urlencode 'author=Alice' \
    --data-urlencode 'message=Hello from the isolated smoke test' \
    "${APP_ORIGIN}/")"
[[ "${post_code}" == 303 ]]

[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" 'SELECT COUNT(*) FROM messages;')" == 1 ]]
message_id="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT id FROM messages WHERE status = 'published' LIMIT 1;")"
manage_path="/messages/${message_id}/manage"
grep --ignore-case --quiet "^Location: ${manage_path}" "${post_headers}"
grep --ignore-case --quiet "^Set-Cookie: gbowner_${message_id}=.*HttpOnly.*SameSite=Strict" "${post_headers}"
grep --ignore-case --quiet "Path=${manage_path}" "${post_headers}"
if grep --ignore-case '^Location:' "${post_headers}" | grep --quiet 'gbowner\|token'; then
    echo 'Ownership capability unexpectedly appeared in the redirect URL.' >&2
    exit 1
fi

sessions_before_private_miss="$(find "${web_session_dir}" -type f | wc -l)"
[[ "$(request_code "${APP_ORIGIN}${manage_path}")" == 404 ]]
sessions_after_private_miss="$(find "${web_session_dir}" -type f | wc -l)"
[[ "${sessions_after_private_miss}" == "${sessions_before_private_miss}" ]]

manage_headers="${tmp_dir}/manage-headers.txt"
manage_html="$(curl --silent --show-error --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" \
    --dump-header "${manage_headers}" "${APP_ORIGIN}${manage_path}")"
grep --ignore-case --quiet '^Cache-Control: private, no-store, max-age=0' "${manage_headers}"
grep --ignore-case --quiet '^X-Robots-Tag: noindex, noarchive' "${manage_headers}"
grep --ignore-case --quiet '^Referrer-Policy: same-origin' "${manage_headers}"
grep --fixed-strings --quiet '<meta name="robots" content="noindex, nofollow, noarchive">' <<<"${manage_html}"
grep --fixed-strings --quiet 'Manage message #' <<<"${manage_html}"
grep --fixed-strings --quiet 'Message posted!' <<<"${manage_html}"
grep --fixed-strings --quiet "href=\"/messages/${message_id}\"" <<<"${manage_html}"
grep --fixed-strings --quiet 'data-clear-compose-draft' <<<"${manage_html}"
grep --fixed-strings --quiet 'Download my message' <<<"${manage_html}"
grep --fixed-strings --quiet 'It excludes network identifiers, review signals, internal reasons and ownership keys.' <<<"${manage_html}"
if grep --fixed-strings --quiet 'Hello from the isolated smoke test' <<<"${manage_html}" \
    || grep --quiet 'analytics.js\|rel="canonical"\|gbowner_' <<<"${manage_html}"; then
    echo 'Private management page exposed content, analytics, canonical metadata or cookie names.' >&2
    exit 1
fi
stored_owner_hash="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT owner_token_hash FROM messages WHERE id = ${message_id};")"
[[ "${stored_owner_hash}" =~ ^[0-9a-f]{64}$ ]]
if grep --fixed-strings --quiet "${stored_owner_hash}" <<<"${manage_html}"; then
    echo 'Stored ownership digest unexpectedly appeared in HTML.' >&2
    exit 1
fi

legacy_permalink_headers="${tmp_dir}/legacy-permalink-headers.txt"
legacy_permalink_body="${tmp_dir}/legacy-permalink-body.txt"
[[ "$(curl --silent --show-error --dump-header "${legacy_permalink_headers}" \
    --output "${legacy_permalink_body}" --write-out '%{http_code}' \
    "${APP_ORIGIN}/?message=${message_id}")" == 301 ]]
grep --ignore-case --quiet "^Location: /messages/${message_id}" "${legacy_permalink_headers}"
if grep --ignore-case --quiet '^Set-Cookie:' "${legacy_permalink_headers}"; then
    echo 'Legacy permalink redirect unexpectedly emitted a cookie.' >&2
    exit 1
fi
[[ ! -s "${legacy_permalink_body}" ]]
permalink_html="$(curl --silent --show-error --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" "${APP_ORIGIN}/messages/${message_id}")"
grep --quiet 'Hello from the isolated smoke test' <<<"${permalink_html}"
grep --fixed-strings --quiet 'data-compose-form data-clear-local-draft="true"' <<<"${permalink_html}"
grep --fixed-strings --quiet 'data-draft-opt-in' <<<"${permalink_html}"
grep --fixed-strings --quiet 'data-preview-toggle' <<<"${permalink_html}"
grep --quiet 'No free-text report or reporter identifier is stored.' <<<"${permalink_html}"
grep --fixed-strings --quiet '<title>Message by Alice · Micuțu Guestbook</title>' <<<"${permalink_html}"
grep --fixed-strings --quiet "<link rel=\"canonical\" href=\"${APP_ORIGIN}/messages/${message_id}\">" <<<"${permalink_html}"
grep --fixed-strings --quiet '<meta property="og:type" content="article">' <<<"${permalink_html}"
grep --fixed-strings --quiet '<meta property="article:published_time" content="' <<<"${permalink_html}"
grep --fixed-strings --quiet 'itemtype="https://schema.org/DiscussionForumPosting"' <<<"${permalink_html}"
grep --fixed-strings --quiet 'itemprop="image"' <<<"${permalink_html}"
grep --fixed-strings --quiet 'itemtype="https://schema.org/BreadcrumbList"' <<<"${permalink_html}"
grep --fixed-strings --quiet 'aria-current="page"' <<<"${permalink_html}"
grep --fixed-strings --quiet 'itemprop="datePublished"' <<<"${permalink_html}"
grep --fixed-strings --quiet 'itemprop="text"' <<<"${permalink_html}"
grep --fixed-strings --quiet "data-share-path=\"/messages/${message_id}\"" <<<"${permalink_html}"
grep --fixed-strings --quiet "data-share-title=\"Micuțu Guestbook message #${message_id}\"" <<<"${permalink_html}"
grep --fixed-strings --quiet 'class="share-button" type="button" hidden' <<<"${permalink_html}"
if grep --fixed-strings --quiet 'data-share-title="Micuțu Guestbook message by Alice' <<<"${permalink_html}"; then
    echo 'Share metadata unexpectedly contains the visitor name.' >&2
    exit 1
fi
[[ "$(request_code "${APP_ORIGIN}/?message%5B%5D=${message_id}")" == 400 ]]
[[ "$(request_code "${APP_ORIGIN}/messages/999999999")" == 404 ]]
[[ "$(request_code "${APP_ORIGIN}/messages/${message_id}?q=hello")" == 400 ]]
[[ "$(request_code "${APP_ORIGIN}/messages/not-a-number")" == 404 ]]
[[ "$(request_code "${APP_ORIGIN}/?message=${message_id}&q=hello")" == 400 ]]
[[ "$(request_code "${APP_ORIGIN}/?page=999999")" == 404 ]]

duplicate_cookie_jar="${tmp_dir}/duplicate-cookies.txt"
duplicate_html="$(curl --silent --show-error --cookie-jar "${duplicate_cookie_jar}" "${APP_ORIGIN}/")"
duplicate_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${duplicate_html}" | head -n 1)"
[[ "${duplicate_csrf}" =~ ^[0-9a-f]{64}$ ]]
duplicate_headers="${tmp_dir}/duplicate-post-headers.txt"
duplicate_code="$(request_code \
    --cookie "${duplicate_cookie_jar}" \
    --cookie-jar "${duplicate_cookie_jar}" \
    --dump-header "${duplicate_headers}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${duplicate_csrf}" \
    --data-urlencode 'action=post' \
    --data-urlencode 'website=' \
    --data-urlencode 'author=Copy bot' \
    --data-urlencode 'message=Hello from the isolated smoke test' \
    "${APP_ORIGIN}/")"
[[ "${duplicate_code}" == 303 ]]
duplicate_message_id="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT id FROM messages WHERE status = 'pending' ORDER BY id DESC LIMIT 1;")"
duplicate_manage_path="/messages/${duplicate_message_id}/manage"
grep --ignore-case --quiet "^Location: ${duplicate_manage_path}" "${duplicate_headers}"
queued_html="$(curl --silent --show-error --cookie "${duplicate_cookie_jar}" "${APP_ORIGIN}${duplicate_manage_path}")"
grep --quiet 'Message received and queued for moderator review.' <<<"${queued_html}"
grep --quiet 'Awaiting moderator review' <<<"${queued_html}"
if grep --quiet 'Hello from the isolated smoke test\|analytics.js' <<<"${queued_html}"; then
    echo 'Pending-message management page exposed content or analytics.' >&2
    exit 1
fi
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM messages
     WHERE status = 'pending' AND moderation_reason = 'risk' AND risk_score >= 50
       AND EXISTS (SELECT 1 FROM json_each(risk_flags) WHERE value = 'duplicate');")" == 1 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM security_events
     WHERE event_type = 'message.queued' AND subject_hash IS NULL;")" == 1 ]]
public_html="$(curl --silent --show-error "${APP_ORIGIN}/")"
[[ "$(grep -o 'Hello from the isolated smoke test' <<<"${public_html}" | wc -l)" == 1 ]]
sitemap_populated="$(curl --silent --show-error "${APP_ORIGIN}/sitemap.xml")"
grep --fixed-strings --quiet "${APP_ORIGIN}/messages/${message_id}" <<<"${sitemap_populated}"
grep --fixed-strings --quiet "<loc>${APP_ORIGIN}/archive</loc>" <<<"${sitemap_populated}"
[[ "$(grep -o '/messages/' <<<"${sitemap_populated}" | wc -l)" == 1 ]]
archive_key="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT substr(created_at, 1, 7) FROM messages WHERE id = ${message_id};")"
[[ "${archive_key}" =~ ^[12][0-9]{3}-(0[1-9]|1[0-2])$ ]]
archive_path="/archive/${archive_key/-//}"
grep --fixed-strings --quiet "${APP_ORIGIN}${archive_path}" <<<"${sitemap_populated}"
archive_index_populated="$(curl --silent --show-error --fail "${APP_ORIGIN}/archive")"
grep --fixed-strings --quiet "href=\"${archive_path}\"" <<<"${archive_index_populated}"
grep --fixed-strings --quiet '1 message' <<<"${archive_index_populated}"
if grep --fixed-strings --quiet 'Hello from the isolated smoke test' <<<"${archive_index_populated}"; then
    echo 'Archive index exposed published message content instead of an aggregate.' >&2
    exit 1
fi
archive_html="$(curl --silent --show-error --fail "${APP_ORIGIN}${archive_path}")"
grep --fixed-strings --quiet "<link rel=\"canonical\" href=\"${APP_ORIGIN}${archive_path}\">" <<<"${archive_html}"
grep --fixed-strings --quiet ' archive · Micuțu Guestbook</title>' <<<"${archive_html}"
grep --fixed-strings --quiet 'itemtype="https://schema.org/BreadcrumbList"' <<<"${archive_html}"
grep --fixed-strings --quiet 'aria-current="page"' <<<"${archive_html}"
[[ "$(grep -o 'Hello from the isolated smoke test' <<<"${archive_html}" | wc -l)" == 1 ]]
[[ "$(request_code --head "${APP_ORIGIN}${archive_path}")" == 200 ]]
[[ "$(request_code "${APP_ORIGIN}${archive_path}?q=isolated")" == 400 ]]
feed_populated="$(curl --silent --show-error "${APP_ORIGIN}/feed.xml")"
[[ "$(grep -o 'Hello from the isolated smoke test' <<<"${feed_populated}" | wc -l)" == 1 ]]
json_feed_populated="$(curl --silent --show-error "${APP_ORIGIN}/feed.json")"
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '
    $v=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    $i=$v["items"][0]??null;
    $ok=count($v["items"]??[])===1
        &&($i["url"]??null)===$argv[1]
        &&($i["content_text"]??null)==="Hello from the isolated smoke test";
    exit($ok?0:1);
' "${APP_ORIGIN}/messages/${message_id}" <<<"${json_feed_populated}"

search_html="$(curl --silent --show-error --get \
    --data-urlencode 'q=Alice isolated smoke' "${APP_ORIGIN}/")"
grep --quiet 'Hello from the isolated smoke test' <<<"${search_html}"
grep --fixed-strings --quiet '<meta name="robots" content="noindex, follow">' <<<"${search_html}"
special_search_code="$(request_code --get \
    --data-urlencode 'q=" OR author:* NEAR(' "${APP_ORIGIN}/")"
[[ "${special_search_code}" == 200 ]]

populated_api_headers="${tmp_dir}/api-populated-headers.txt"
populated_api_body="$(curl --silent --show-error --dump-header "${populated_api_headers}" "${APP_ORIGIN}/api/v1/messages?limit=1")"
grep --quiet 'Hello from the isolated smoke test' <<<"${populated_api_body}"
grep --fixed-strings --quiet '"featured":false' <<<"${populated_api_body}"
populated_etag="$(sed -n 's/^ETag: \(.*\)\r$/\1/ip' "${populated_api_headers}")"
[[ "${populated_etag}" =~ ^\"[0-9a-f]{64}\"$ ]]
[[ "${populated_etag}" != "${api_etag}" ]]
if grep --ignore-case --quiet '^Set-Cookie:' "${populated_api_headers}"; then
    echo 'Populated API request unexpectedly emitted a cookie.' >&2
    exit 1
fi

report_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${permalink_html}" | head -n 1)"
[[ "${report_csrf}" =~ ^[0-9a-f]{64}$ ]]
invalid_report_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${report_csrf}" \
    --data-urlencode 'action=report' \
    --data-urlencode "id=${message_id}" \
    --data-urlencode 'category=unknown' \
    "${APP_ORIGIN}/")"
[[ "${invalid_report_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" 'SELECT COUNT(*) FROM abuse_reports;')" == 0 ]]

report_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${report_csrf}" \
    --data-urlencode 'action=report' \
    --data-urlencode "id=${message_id}" \
    --data-urlencode 'category=privacy' \
    "${APP_ORIGIN}/")"
[[ "${report_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT message_id || ':' || category || ':' || status FROM abuse_reports;")" == "${message_id}:privacy:open" ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT attempts FROM rate_limits WHERE scope = 'abuse-report';")" == 1 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT group_concat(name, ',') FROM pragma_table_info('abuse_reports');")" == 'id,message_id,category,status,created_at,reviewed_at' ]]

unauthorised_feature_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${report_csrf}" \
    --data-urlencode 'action=feature' \
    --data-urlencode "id=${message_id}" \
    "${APP_ORIGIN}/")"
[[ "${unauthorised_feature_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM messages WHERE featured_at IS NOT NULL;")" == 0 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM security_events
     WHERE event_type = 'message.feature' AND outcome = 'blocked';")" == 1 ]]

unauthorised_bulk_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${report_csrf}" \
    --data-urlencode 'action=bulk_moderate' \
    --data-urlencode "message_ids[]=${message_id}" \
    --data-urlencode 'status=hidden' \
    --data-urlencode 'reason=policy' \
    "${APP_ORIGIN}/")"
[[ "${unauthorised_bulk_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT status FROM messages WHERE id = ${message_id};")" == published ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM security_events
     WHERE event_type = 'message.bulk_moderate' AND outcome = 'blocked';")" == 1 ]]

legacy_rss_headers="$(curl --silent --show-error --head "${APP_ORIGIN}/?format=rss")"
grep --ignore-case --quiet '^HTTP/1.1 301' <<<"${legacy_rss_headers}"
grep --ignore-case --quiet '^Location: /feed.xml' <<<"${legacy_rss_headers}"
rss="$(curl --silent --show-error "${APP_ORIGIN}/feed.xml")"
grep --quiet '<rss version="2.0">' <<<"${rss}"
grep --quiet 'Hello from the isolated smoke test' <<<"${rss}"
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '$xml = simplexml_load_string(stream_get_contents(STDIN)); exit($xml === false ? 1 : 0);' <<<"${rss}"

legacy_headers="$(curl --silent --show-error --head "${APP_ORIGIN}/?format=json")"
grep --ignore-case --quiet '^Deprecation: true' <<<"${legacy_headers}"
grep --ignore-case --quiet '^Link: </api/v1/messages>; rel="successor-version"' <<<"${legacy_headers}"
[[ "$(request_code --cookie "${cookie_jar}" "${APP_ORIGIN}/?admin_status=pending")" == 400 ]]

login_html="$(curl --silent --show-error --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" "${APP_ORIGIN}/")"
login_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${login_html}" | head -n 1)"
[[ "${login_csrf}" =~ ^[0-9a-f]{64}$ ]]

wrong_login_code="$(request_code \
    --cookie "${cookie_jar}" \
    --cookie-jar "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${login_csrf}" \
    --data-urlencode 'action=login' \
    --data-urlencode 'password=wrong' \
    "${APP_ORIGIN}/")"
[[ "${wrong_login_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT attempts FROM rate_limits WHERE scope = 'admin-login';")" == 1 ]]

login_html="$(curl --silent --show-error --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" "${APP_ORIGIN}/")"
login_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${login_html}" | head -n 1)"
pre_login_session_id="$(cookie_value "${cookie_jar}" gbsid)"
[[ "${login_csrf}" =~ ^[0-9a-f]{64}$ ]]
[[ -n "${pre_login_session_id}" ]]
good_login_code="$(request_code \
    --cookie "${cookie_jar}" \
    --cookie-jar "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${login_csrf}" \
    --data-urlencode 'action=login' \
    --data-urlencode 'password=smoke-test-password' \
    "${APP_ORIGIN}/")"
[[ "${good_login_code}" == 303 ]]
admin_session_id="$(cookie_value "${cookie_jar}" gbsid)"
[[ -n "${admin_session_id}" && "${admin_session_id}" != "${pre_login_session_id}" ]]
[[ ! -e "${web_session_dir}/sess_${pre_login_session_id}" ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT COUNT(*) FROM rate_limits WHERE scope = 'admin-login';")" == 0 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT COUNT(*) FROM security_events WHERE event_type = 'admin.login';")" == 2 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT COUNT(*) FROM admin_audit_events WHERE event_type = 'admin.login';")" == 1 ]]
sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "INSERT INTO rate_limits
        (scope, subject_hash, window_started_at, attempts, penalty_level,
         blocked_until, updated_at)
     VALUES
        ('admin-login', lower(hex(randomblob(32))),
         CAST(strftime('%s', 'now') AS INTEGER), 5, 1,
         CAST(strftime('%s', 'now') AS INTEGER) + 600,
         CAST(strftime('%s', 'now') AS INTEGER));"
active_throttle_hash="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT subject_hash FROM rate_limits
     WHERE scope = 'admin-login' AND blocked_until > strftime('%s', 'now') LIMIT 1;")"
[[ "${active_throttle_hash}" =~ ^[0-9a-f]{64}$ ]]

admin_headers="${tmp_dir}/admin-headers.txt"
admin_html="$(curl --silent --show-error --dump-header "${admin_headers}" --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
grep --quiet 'Sign out' <<<"${admin_html}"
grep --quiet 'Abuse reports · 1 open' <<<"${admin_html}"
grep --quiet 'Append-only admin audit' <<<"${admin_html}"
grep --quiet 'Review score ' <<<"${admin_html}"
grep --quiet 'Recent duplicate' <<<"${admin_html}"
grep --fixed-strings --quiet 'Operational overview' <<<"${admin_html}"
grep --fixed-strings --quiet 'High-risk pending' <<<"${admin_html}"
grep --fixed-strings --quiet 'Security exceptions' <<<"${admin_html}"
grep --fixed-strings --quiet 'Suspicious activity · 3 exceptions · 1 active throttle' <<<"${admin_html}"
grep --fixed-strings --quiet 'This summary uses rolling 24-hour aggregates and shows up to 20 groups per list.' <<<"${admin_html}"
grep --fixed-strings --quiet '<code>admin.login</code>' <<<"${admin_html}"
grep --fixed-strings --quiet '<strong>1</strong>' <<<"${admin_html}"
if grep --fixed-strings --quiet "${active_throttle_hash}" <<<"${admin_html}"; then
    echo 'Authenticated operations view exposed a limiter subject hash.' >&2
    exit 1
fi
grep --fixed-strings --quiet '<title>Admin workspace · Micuțu Guestbook</title>' <<<"${admin_html}"
grep --fixed-strings --quiet '<meta name="robots" content="noindex, nofollow, noarchive">' <<<"${admin_html}"
grep --fixed-strings --quiet 'aria-label="Filter moderation workspace"' <<<"${admin_html}"
grep --fixed-strings --quiet 'Search stays on this server and analytics is disabled for authenticated admin views.' <<<"${admin_html}"
grep --fixed-strings --quiet 'id="bulk-moderation-form"' <<<"${admin_html}"
grep --fixed-strings --quiet 'Publication stays individual; the whole batch fails if any selection is ineligible or already in the target state.' <<<"${admin_html}"
grep --ignore-case --quiet '^Cache-Control: private, no-store, max-age=0' "${admin_headers}"
grep --ignore-case --quiet '^X-Robots-Tag: noindex, nofollow, noarchive' "${admin_headers}"
grep --ignore-case --quiet '^Referrer-Policy: same-origin' "${admin_headers}"
if grep --quiet 'analytics.js' <<<"${admin_html}"; then
    echo 'Authenticated admin response unexpectedly loaded analytics.' >&2
    exit 1
fi

filtered_admin_html="$(curl --silent --show-error --get --cookie "${cookie_jar}" \
    --data-urlencode 'admin_status=pending' \
    --data-urlencode 'admin_q=Copy bot' \
    --data-urlencode 'admin_sort=risk' \
    "${APP_ORIGIN}/")"
grep --fixed-strings --quiet 'value="pending" selected' <<<"${filtered_admin_html}"
grep --fixed-strings --quiet 'value="risk" selected' <<<"${filtered_admin_html}"
grep --fixed-strings --quiet 'value="Copy bot"' <<<"${filtered_admin_html}"
grep --fixed-strings --quiet 'Review messages · 1 match' <<<"${filtered_admin_html}"
grep --fixed-strings --quiet '<strong>Copy bot</strong>' <<<"${filtered_admin_html}"
grep --fixed-strings --quiet 'Showing 1–1 of 1.' <<<"${filtered_admin_html}"

empty_admin_html="$(curl --silent --show-error --get --cookie "${cookie_jar}" \
    --data-urlencode 'admin_status=published' \
    --data-urlencode 'admin_q=Copy bot' \
    "${APP_ORIGIN}/")"
grep --fixed-strings --quiet 'Review messages · 0 matches' <<<"${empty_admin_html}"
grep --fixed-strings --quiet 'No messages match these review filters.' <<<"${empty_admin_html}"
if grep --fixed-strings --quiet '<strong>Copy bot</strong>' <<<"${empty_admin_html}"; then
    echo 'Admin status filter returned a row from another state.' >&2
    exit 1
fi
[[ "$(request_code --cookie "${cookie_jar}" "${APP_ORIGIN}/?admin_status%5B%5D=pending")" == 400 ]]
[[ "$(request_code --cookie "${cookie_jar}" "${APP_ORIGIN}/?admin_q%5B%5D=copy")" == 400 ]]
[[ "$(request_code --cookie "${cookie_jar}" "${APP_ORIGIN}/?admin_sort=random")" == 400 ]]
[[ "$(request_code --cookie "${cookie_jar}" "${APP_ORIGIN}/?admin_page=0")" == 400 ]]
[[ "$(request_code --cookie "${cookie_jar}" "${APP_ORIGIN}/?admin_page=2")" == 404 ]]

admin_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${admin_html}" | head -n 1)"
[[ "${admin_csrf}" =~ ^[0-9a-f]{64}$ && "${admin_csrf}" != "${login_csrf}" ]]
sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "INSERT INTO messages (author, content, status)
     VALUES ('Bulk smoke one', 'first atomic HTTP fixture', 'pending'),
            ('Bulk smoke two', 'second atomic HTTP fixture', 'pending');"
bulk_second_id="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT id FROM messages WHERE author = 'Bulk smoke two';")"
bulk_first_id="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT id FROM messages WHERE author = 'Bulk smoke one';")"
[[ "${bulk_first_id}" =~ ^[1-9][0-9]*$ && "${bulk_second_id}" =~ ^[1-9][0-9]*$ ]]

malformed_bulk_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=bulk_moderate' \
    --data-urlencode "message_ids=${bulk_first_id}" \
    --data-urlencode 'status=hidden' \
    --data-urlencode 'reason=policy' \
    "${APP_ORIGIN}/")"
[[ "${malformed_bulk_code}" == 303 ]]
duplicate_bulk_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=bulk_moderate' \
    --data-urlencode "message_ids[]=${bulk_first_id}" \
    --data-urlencode "message_ids[]=${bulk_first_id}" \
    --data-urlencode 'status=hidden' \
    --data-urlencode 'reason=policy' \
    "${APP_ORIGIN}/")"
[[ "${duplicate_bulk_code}" == 303 ]]
bulk_publish_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=bulk_moderate' \
    --data-urlencode "message_ids[]=${bulk_first_id}" \
    --data-urlencode 'status=published' \
    --data-urlencode 'reason=restored' \
    "${APP_ORIGIN}/")"
[[ "${bulk_publish_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT group_concat(status, ':') FROM messages
     WHERE id IN (${bulk_first_id}, ${bulk_second_id}) ORDER BY id;")" == pending:pending ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM security_events
     WHERE event_type = 'message.bulk_moderate' AND outcome = 'failure';")" == 3 ]]

valid_bulk_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=bulk_moderate' \
    --data-urlencode "message_ids[]=${bulk_first_id}" \
    --data-urlencode "message_ids[]=${bulk_second_id}" \
    --data-urlencode 'status=hidden' \
    --data-urlencode 'reason=risk' \
    "${APP_ORIGIN}/")"
[[ "${valid_bulk_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT group_concat(status || ':' || moderation_reason, '|') FROM messages
     WHERE id IN (${bulk_first_id}, ${bulk_second_id}) ORDER BY id;")" == hidden:risk\|hidden:risk ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM admin_audit_events
     WHERE related_id IN (${bulk_first_id}, ${bulk_second_id})
       AND event_type = 'message.hide' AND reason_code = 'risk';")" == 2 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM security_events
     WHERE event_type = 'message.bulk_moderate' AND outcome = 'success'
       AND related_id IS NULL;")" == 1 ]]

feature_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=feature' \
    --data-urlencode "id=${message_id}" \
    "${APP_ORIGIN}/")"
[[ "${feature_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT status || ':' || (featured_at IS NOT NULL) || ':' ||
            (feature_changed_at IS NOT NULL)
     FROM messages WHERE id = ${message_id};")" == published:1:1 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM admin_audit_events
     WHERE event_type = 'message.feature' AND related_id = ${message_id};")" == 1 ]]
featured_public_html="$(curl --silent --show-error "${APP_ORIGIN}/")"
grep --fixed-strings --quiet 'Featured &amp; recent posts' <<<"${featured_public_html}"
grep --fixed-strings --quiet 'Featured message' <<<"${featured_public_html}"
featured_api_body="$(curl --silent --show-error "${APP_ORIGIN}/api/v1/messages?limit=1")"
grep --fixed-strings --quiet '"featured":true' <<<"${featured_api_body}"

admin_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
admin_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${admin_html}" | head -n 1)"
hide_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=moderate' \
    --data-urlencode "id=${message_id}" \
    --data-urlencode 'status=hidden' \
    --data-urlencode 'reason=privacy' \
    "${APP_ORIGIN}/")"
[[ "${hide_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT status || ':' || moderation_reason || ':' || (ip_hash IS NULL) || ':' || (featured_at IS NULL) FROM messages WHERE id = ${message_id};")" == hidden:privacy:1:1 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT previous_state || ':' || new_state || ':' || reason_code FROM admin_audit_events WHERE event_type = 'message.hide' AND related_id = ${message_id};")" == published:hidden:privacy ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM admin_audit_events
     WHERE event_type = 'message.unfeature' AND related_id = ${message_id};")" == 1 ]]
hidden_search_html="$(curl --silent --show-error --get \
    --data-urlencode 'q=isolated smoke' "${APP_ORIGIN}/")"
if grep --quiet 'Hello from the isolated smoke test' <<<"${hidden_search_html}"; then
    echo 'FTS search exposed a moderated message.' >&2
    exit 1
fi
hidden_sitemap="$(curl --silent --show-error "${APP_ORIGIN}/sitemap.xml")"
hidden_feed="$(curl --silent --show-error "${APP_ORIGIN}/feed.xml")"
hidden_json_feed="$(curl --silent --show-error "${APP_ORIGIN}/feed.json")"
hidden_archive_code="$(request_code "${APP_ORIGIN}${archive_path}")"
hidden_archive_index_code="$(request_code "${APP_ORIGIN}/archive")"
if grep --fixed-strings --quiet "/messages/${message_id}" <<<"${hidden_sitemap}" \
    || grep --fixed-strings --quiet "${archive_path}" <<<"${hidden_sitemap}" \
    || grep --fixed-strings --quiet "<loc>${APP_ORIGIN}/archive</loc>" <<<"${hidden_sitemap}" \
    || grep --fixed-strings --quiet 'Hello from the isolated smoke test' <<<"${hidden_feed}" \
    || grep --fixed-strings --quiet 'Hello from the isolated smoke test' <<<"${hidden_json_feed}" \
    || [[ "${hidden_archive_code}" != 404 ]] \
    || [[ "${hidden_archive_index_code}" != 404 ]]; then
    echo 'A discovery document exposed a moderated message.' >&2
    exit 1
fi

admin_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
admin_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${admin_html}" | head -n 1)"
grep --fixed-strings --quiet 'Review messages · 4 matches' <<<"${admin_html}"
report_id="$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT id FROM abuse_reports WHERE status = 'open';")"
review_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=review_report' \
    --data-urlencode "id=${report_id}" \
    --data-urlencode 'decision=resolved' \
    "${APP_ORIGIN}/")"
[[ "${review_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT status FROM abuse_reports WHERE id = ${report_id};")" == resolved ]]

admin_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
admin_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${admin_html}" | head -n 1)"
restore_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=moderate' \
    --data-urlencode "id=${message_id}" \
    --data-urlencode 'status=published' \
    --data-urlencode 'reason=restored' \
    "${APP_ORIGIN}/")"
[[ "${restore_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT status || ':' || (featured_at IS NULL)
     FROM messages WHERE id = ${message_id};")" == published:1 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT status || ':' || moderation_reason FROM messages WHERE id = ${message_id};")" == published:restored ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT COUNT(*) FROM admin_audit_events;")" == 8 ]]

manage_html="$(curl --silent --show-error --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" \
    "${APP_ORIGIN}${manage_path}")"
owner_csrf="$(sed -n 's/.*name="owner_csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${manage_html}" | head -n 1)"
manage_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${manage_html}" | head -n 1)"
[[ "${owner_csrf}" =~ ^[0-9a-f]{64}$ ]]
[[ "${manage_csrf}" =~ ^[0-9a-f]{64}$ ]]

invalid_export_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${manage_csrf}" \
    --data-urlencode "owner_csrf=$(printf '0%.0s' {1..64})" \
    --data-urlencode 'action=owner_export' \
    "${APP_ORIGIN}${manage_path}")"
[[ "${invalid_export_code}" == 403 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM admin_audit_events
     WHERE event_type = 'message.owner_export' AND related_id = ${message_id};")" == 0 ]]

export_headers="${tmp_dir}/owner-export-headers.txt"
export_body="${tmp_dir}/owner-export.json"
export_code="$(curl --silent --show-error \
    --output "${export_body}" \
    --write-out '%{http_code}' \
    --dump-header "${export_headers}" \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${manage_csrf}" \
    --data-urlencode "owner_csrf=${owner_csrf}" \
    --data-urlencode 'action=owner_export' \
    "${APP_ORIGIN}${manage_path}")"
[[ "${export_code}" == 200 ]]
grep --ignore-case --quiet '^Content-Type: application/json; charset=utf-8' "${export_headers}"
grep --ignore-case --quiet '^Cache-Control: private, no-store, max-age=0' "${export_headers}"
grep --ignore-case --quiet "^Content-Disposition: attachment; filename=\"micutu-guestbook-message-${message_id}.json\"" "${export_headers}"
grep --ignore-case --quiet "^Content-Security-Policy: default-src 'none'; sandbox" "${export_headers}"
grep --ignore-case --quiet '^X-Content-Type-Options: nosniff' "${export_headers}"
# The PHP snippet is intentionally single-quoted shell input.
# shellcheck disable=SC2016
php -r '
    $document = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $message = $document["message"] ?? null;
    $ok = array_keys($document) === ["export_version", "exported_at", "message"]
        && $document["export_version"] === "1.0"
        && is_string($document["exported_at"])
        && array_keys($message ?? []) === ["id", "author", "content", "submitted_at", "status"]
        && $message["id"] === (int) $argv[2]
        && $message["author"] === "Alice"
        && $message["content"] === "Hello from the isolated smoke test"
        && str_ends_with($message["submitted_at"], "Z")
        && $message["status"] === "published";
    exit($ok ? 0 : 1);
' "${export_body}" "${message_id}"
if grep --extended-regexp --quiet 'ip_hash|content_fingerprint|risk_score|risk_flags|owner_token|moderation_reason' "${export_body}"; then
    echo 'Owner export exposed internal moderation or capability material.' >&2
    exit 1
fi
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM security_events
     WHERE event_type = 'message.owner_export' AND outcome = 'success'
       AND related_id = ${message_id} AND subject_hash IS NULL;")" == 1 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM admin_audit_events
     WHERE event_type = 'message.owner_export' AND outcome = 'success'
       AND related_id = ${message_id};")" == 1 ]]

withdraw_headers="${tmp_dir}/withdraw-headers.txt"
withdraw_code="$(request_code \
    --cookie "${cookie_jar}" \
    --cookie-jar "${cookie_jar}" \
    --dump-header "${withdraw_headers}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${manage_csrf}" \
    --data-urlencode "owner_csrf=${owner_csrf}" \
    --data-urlencode 'action=owner_remove' \
    "${APP_ORIGIN}${manage_path}")"
[[ "${withdraw_code}" == 303 ]]
grep --ignore-case --quiet '^Location: /' "${withdraw_headers}"
grep --ignore-case --quiet "^Set-Cookie: gbowner_${message_id}=deleted;.*Max-Age=0;.*HttpOnly.*SameSite=Strict" "${withdraw_headers}"
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT status || ':' || moderation_reason || ':' || (owner_withdrawn_at IS NOT NULL) || ':' ||
            (owner_token_hash IS NULL) || ':' || (ip_hash IS NULL) || ':' ||
            (content_fingerprint IS NULL) || ':' || (featured_at IS NULL)
     FROM messages WHERE id = ${message_id};")" == deleted:requested:1:1:1:1:1 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM security_events
     WHERE event_type = 'message.owner_remove' AND outcome = 'success'
       AND related_id = ${message_id} AND subject_hash IS NULL;")" == 1 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" \
    "SELECT COUNT(*) FROM admin_audit_events
     WHERE event_type = 'message.delete' AND related_id = ${message_id}
       AND previous_state = 'published' AND new_state = 'deleted'
       AND reason_code = 'requested';")" == 1 ]]
[[ "$(request_code "${APP_ORIGIN}/messages/${message_id}")" == 404 ]]
[[ "$(request_code --cookie "${cookie_jar}" "${APP_ORIGIN}${manage_path}")" == 404 ]]
if curl --silent --show-error "${APP_ORIGIN}/sitemap.xml" | grep --fixed-strings --quiet "/messages/${message_id}" \
    || curl --silent --show-error "${APP_ORIGIN}/feed.xml" | grep --fixed-strings --quiet 'Hello from the isolated smoke test' \
    || curl --silent --show-error "${APP_ORIGIN}/feed.json" | grep --fixed-strings --quiet 'Hello from the isolated smoke test' \
    || curl --silent --show-error "${APP_ORIGIN}/api/v1/messages" | grep --fixed-strings --quiet 'Hello from the isolated smoke test'; then
    echo 'Owner-withdrawn content remained on a public discovery surface.' >&2
    exit 1
fi

admin_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
grep --fixed-strings --quiet 'Withdrawn by its author · cannot be republished.' <<<"${admin_html}"
admin_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${admin_html}" | head -n 1)"
restore_withdrawn_code="$(request_code \
    --cookie "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${admin_csrf}" \
    --data-urlencode 'action=moderate' \
    --data-urlencode "id=${message_id}" \
    --data-urlencode 'status=published' \
    --data-urlencode 'reason=restored' \
    "${APP_ORIGIN}/")"
[[ "${restore_withdrawn_code}" == 303 ]]
[[ "$(sqlite3 "${APP_STORAGE_DIR}/database.sqlite" "SELECT status FROM messages WHERE id = ${message_id};")" == deleted ]]

expiring_session_id="$(cookie_value "${cookie_jar}" gbsid)"
[[ -n "${expiring_session_id}" && -f "${web_session_dir}/sess_${expiring_session_id}" ]]
# The snippet edits only the isolated smoke-test session to simulate 30 minutes idle.
# shellcheck disable=SC2016
php -d "session.save_path=${web_session_dir}" -d session.use_strict_mode=1 -r '
    session_name("gbsid");
    session_id($argv[1]);
    session_start();
    $_SESSION["admin_authenticated_at"] = time() - 1901;
    $_SESSION["admin_last_seen_at"] = time() - 1901;
    session_write_close();
' "${expiring_session_id}"
expired_headers="${tmp_dir}/expired-session-headers.txt"
expired_html="$(curl --silent --show-error \
    --dump-header "${expired_headers}" \
    --cookie "${cookie_jar}" \
    --cookie-jar "${cookie_jar}" \
    "${APP_ORIGIN}/")"
grep --fixed-strings --quiet 'Your admin session expired. Please sign in again.' <<<"${expired_html}"
if grep --fixed-strings --quiet 'Sign out' <<<"${expired_html}"; then
    echo 'Expired admin session retained its privilege.' >&2
    exit 1
fi
renewed_anonymous_id="$(cookie_value "${cookie_jar}" gbsid)"
[[ -n "${renewed_anonymous_id}" && "${renewed_anonymous_id}" != "${expiring_session_id}" ]]
[[ ! -e "${web_session_dir}/sess_${expiring_session_id}" ]]
grep --ignore-case --quiet '^Set-Cookie: gbsid=' "${expired_headers}"

expired_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${expired_html}" | head -n 1)"
[[ "${expired_csrf}" =~ ^[0-9a-f]{64}$ && "${expired_csrf}" != "${admin_csrf}" ]]
relogin_code="$(request_code \
    --cookie "${cookie_jar}" \
    --cookie-jar "${cookie_jar}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${expired_csrf}" \
    --data-urlencode 'action=login' \
    --data-urlencode 'password=smoke-test-password' \
    "${APP_ORIGIN}/")"
[[ "${relogin_code}" == 303 ]]
logout_session_id="$(cookie_value "${cookie_jar}" gbsid)"
[[ -n "${logout_session_id}" && "${logout_session_id}" != "${renewed_anonymous_id}" ]]
[[ ! -e "${web_session_dir}/sess_${renewed_anonymous_id}" ]]

logout_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
logout_csrf="$(sed -n 's/.*name="csrf" value="\([0-9a-f]\{64\}\)".*/\1/p' <<<"${logout_html}" | head -n 1)"
[[ "${logout_csrf}" =~ ^[0-9a-f]{64}$ ]]
logout_headers="${tmp_dir}/logout-headers.txt"
logout_code="$(request_code \
    --cookie "${cookie_jar}" \
    --cookie-jar "${cookie_jar}" \
    --dump-header "${logout_headers}" \
    --header "Origin: ${APP_ORIGIN}" \
    --data-urlencode "csrf=${logout_csrf}" \
    --data-urlencode 'action=logout' \
    "${APP_ORIGIN}/")"
[[ "${logout_code}" == 303 ]]
post_logout_session_id="$(cookie_value "${cookie_jar}" gbsid)"
[[ -n "${post_logout_session_id}" && "${post_logout_session_id}" != "${logout_session_id}" ]]
[[ ! -e "${web_session_dir}/sess_${logout_session_id}" ]]
grep --ignore-case --quiet '^Set-Cookie: gbsid=' "${logout_headers}"
signed_out_html="$(curl --silent --show-error --cookie "${cookie_jar}" "${APP_ORIGIN}/")"
grep --fixed-strings --quiet 'Signed out.' <<<"${signed_out_html}"
if grep --fixed-strings --quiet 'Sign out' <<<"${signed_out_html}"; then
    echo 'Logged-out session retained its privilege.' >&2
    exit 1
fi

echo 'HTTP smoke tests passed.'
