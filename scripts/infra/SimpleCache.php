<?php
/**
 * 简单文件缓存类 - 最低成本优化方案
 */

if (isset($_SERVER['SCRIPT_FILENAME']) && ($scriptFile = realpath((string)$_SERVER['SCRIPT_FILENAME'])) && $scriptFile === __FILE__) {
    http_response_code(404);
    exit;
}

class SimpleCache {
    private static $cacheDir = null;

    private static function getCacheDir() {
        if (self::$cacheDir === null) {
            self::$cacheDir = defined('PRIVATE_CACHE_PATH') ? PRIVATE_CACHE_PATH : (ROOT_PATH . '/cache');
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0775, true);
                @chmod(self::$cacheDir, 0775);
            }
        }
        return self::$cacheDir;
    }

    /**
     * 分片路径：md5(key) 前两位作为子目录
     * 把 5M+ 平铺文件改成 256 个子目录平均承载，避免 dentry 缓存抖动。
     */
    private static function shardedPath(string $hash): string {
        $bucket = substr($hash, 0, 2);
        $dir = self::getCacheDir() . '/' . $bucket;
        if (!is_dir($dir)) {
            // 显式 chmod 防 umask 把 0775 缩成 0755（之前外部 root 创建的桶就是这种 trap）
            @mkdir($dir, 0775, true);
            @chmod($dir, 0775);
        }
        return $dir . '/' . $hash . '.cache';
    }

    /**
     * 读时同时尝试新分片路径与旧平铺路径，迁移期无缝兼容。
     * 返回首个存在的路径与 mode 标识。
     */
    private static function resolveReadPath(string $hash): ?string {
        $sharded = self::getCacheDir() . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
        if (is_file($sharded)) return $sharded;
        $legacy = self::getCacheDir() . '/' . $hash . '.cache';
        if (is_file($legacy)) return $legacy;
        return null;
    }

    /**
     * 获取缓存
     */
    public static function get($key, $default = null) {
        $hash = md5($key);
        $file = self::resolveReadPath($hash);
        if ($file === null) {
            return $default;
        }

        $data = @file_get_contents($file);
        if ($data === false || $data === '') {
            @unlink($file);
            return $default;
        }

        $cache = @unserialize($data);
        if (!is_array($cache) || !array_key_exists('expire', $cache) || !array_key_exists('data', $cache)) {
            @unlink($file);
            return $default;
        }

        // 检查是否过期
        if ((int)$cache['expire'] > 0 && time() > (int)$cache['expire']) {
            @unlink($file);
            return $default;
        }

        return $cache['data'];
    }

    /**
     * 设置缓存
     */
    public static function set($key, $data, $ttl = 3600) {
        $hash = md5($key);
        $file = self::shardedPath($hash);

        $cache = [
            'data' => $data,
            'expire' => $ttl > 0 ? time() + $ttl : 0,
            'created' => time()
        ];

        // 原子写：先写临时文件再 rename，避免读到半截
        $tmp = $file . '.tmp.' . getmypid();
        if (file_put_contents($tmp, serialize($cache), LOCK_EX) !== false) {
            @rename($tmp, $file);
            // 顺手清掉旧平铺路径，单点向新路径收敛
            $legacy = self::getCacheDir() . '/' . $hash . '.cache';
            if (is_file($legacy)) @unlink($legacy);
        }
    }

    /**
     * 删除缓存
     */
    public static function delete($key) {
        $hash = md5($key);
        @unlink(self::getCacheDir() . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache');
        @unlink(self::getCacheDir() . '/' . $hash . '.cache');
    }

    /**
     * 清空所有缓存
     */
    public static function clear() {
        $dir = self::getCacheDir();
        // 顶层平铺
        foreach (glob($dir . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
        // 分片目录
        foreach (glob($dir . '/[0-9a-f][0-9a-f]/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * 清理过期/陈旧缓存（GC）。返回清理数量。
     * @param int $maxAge 文件 mtime 超过该秒数即清理（默认 30 天）
     */
    public static function gc(int $maxAge = 2592000): int {
        $dir = self::getCacheDir();
        $now = time();
        $deleted = 0;
        $sweep = static function (string $path) use (&$deleted, $now, $maxAge): void {
            if (!is_file($path)) return;
            $mtime = @filemtime($path);
            if ($mtime !== false && ($now - $mtime) > $maxAge) {
                if (@unlink($path)) $deleted++;
            }
        };
        // 顶层平铺
        foreach (glob($dir . '/*.cache') ?: [] as $f) $sweep($f);
        // 分片
        foreach (glob($dir . '/[0-9a-f][0-9a-f]/*.cache') ?: [] as $f) $sweep($f);
        return $deleted;
    }

    /**
     * 记住缓存（如果不存在则通过回调生成）
     *
     * 支持 stale-while-revalidate：
     *   - $ttl 内：直接返回（fresh）
     *   - $ttl 过期但 $staleGrace 秒内：返回旧数据，同时单进程后台刷新
     *     （并发时只有一个进程拿到刷新锁，其余继续读 stale，避免击穿尖峰）
     *   - 超过 $staleGrace：同步重建
     *
     * @param string   $key
     * @param callable $callback
     * @param int      $ttl         数据 fresh 时长（秒），默认 3600
     * @param int      $staleGrace  fresh 过期后允许返回 stale 的时长（秒），默认 60；设为 0 关闭 SWR
     */
    public static function remember($key, $callback, $ttl = 3600, $staleGrace = 60) {
        $hash = md5($key);
        $file = self::resolveReadPath($hash);

        if ($file !== null) {
            $raw = @file_get_contents($file);
            if ($raw !== false && $raw !== '') {
                $cache = @unserialize($raw);
                if (is_array($cache)
                    && array_key_exists('data', $cache)
                    && array_key_exists('expire', $cache)) {
                    $now = time();
                    $expire = (int)$cache['expire'];

                    // 1. fresh：永不过期 或 仍在 fresh 期内
                    if ($expire === 0 || $now <= $expire) {
                        return $cache['data'];
                    }

                    // 2. stale-while-revalidate：fresh 过期但仍在 grace 期内
                    if ($staleGrace > 0 && ($now - $expire) <= $staleGrace) {
                        // 用 lockfile 实现单写者：只让一个进程刷新，其余直接读 stale
                        // lock 与 cache 同目录（同一分片桶），避免新建独立锁目录
                        $lockFile = $file . '.lock';
                        $fp = @fopen($lockFile, 'c');
                        if ($fp && @flock($fp, LOCK_EX | LOCK_NB)) {
                            try {
                                $fresh = call_user_func($callback);
                                self::set($key, $fresh, $ttl);
                                @flock($fp, LOCK_UN);
                                @fclose($fp);
                                @unlink($lockFile);
                                return $fresh;
                            } catch (\Throwable $e) {
                                // 刷新失败：保留 stale，继续返回旧数据
                                @flock($fp, LOCK_UN);
                                @fclose($fp);
                                @unlink($lockFile);
                                error_log('[SimpleCache] swr refresh failed key=' . $key . ' err=' . $e->getMessage());
                                return $cache['data'];
                            }
                        }
                        // 没拿到锁：另一个进程正在刷，本进程直接返回 stale
                        if ($fp) @fclose($fp);
                        return $cache['data'];
                    }
                    // 超过 grace：丢弃 stale，落到同步重建
                }
            }
            // 损坏文件：清掉
            @unlink($file);
        }

        // miss / 完全过期：同步重建
        $data = call_user_func($callback);
        self::set($key, $data, $ttl);
        return $data;
    }
}
