<?php
/**
 * 高频页面缓存预热脚本
 * 通过本地 HTTP 调用重建首页 / news / trade / search 等关键缓存
 * 设计为可挂 cron（每 5 分钟一次），保证用户永远命中热缓存
 *
 * 用法：
 *   php scripts/warmup_caches.php
 *   php scripts/warmup_caches.php --verbose
 */

declare(strict_types=1);

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

$baseUrl = 'http://127.0.0.1';
$host = 'guonika.com';

$urls = [
    '/' => 'home',
    '/news/' => 'news',
    '/products/' => 'products',
    '/products/?page=2' => 'products_p2',
    '/products/?sort=views' => 'products_views',
    '/companies/' => 'companies',
    '/companies/?page=2' => 'companies_p2',
    '/trade/' => 'trade',
    '/opportunities/' => 'opportunities',
    '/search?q=%E5%B7%A5%E4%B8%9A%E9%98%80%E9%97%A8' => 'search_valve',
    '/search?q=%E8%BD%B4%E6%89%BF' => 'search_bearing',
    // 详情页兜底：news_detail 内的 quote_pool key (news_detail_quote_pool_v1)
    // 30min TTL + SWR 60s，被 product/company/news 三种详情页共享。
    // 取一条新闻拉一次即足够把 quote_pool 重建写入 sharded cache。
    '/news/2000000286' => 'news_detail_seed',
];

$totalT0 = microtime(true);
$stats = ['ok' => 0, 'fail' => 0, 'totalMs' => 0];

foreach ($urls as $path => $name) {
    $t0 = microtime(true);
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Host: ' . $host],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_NOBODY => false,  // 让 PHP 真的渲染完，触发 cache::set
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = is_string($body) ? strlen($body) : 0;
    curl_close($ch);
    $dt = round((microtime(true) - $t0) * 1000);
    $stats['totalMs'] += $dt;

    if ($code === 200 && $size > 1000) {
        $stats['ok']++;
        if ($verbose) {
            echo sprintf("  [OK] %-25s %4dms  %dKB\n", $name, $dt, $size >> 10);
        }
    } else {
        $stats['fail']++;
        echo sprintf("  [FAIL] %-25s code=%d size=%d\n", $name, $code, $size);
    }
}

$totalDt = round((microtime(true) - $totalT0) * 1000);
echo sprintf(
    "[%s] warmup ok=%d fail=%d total=%dms\n",
    date('Y-m-d H:i:s'),
    $stats['ok'],
    $stats['fail'],
    $totalDt
);

/*
建议的 cron 挂法（用户自行确认后添加）：

  # 每 5 分钟预热一次，避免缓存 TTL 到期后首次访问慢
  *​/5 * * * * flock -xn /tmp/guonika_warmup.lock -c "/usr/bin/php /www/wwwroot/guonika.com/scripts/warmup_caches.php" >> /tmp/guonika-runtime/logs/cron_warmup.log 2>&1

  # 每天凌晨 4 点清理 30 天以上的旧缓存（GC）
  0 4 * * * /usr/bin/php /www/wwwroot/guonika.com/scripts/migrate_cache_shards.php --max-age=2592000 >> /tmp/guonika-runtime/logs/cron_cache_gc.log 2>&1
*/
