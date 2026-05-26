<?php
/**
 * 百度普通收录主动推送 - baidu_push.php
 *
 * 配额：默认每日 N 万条（按权重）。
 * Endpoint: http://data.zz.baidu.com/urls?site=https://guonika.com&token=XXX
 * 文档: https://ziyuan.baidu.com/college/articleinfo?id=267
 *
 * 用法：
 *   php scripts/baidu_push.php                          # 推最近 1 小时新增
 *   php scripts/baidu_push.php --since=2026-05-22       # 推某日起新增
 *   php scripts/baidu_push.php --urls=urls.txt          # 推文件里列的 URL
 *   php scripts/baidu_push.php --table=trade_leads      # 推某表的最近变更
 *   php scripts/baidu_push.php --dry-run                # 不实际调 API，只打印
 *
 * 配置：
 *   需要在 config.php 或环境里定义：
 *     BAIDU_PUSH_TOKEN  -- 站长后台 https://ziyuan.baidu.com/linksubmit/index 取
 *     BAIDU_PUSH_SITE   -- 默认 https://guonika.com
 */

require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

@date_default_timezone_set('Asia/Shanghai');

if (!defined('BAIDU_PUSH_TOKEN')) {
    fwrite(STDERR, "[error] BAIDU_PUSH_TOKEN 未定义。请在 config.php 加：\n");
    fwrite(STDERR, "  define('BAIDU_PUSH_TOKEN', 'your_token_from_ziyuan_baidu_com');\n");
    exit(1);
}
$site = defined('BAIDU_PUSH_SITE') ? BAIDU_PUSH_SITE : (defined('SITE_URL') ? SITE_URL : 'https://guonika.com');

$opts = getopt('', ['since::', 'urls::', 'table::', 'dry-run::', 'limit::']);
$dryRun = isset($opts['dry-run']);
$limit = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 2000;

$logFile = __DIR__ . '/baidu_push.log';
function logPush(string $msg, string $logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

// 收集 URL
$urls = [];

if (!empty($opts['urls']) && is_file($opts['urls'])) {
    foreach (file($opts['urls'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $u = trim($line);
        if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL)) $urls[] = $u;
    }
}

$db = getDB();

if (!empty($opts['table']) || (empty($urls) && empty($opts['urls']))) {
    $table = $opts['table'] ?? '';
    $since = $opts['since'] ?? date('Y-m-d H:i:s', strtotime('-1 hour'));

    if ($table === '' || $table === 'trade_leads') {
        $rows = $db->fetchAll(
            "SELECT id FROM trade_leads WHERE created_at >= ? OR date >= ? ORDER BY id DESC LIMIT ?",
            [$since, substr($since,0,10), $limit]
        );
        foreach ($rows as $r) $urls[] = rtrim($site,'/') . '/trade/' . (int)$r['id'];
    }

    if ($table === '' || $table === 'posts') {
        $rows = $db->fetchAll(
            "SELECT id FROM posts WHERE created_at >= ? OR updated_at >= ? ORDER BY id DESC LIMIT ?",
            [$since, $since, $limit]
        );
        foreach ($rows as $r) $urls[] = rtrim($site,'/') . '/news/' . (int)$r['id'];
    }

    if ($table === '' || $table === 'products') {
        try {
            $rows = $db->fetchAll(
                "SELECT id FROM products WHERE created_at >= ? OR updated_at >= ? ORDER BY id DESC LIMIT ?",
                [$since, $since, $limit]
            );
            foreach ($rows as $r) $urls[] = rtrim($site,'/') . '/products/' . (int)$r['id'];
        } catch (Throwable $e) { /* table may not have these cols */ }
    }
}

$urls = array_values(array_unique($urls));
if (empty($urls)) {
    logPush("[skip] 没有 URL 可推送", $logFile);
    exit(0);
}

logPush("[start] 准备推送 " . count($urls) . " 个 URL (dry-run=" . ($dryRun?'1':'0') . ")", $logFile);

// 分批，每批 ≤ 2000 个 URL（百度上限）
$batchSize = 2000;
$batches = array_chunk($urls, $batchSize);
$totalSuccess = 0;
$totalRemaining = null;

foreach ($batches as $i => $batch) {
    $body = implode("\n", $batch);
    $api = 'http://data.zz.baidu.com/urls?site=' . rawurlencode($site) . '&token=' . rawurlencode(BAIDU_PUSH_TOKEN);

    if ($dryRun) {
        logPush("[dry-run] batch " . ($i+1) . " size=" . count($batch) . " api=$api", $logFile);
        logPush("           first 3 urls: " . implode(', ', array_slice($batch,0,3)), $logFile);
        continue;
    }

    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        logPush("[fail] batch " . ($i+1) . " HTTP $code err=$err body=" . substr((string)$resp,0,200), $logFile);
        continue;
    }

    $j = json_decode((string)$resp, true);
    if (!is_array($j)) {
        logPush("[fail] batch " . ($i+1) . " 非 JSON 响应: " . substr((string)$resp,0,200), $logFile);
        continue;
    }

    // 百度响应示例：
    //   成功: {"remain":4999998,"success":2}
    //   错误: {"error":401,"message":"token is not valid"}
    if (isset($j['error'])) {
        logPush("[fail] batch " . ($i+1) . " API error: " . json_encode($j, JSON_UNESCAPED_UNICODE), $logFile);
        continue;
    }
    $succ = (int)($j['success'] ?? 0);
    $totalSuccess += $succ;
    $totalRemaining = isset($j['remain']) ? (int)$j['remain'] : $totalRemaining;
    logPush("[ok]   batch " . ($i+1) . " success=$succ remain=" . ($j['remain'] ?? '?') . " not_same_site=" . count($j['not_same_site'] ?? []) . " not_valid=" . count($j['not_valid'] ?? []), $logFile);

    // 限速避免触发风控
    usleep(500000);
}

logPush("[done] total success=$totalSuccess remain=" . ($totalRemaining ?? '?'), $logFile);
