<?php
/**
 * SimpleCache 定期观测脚本（pulse）。
 *
 * 用途：定期跑一次（建议 5-10 分钟），把以下指标追加到 logs/cache_pulse.log：
 *   - 核心 cache key 是否命中、文件 mtime / age / size
 *   - 256 个 shard 桶的所有权 + 异常计数（root 拥有 = 异常）
 *   - 关键 URL（home / news / products / search / api/posts.php）的 cold/hot 延迟
 *
 * 出现以下情况时自动触发 fix：
 *   - root-owned shards > 0 → echo 警告并提示运行 scripts/fix_cache_perms.sh
 *
 * 用法：
 *   php scripts/cache_pulse.php
 *   php scripts/cache_pulse.php --json    # 仅输出 JSON 摘要
 *
 * 写入：./logs/cache_pulse.log（按行追加）
 *
 * 建议 cron 挂法（用户自行 crontab -e 添加，不自动改 crontab）：
 *   每 5 分钟跑一次 pulse：  cd /www/wwwroot/guonika.com && php scripts/cache_pulse.php >/dev/null 2>&1
 *   每天 04:00 修权限：     cd /www/wwwroot/guonika.com && sudo bash scripts/fix_cache_perms.sh >> logs/fix_cache_perms.log 2>&1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/cache.php';

$jsonOnly = in_array('--json', $argv ?? [], true);

$cacheDir = defined('PRIVATE_CACHE_PATH') ? PRIVATE_CACHE_PATH : (ROOT_PATH . '/cache');

// 1) 核心 cache key 命中状态
$watchKeys = [
    'home_quote_cards_v1',
    'home_hub_count_v1',
    'news_detail_quote_pool_v1',
    'pc_trade_stats_v1_' . date('Y-m-d'),
];
$keyStats = [];
foreach ($watchKeys as $k) {
    $hash = md5($k);
    $shardFile = $cacheDir . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    $legacyFile = $cacheDir . '/' . $hash . '.cache';
    $file = is_file($shardFile) ? $shardFile : (is_file($legacyFile) ? $legacyFile : null);
    if ($file === null) {
        $keyStats[$k] = ['status' => 'MISS'];
        continue;
    }
    $age = time() - (int)@filemtime($file);
    $size = (int)@filesize($file);
    $keyStats[$k] = [
        'status' => 'HIT',
        'age_s' => $age,
        'size' => $size,
        'shard' => $file === $shardFile ? 'sharded' : 'legacy',
    ];
}

// 2) 桶所有权 / 权限审计
$rootOwnedDirs = 0;
$writableDirs = 0;
$totalDirs = 0;
foreach (glob($cacheDir . '/[0-9a-f][0-9a-f]', GLOB_ONLYDIR) ?: [] as $d) {
    $totalDirs++;
    $stat = @stat($d);
    if ($stat && $stat['uid'] === 0) $rootOwnedDirs++;
    if (@is_writable($d)) $writableDirs++;
}

// 3) URL 延迟探测
$host = 'guonika.com';
$baseUrl = 'http://127.0.0.1';
$urls = [
    'home' => '/',
    'news' => '/news/',
    'products' => '/products/',
    'companies' => '/companies/',
    'trade' => '/trade/',
    'search_valve' => '/search?q=%E5%B7%A5%E4%B8%9A%E9%98%80%E9%97%A8',
    'api_posts_default' => '/api/posts.php?pageSize=10',
    'api_posts_q' => '/api/posts.php?q=%E9%98%80%E9%97%A8',
];
$urlStats = [];
foreach ($urls as $name => $path) {
    $samples = [];
    for ($i = 0; $i < 3; $i++) {
        $t0 = microtime(true);
        $ch = curl_init($baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Host: ' . $host],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = is_string($body) ? strlen($body) : 0;
        curl_close($ch);
        $samples[] = [
            'ms' => (int)round((microtime(true) - $t0) * 1000),
            'code' => $code,
            'size' => $size,
        ];
    }
    // 取最后两次（去掉首次冷）
    $hotSamples = array_slice($samples, 1);
    $hotMsAvg = (int)round(array_sum(array_column($hotSamples, 'ms')) / max(1, count($hotSamples)));
    $urlStats[$name] = [
        'cold_ms' => $samples[0]['ms'],
        'hot_avg_ms' => $hotMsAvg,
        'code' => $samples[0]['code'],
        'size' => $samples[0]['size'],
    ];
}

// 4) 摘要
$summary = [
    'ts' => date('Y-m-d H:i:s'),
    'shards' => [
        'total' => $totalDirs,
        'root_owned' => $rootOwnedDirs,
        'writable' => $writableDirs,
    ],
    'keys' => $keyStats,
    'urls' => $urlStats,
];

$logDir = ROOT_PATH . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$logFile = $logDir . '/cache_pulse.log';
@file_put_contents($logFile, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

if ($jsonOnly) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

// 人类可读输出
echo "[cache-pulse] " . $summary['ts'] . "\n";
echo "  shards: total={$summary['shards']['total']} root_owned={$summary['shards']['root_owned']} writable={$summary['shards']['writable']}\n";
if ($rootOwnedDirs > 0) {
    echo "  *** WARNING: $rootOwnedDirs shard dirs are root-owned. Run: sudo bash scripts/fix_cache_perms.sh\n";
}
echo "  watch keys:\n";
foreach ($keyStats as $k => $v) {
    if ($v['status'] === 'HIT') {
        echo sprintf("    [HIT] %-40s age=%5ds size=%6dB shard=%s\n", $k, $v['age_s'], $v['size'], $v['shard']);
    } else {
        echo sprintf("    [MISS] %s\n", $k);
    }
}
echo "  urls (cold / hot-avg ms):\n";
foreach ($urlStats as $name => $v) {
    $tag = $v['code'] === 200 ? 'OK ' : "C{$v['code']}";
    echo sprintf("    [%s] %-22s %4dms / %4dms  %dB\n", $tag, $name, $v['cold_ms'], $v['hot_avg_ms'], $v['size']);
}
