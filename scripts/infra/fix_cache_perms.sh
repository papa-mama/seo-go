#!/bin/bash
# 修复 SimpleCache 分片目录权限。
# 历史问题：migrate_cache_shards.php / 其他 root 维护脚本会让 256 个 shard 桶
#           归 root:root 755，导致 PHP-FPM (www 用户) 写 cache::set 时 Permission denied。
# 影响：所有 SimpleCache::remember / set 静默失败，cache 永远 miss，性能优化失效。
# 用法：sudo bash scripts/fix_cache_perms.sh
set -e

CACHE_DIR="${GUONIKA_CACHE_DIR:-/tmp/guonika-runtime/cache}"
WEB_USER="${WEB_USER:-www}"
WEB_GROUP="${WEB_GROUP:-www}"

if [ ! -d "$CACHE_DIR" ]; then
    echo "[fix-cache-perms] cache dir missing: $CACHE_DIR"
    exit 0
fi

echo "[fix-cache-perms] chown -R ${WEB_USER}:${WEB_GROUP} $CACHE_DIR"
chown -R "${WEB_USER}:${WEB_GROUP}" "$CACHE_DIR"

echo "[fix-cache-perms] chmod 775 on all shard dirs"
find "$CACHE_DIR" -type d -exec chmod 775 {} \;

ROOT_LEFT=$(find "$CACHE_DIR" -uid 0 2>/dev/null | wc -l)
echo "[fix-cache-perms] root-owned items remaining: $ROOT_LEFT"
echo "[fix-cache-perms] done."
