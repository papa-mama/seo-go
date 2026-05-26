# Playbook 01 — Cache Foundation

> **所有性能优化的地基。其他 playbook 跑得快不快取决于这个。**

源参考：`references/cache-perms-trap.md`（必读，含血泪复盘）。

---

## 目标

把站从"无 cache 或 cache 静默失败"改造成：
- 256 桶分片文件缓存（写入分散，避免 dentry 抖动）
- SWR（stale-while-revalidate）默认 60s（grace 期内单写者，其余读 stale）
- 持续观测：每 5 分钟 cache_pulse，每 10 分钟 warmup
- 任何 root 操作完自动修权限

参考实现位于 `scripts/infra/`：
- `SimpleCache.php` — 缓存类本体
- `migrate_cache_shards.php` — 老平铺改分片 + GC
- `fix_cache_perms.sh` — 权限修复（必跑）
- `warmup_caches.php` — 预热脚本（cron 用）
- `cache_pulse.php`（在 audit/ 下）— 观测脚本

---

## Step 1：选 cache 路径

```
PRIVATE_CACHE_PATH = /tmp/<project>-runtime/cache
```

**为什么用 `/tmp` 子目录而不是项目内 `cache/`：**
1. 项目目录被 git tracked，缓存写入容易污染 working tree
2. `/tmp` 是 tmpfs（部分系统）天生快
3. SELinux / open_basedir 限制少

**为什么用 `<project>-runtime` 子目录而不是直接 `/tmp`：**
- `/tmp` 会被系统定期清理（systemd-tmpfiles），子目录可加保留规则
- 多项目并存时不冲突

在 `config.php` 顶部定义：

```php
define('PRIVATE_CACHE_PATH', '/tmp/' . PROJECT_SLUG . '-runtime/cache');
if (!is_dir(PRIVATE_CACHE_PATH)) {
    @mkdir(PRIVATE_CACHE_PATH, 0775, true);
    @chmod(PRIVATE_CACHE_PATH, 0775);
}
```

---

## Step 2：放 SimpleCache.php

复制 `scripts/infra/SimpleCache.php` 到 `includes/cache.php`。
**关键设计点（不要改）：**

1. **shardedPath()**：md5(key) 前 2 位作子目录 → 256 桶。
2. **resolveReadPath()**：先读 sharded → 回退 legacy。迁移期无缝兼容。
3. **set()**：原子写（写 .tmp + rename）+ set 时清掉旧 legacy（向新路径自然收敛）。
4. **remember()**：4 参签名 `($key, $callback, $ttl, $staleGrace=60)`，**默认开 SWR**。
5. **每次 mkdir 后显式 chmod 0775**。不依赖 umask（umask 会把 0775 砍成 0755 → www 写不进，触发 cache-perms-trap）。

---

## Step 3：跑迁移（如果有旧平铺缓存）

```bash
# 先 dry-run 看会动多少
php scripts/infra/migrate_cache_shards.php --dry-run --limit=10000

# 真跑
php scripts/infra/migrate_cache_shards.php --max-age=2592000

# 跑完立刻修权限（重要！如果上面是 root 跑的）
sudo bash scripts/infra/fix_cache_perms.sh
```

---

## Step 4：埋 cache 进高频路径

**埋哪：** 任何 listing 页 / detail 页里的：
- COUNT(*) 之类的统计 SQL
- 热门排行 / 推荐位 / 侧栏聚合
- 跨表 JOIN 的 LIMIT N 查询

**怎么埋：**

```php
// 改前
$rows = $db->fetchAll("SELECT ... FROM products WHERE ... LIMIT 12");

// 改后
$rows = SimpleCache::remember(
    'products_list_v1_' . md5($queryKey),  // 命名: <模块>_<语义>_v<版本>_<参数hash>
    function () use ($db, ...) {
        return $db->fetchAll("SELECT ... FROM products WHERE ... LIMIT 12");
    },
    600,   // TTL 10 min
    60     // SWR grace 60s
);
```

### 命名约束（必须）

`<module>_<purpose>_v<n>[_<param_hash>]`

例：
- `home_quote_cards_v1`
- `pc_trade_stats_v1_2026-05-26`
- `news_detail_quote_pool_v1`

**bump v 是唯一的强制穿透方式**。改 SQL / 改字段 → bump v → 老缓存自然过期。

---

## Step 5：装观测（不挂会盲飞）

### 5.1 cache_pulse.php

复制 `scripts/audit/cache_pulse.php`，跑一次：

```bash
php scripts/audit/cache_pulse.php
```

输出：shard 总数 / root_owned / writable + watch keys 命中状态 + 8 个 URL 的 cold/hot 延迟，写入 `logs/cache_pulse.log`。

### 5.2 warmup_caches.php

复制 `scripts/infra/warmup_caches.php`，按项目改 URL 列表。**必须包括：**
- 首页 / 列表页 / 列表页第二页
- 至少一个详情页（让 detail 内的共享 quote_pool 类 key 重建）

### 5.3 cron 挂法（建议给用户，不要自动改 crontab）

```cron
*/5 * * * * cd /path/to/project && php scripts/audit/cache_pulse.php >/dev/null 2>&1
*/10 * * * * cd /path/to/project && php scripts/infra/warmup_caches.php >/dev/null 2>&1
0 4 * * * cd /path/to/project && sudo bash scripts/infra/fix_cache_perms.sh >> logs/fix_cache_perms.log 2>&1
```

---

## 验收标准

跑完一轮后：

```bash
php scripts/audit/cache_pulse.php
```

应满足：
- `shards: total=256 root_owned=0 writable=256`
- 4 个 watch key 全 HIT，shard=sharded
- 列表页 hot < 50ms，详情页 hot < 100ms
- `tail /var/log/nginx/error.log | grep "Permission denied" | wc -l` = 0

---

## 常见坑（必读）

### 坑 1：root 跑过 migrate / 手动 mkdir → www 写不进

症状：
- nginx error.log 海量 `Permission denied`
- cache 永远 MISS，看起来 cache 没生效

判断：
```bash
find $CACHE_DIR -type d -uid 0 | wc -l  # 应 = 0
```

修：`sudo bash scripts/infra/fix_cache_perms.sh`

完整复盘：`references/cache-perms-trap.md`。

### 坑 2：依赖 umask

`mkdir 0775` 在某些 nginx 启动 user 配置下会被 umask 022 砍成 0755 → www 不在 group 也写不进。
**所有 mkdir 后必须显式 `chmod 0775`。** SimpleCache 已经这么做。

### 坑 3：没 SWR 导致 cold 抖动

`remember()` 不传第 4 参 → grace=60s（默认开）。**不要传 0 关掉**，除非数据强一致需求（订单 / 余额）。

---

## 不要做的事

- ❌ 不要用 Redis/Memcached 替代 SimpleCache 除非用户明确要 — 多一个组件多一个故障点，文件缓存对绝大多数 B2B / 资讯站够用
- ❌ 不要 cache 用户态数据（购物车 / session）— SimpleCache 是站点级缓存
- ❌ 不要 cache 写入操作的结果 — 只 cache GET 类的读
