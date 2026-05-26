<?php
/**
 * 缓存目录迁移：把 PRIVATE_CACHE_PATH 顶层平铺的 .cache 文件
 * 移到对应的两位 hash 子目录下，让 SimpleCache 的分片读路径生效。
 *
 * 安全策略：
 *  - 只移动文件名严格匹配 /^[0-9a-f]{32}\.cache$/ 的，避免误伤
 *  - 目标已存在则跳过（保留较新的那个）
 *  - 顺手 GC 掉超过 maxAge 的旧文件
 *
 * 用法：
 *   php scripts/migrate_cache_shards.php --max-age=2592000          # 默认 30 天
 *   php scripts/migrate_cache_shards.php --max-age=604800 --limit=200000
 *   php scripts/migrate_cache_shards.php --dry-run --limit=1000
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$opts = getopt('', ['max-age::', 'limit::', 'dry-run']);
$maxAge = isset($opts['max-age']) ? (int)$opts['max-age'] : 2592000;
$limit  = isset($opts['limit']) ? (int)$opts['limit'] : PHP_INT_MAX;
$dryRun = isset($opts['dry-run']);

$dir = PRIVATE_CACHE_PATH;
if (!is_dir($dir)) {
    fwrite(STDERR, "cache dir not found: $dir\n");
    exit(1);
}

$now = time();
$stats = ['scanned' => 0, 'moved' => 0, 'gc' => 0, 'collide' => 0, 'skip_format' => 0];

$dh = opendir($dir);
if (!$dh) {
    fwrite(STDERR, "cannot open dir\n");
    exit(2);
}

$t0 = microtime(true);
while (($name = readdir($dh)) !== false) {
    if ($stats['scanned'] >= $limit) break;
    if ($name[0] === '.') continue;
    $full = $dir . '/' . $name;
    if (!is_file($full)) continue;
    $stats['scanned']++;

    if (!preg_match('/^([0-9a-f]{32})\.cache$/', $name, $m)) {
        $stats['skip_format']++;
        continue;
    }
    $hash = $m[1];

    // GC 优先
    $mtime = @filemtime($full);
    if ($mtime !== false && $maxAge > 0 && ($now - $mtime) > $maxAge) {
        if ($dryRun || @unlink($full)) $stats['gc']++;
        if ($stats['scanned'] % 50000 === 0) {
            echo "  scan={$stats['scanned']} moved={$stats['moved']} gc={$stats['gc']}\n";
        }
        continue;
    }

    $bucket = substr($hash, 0, 2);
    $bucketDir = $dir . '/' . $bucket;
    if (!is_dir($bucketDir)) {
        if (!$dryRun) {
            @mkdir($bucketDir, 0775, true);
            @chmod($bucketDir, 0775);
        }
    }
    $target = $bucketDir . '/' . $name;
    if (is_file($target)) {
        // 目标已有：保留较新者
        if (!$dryRun) {
            $tmtime = @filemtime($target) ?: 0;
            if ($mtime !== false && $mtime > $tmtime) {
                @rename($full, $target);
            } else {
                @unlink($full);
            }
        }
        $stats['collide']++;
    } else {
        if (!$dryRun) @rename($full, $target);
        $stats['moved']++;
    }

    if ($stats['scanned'] % 50000 === 0) {
        echo "  scan={$stats['scanned']} moved={$stats['moved']} gc={$stats['gc']} collide={$stats['collide']}\n";
    }
}
closedir($dh);

$dt = round(microtime(true) - $t0, 2);
echo "\nDone in {$dt}s. " . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
echo "dry-run=" . ($dryRun ? 'yes' : 'no') . " max-age={$maxAge}s\n";
