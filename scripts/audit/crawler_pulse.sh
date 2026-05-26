#!/usr/bin/env bash
# crawler_pulse.sh — 实时统计今日爬虫情况，每次跑只读今天日志的一小窗
# 用法：
#   bash scripts/crawler_pulse.sh                # 单次快照
#   bash scripts/crawler_pulse.sh --window=10000 # 取最近 N 行做样本
#   bash scripts/crawler_pulse.sh --tail-N=5     # 显示前 N 行最近记录
#
# 输出：bot 命中数 + URL 类型分布 + status 分布
# 写入：runtime/crawler_pulse_<date>.log（append-only）

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOG="${LOG:-/www/wwwlogs/guonika.com.log}"
WINDOW=${WINDOW:-50000}
DATE_TAG=$(date +%Y%m%d)
OUT_DIR="$ROOT/runtime"
OUT="$OUT_DIR/crawler_pulse_${DATE_TAG}.log"
mkdir -p "$OUT_DIR"

ts() { date +"%Y-%m-%d %H:%M:%S"; }

# 解析参数
for arg in "$@"; do
  case "$arg" in
    --window=*) WINDOW="${arg#--window=}" ;;
    --tail-N=*) TAIL_N="${arg#--tail-N=}" ;;
  esac
done

if [ ! -r "$LOG" ]; then
  echo "[$(ts)] ERROR: cannot read $LOG (is www the right user? try sudo)" | tee -a "$OUT"
  exit 1
fi

# 取尾部 WINDOW 行做窗口
TMP=$(mktemp /tmp/cpulse.XXXXXX)
trap 'rm -f "$TMP"' EXIT
tail -n "$WINDOW" "$LOG" > "$TMP"
TOTAL=$(wc -l < "$TMP")

# bot 命中（UA 关键词）
BAIDU=$(grep -ciE "Baiduspider|baidu\.com\)" "$TMP")
GOOGLE=$(grep -ciE "Googlebot|Mediapartners-Google" "$TMP")
BING=$(grep -ciE "bingbot|msnbot" "$TMP")
SOGOU=$(grep -ciE "Sogou web spider" "$TMP")
SO360=$(grep -ciE "360Spider|HaosouSpider" "$TMP")
YANDEX=$(grep -ciE "YandexBot|YandexImages" "$TMP")
GPTBOT=$(grep -ciE "GPTBot|ChatGPT-User" "$TMP")
CLAUDEBOT=$(grep -ciE "ClaudeBot|anthropic-ai" "$TMP")
PERPLEXITY=$(grep -ciE "PerplexityBot" "$TMP")
META=$(grep -ciE "meta-externalagent|FacebookBot|Bytespider" "$TMP")
BYTESPIDER=$(grep -ciE "Bytespider" "$TMP")

# 全部"AI/搜索引擎 bot" 命中
ALLBOT=$(grep -ciE "spider|bot|crawl|gptbot|chatgpt|claudebot|perplexity|bytespider|yandex|sogou" "$TMP")

# URL 类型分布（爬虫只看路径前缀）
BOT_LINES=$(grep -iE "spider|bot|crawl|gptbot|chatgpt|claudebot|perplexity|bytespider|yandex|sogou" "$TMP" || true)
URL_TOPIC=$(echo "$BOT_LINES" | grep -cE 'GET /topics/' || true)
URL_TRADE=$(echo "$BOT_LINES" | grep -cE 'GET /trade' || true)
URL_PRODUCT=$(echo "$BOT_LINES" | grep -cE 'GET /product' || true)
URL_NEWS=$(echo "$BOT_LINES" | grep -cE 'GET /news|content\.html' || true)
URL_HOME=$(echo "$BOT_LINES" | grep -cE 'GET / HTTP' || true)
URL_SITEMAP=$(echo "$BOT_LINES" | grep -cE 'GET /sitemap' || true)
URL_ROBOTS=$(echo "$BOT_LINES" | grep -cE 'GET /robots' || true)

# topics 子目录细分
URL_T_FLAGSHIP=$(echo "$BOT_LINES" | grep -cE 'GET /topics/flagship/' || true)
URL_T_GEO=$(echo "$BOT_LINES" | grep -cE 'GET /topics/geo/' || true)
URL_T_QUERIES=$(echo "$BOT_LINES" | grep -cE 'GET /topics/queries/' || true)
URL_T_QUOTES=$(echo "$BOT_LINES" | grep -cE 'GET /topics/quotes/' || true)
URL_T_CATEGORIES=$(echo "$BOT_LINES" | grep -cE 'GET /topics/categories/' || true)

# status 分布
S200=$(echo "$BOT_LINES" | grep -cE '" 200 ' || true)
S301=$(echo "$BOT_LINES" | grep -cE '" 30[12] ' || true)
S404=$(echo "$BOT_LINES" | grep -cE '" 404 ' || true)
S5XX=$(echo "$BOT_LINES" | grep -cE '" 5[0-9][0-9] ' || true)

# 输出
{
  echo "==== $(ts) WINDOW=$WINDOW ===="
  echo "  total lines in window: $TOTAL"
  echo "  -- bot hits (UA match) --"
  printf "    %-14s %s\n" baidu "$BAIDU"
  printf "    %-14s %s\n" google "$GOOGLE"
  printf "    %-14s %s\n" bing "$BING"
  printf "    %-14s %s\n" sogou "$SOGOU"
  printf "    %-14s %s\n" "360/haosou" "$SO360"
  printf "    %-14s %s\n" yandex "$YANDEX"
  printf "    %-14s %s\n" gptbot "$GPTBOT"
  printf "    %-14s %s\n" claudebot "$CLAUDEBOT"
  printf "    %-14s %s\n" perplexity "$PERPLEXITY"
  printf "    %-14s %s\n" bytespider "$BYTESPIDER"
  printf "    %-14s %s\n" "meta/fb" "$META"
  printf "    %-14s %s\n" all_bots "$ALLBOT"
  echo "  -- URL types (bot only) --"
  printf "    %-14s %s\n" /topics/ "$URL_TOPIC"
  printf "    %-16s %s\n" "  flagship" "$URL_T_FLAGSHIP"
  printf "    %-16s %s\n" "  geo" "$URL_T_GEO"
  printf "    %-16s %s\n" "  queries" "$URL_T_QUERIES"
  printf "    %-16s %s\n" "  quotes" "$URL_T_QUOTES"
  printf "    %-16s %s\n" "  categories" "$URL_T_CATEGORIES"
  printf "    %-14s %s\n" /trade "$URL_TRADE"
  printf "    %-14s %s\n" /product "$URL_PRODUCT"
  printf "    %-14s %s\n" /news "$URL_NEWS"
  printf "    %-14s %s\n" "/ (home)" "$URL_HOME"
  printf "    %-14s %s\n" /sitemap "$URL_SITEMAP"
  printf "    %-14s %s\n" /robots "$URL_ROBOTS"
  echo "  -- status (bot only) --"
  printf "    %-14s %s\n" 200 "$S200"
  printf "    %-14s %s\n" 30x "$S301"
  printf "    %-14s %s\n" 404 "$S404"
  printf "    %-14s %s\n" 5xx "$S5XX"
} | tee -a "$OUT"

if [ -n "${TAIL_N:-}" ] && [ "$TAIL_N" -gt 0 ]; then
  echo ""
  echo "  -- last $TAIL_N bot hits --"
  echo "$BOT_LINES" | tail -n "$TAIL_N"
fi
