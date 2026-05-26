# Cache Perms Trap（SimpleCache 权限坑完整复盘）

> **这是 guonika 性能优化最大的坑。一年里所有"看起来 cache 没生效"问题 90% 都是这个。**

---

## 现象

- API / 列表页延迟看起来正常（200-500ms）
- 看似 cache 已经埋好（`SimpleCache::remember(...)`）
- 但 `tail nginx error.log` 海量 `Permission denied`
- `ls $CACHE_DIR/<bucket>/` 文件 mtime 永远是几小时前的（写不进去）

---

## 根因

PHP-FPM 跑在 `www` 用户，但 `/tmp/<project>-runtime/cache/<2 位 hex 桶>` 子目录归 `root:root 0755`。

**怎么变成 root 的：**
- 用 `sudo php scripts/migrate_cache_shards.php` 跑过迁移
- 或 `sudo mkdir /tmp/<project>-runtime/cache/00`
- 或 `sudo php scripts/<任何创建桶的脚本>`

**为什么 `mkdir 0775` 没有救：**
- umask 022 把 0775 砍成 0755
- 即使桶组是 www，模式 0755 不允许 group 写
- `file_put_contents()` 静默失败，PHP 只 `error_log` 不抛异常

---

## 症状识别（30 秒判断）

```bash
# A. shard 桶里有 root-owned 的吗？
find $CACHE_DIR -type d -uid 0 | wc -l       # 必须 = 0

# B. nginx error log 最近有 Permission denied 吗？
tail -1000 /var/log/nginx/<domain>.error.log | grep -c "Permission denied"   # 必须 = 0

# C. cache 命中率？
php scripts/audit/cache_pulse.php
# 看 watch keys：MISS 的 key = 业务路径或权限有问题
```

A、B 任一 > 0 → 命中本坑。

---

## 修复（一次性）

```bash
sudo bash scripts/infra/fix_cache_perms.sh
```

实际做的事：

```bash
chown -R www:www $CACHE_DIR
find $CACHE_DIR -type d -exec chmod 775 {} \;
```

---

## 预防（永久）

### 1. SimpleCache.php 必须显式 chmod

每次 `mkdir` 后立刻 `chmod 0775`：

```php
private static function shardedPath(string $hash): string {
    $bucket = substr($hash, 0, 2);
    $dir = self::getCacheDir() . '/' . $bucket;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @chmod($dir, 0775);    // 必须！不能依赖 umask
    }
    return $dir . '/' . $hash . '.cache';
}
```

### 2. 任何外部脚本创建桶都要 chmod

`migrate_cache_shards.php` / `clear_cache.php` / 任何手动 mkdir 都要：

```php
@mkdir($bucketDir, 0775, true);
@chmod($bucketDir, 0775);
```

### 3. 任何 root 跑过 cache 脚本，跑完立刻修权限

```bash
sudo php scripts/<anything that touches cache>
sudo bash scripts/infra/fix_cache_perms.sh
```

### 4. 监控（cache_pulse 每 5 min）

`cache_pulse.php` 输出 `root_owned > 0` 时会打 WARNING，cron 邮件能收到。

---

## 完整时间线（guonika 实际事故）

```
2026-05-25 21:00  跑 sudo php scripts/migrate_cache_shards.php
                  → 创建 256 个 root:root 0755 桶
2026-05-25 21:05  之后所有 SimpleCache::set 全部静默失败
2026-05-26 09:30  发现 API q=阀门 测了 500ms 三次仍不命中
2026-05-26 09:45  tail nginx error log → 海量 Permission denied
2026-05-26 09:50  跑 fix_cache_perms.sh → 立刻所有 cache 命中
2026-05-26 10:00  写入 SimpleCache.php 显式 chmod 防御
2026-05-26 10:30  写 cache_pulse.php 持续监控
2026-05-26 11:00  保存 memory project_cache_perms_bug.md
```

---

## 为什么这个坑值得单独一篇 reference

因为它具备"最难诊断的 bug"全部特征：
1. 不抛异常 — `@file_put_contents` 失败只 error_log
2. 性能数字看起来正常 — InnoDB buffer / OPcache 帮忙撑场面
3. 没有自动化报警 — 直到 7 天 hot_avg 偷偷涨 80% 才发现
4. 修复路径可逆 — chown + chmod 一次性搞定，但容易再次踩入

**记住：cache 不是"埋了就行"，必须验证它真的写进去了。**
