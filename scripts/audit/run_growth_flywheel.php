#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$logPrefix = '[growth-flywheel] ' . date('Y-m-d H:i:s') . ' ';

function runStep(string $command, string $label): array
{
    global $logPrefix;
    echo $logPrefix . "start {$label}\n";
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    foreach ($output as $line) {
        echo '[' . $label . '] ' . $line . PHP_EOL;
    }
    echo $logPrefix . "done {$label} exit={$exitCode}\n";
    return ['exit_code' => $exitCode, 'output' => $output];
}

$maxQueue = getenv('GUONIKA_MAX_QUEUE') !== false ? (int)getenv('GUONIKA_MAX_QUEUE') : 1800;
$pullLimit = getenv('GUONIKA_PULL_LIMIT') !== false ? (int)getenv('GUONIKA_PULL_LIMIT') : 18;
$geoLimit = getenv('GUONIKA_GEO_LIMIT') !== false ? (int)getenv('GUONIKA_GEO_LIMIT') : 12;
$batch = getenv('GUONIKA_GENERATION_BATCH') !== false ? (int)getenv('GUONIKA_GENERATION_BATCH') : 4;
$dynamicQueueLength = 0;

try {
    $redis = new Redis();
    if ($redis->connect(REDIS_HOST, REDIS_PORT, 1.0)) {
        if (defined('REDIS_PASSWORD') && REDIS_PASSWORD) {
            $redis->auth(REDIS_PASSWORD);
        }
        if (defined('REDIS_DB') && REDIS_DB) {
            $redis->select(REDIS_DB);
        }
        $dynamicQueueLength = (int)$redis->zCard(defined('CONTENT_QUEUE_KEY') ? CONTENT_QUEUE_KEY : 'guonika_content_generation_queue');
    }
} catch (Throwable $e) {
    $dynamicQueueLength = 0;
}

if ($dynamicQueueLength > 0) {
    if ($dynamicQueueLength <= 60) {
        $pullLimit = max($pullLimit, 30);
        $geoLimit = max($geoLimit, 20);
        $batch = max($batch, 6);
    } elseif ($dynamicQueueLength <= 120) {
        $pullLimit = max($pullLimit, 24);
        $geoLimit = max($geoLimit, 16);
        $batch = max($batch, 5);
    } elseif ($dynamicQueueLength >= 450) {
        $pullLimit = min($pullLimit, 4);
        $geoLimit = min($geoLimit, 3);
        $batch = max($batch, 8);
    } elseif ($dynamicQueueLength >= 360) {
        $pullLimit = min($pullLimit, 8);
        $geoLimit = min($geoLimit, 5);
        $batch = max($batch, 6);
    } elseif ($dynamicQueueLength >= 260) {
        $pullLimit = min($pullLimit, 12);
        $geoLimit = min($geoLimit, 8);
        $batch = max($batch, 4);
    }
}

$results = [];
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/seed_core_industrial_coverage.php') . ' --limit=' . ($dynamicQueueLength <= 120 ? 24 : 18) . ' --group-window=3 --max-queue=' . max(100, $maxQueue),
    'seed_core_industrial_coverage'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/pull_shared_keywords.php') . ' --limit=' . max(1, $pullLimit) . ' --max-queue=' . max(100, $maxQueue) . ' --server-hits-bootstrap=800',
    'pull_shared_keywords'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/refresh_weak_industrial_posts.php') . ' --limit=' . max(6, min(36, (int)ceil($pullLimit * 1.5))) . ' --scan-limit=' . max(300, $maxQueue) . ' --recent=36000 --min-age-hours=2',
    'refresh_weak_industrial_posts'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/repair_polluted_posts_locally.php') . ' --limit=' . max(4, min(18, $pullLimit)) . ' --scan-limit=' . max(300, (int)floor($maxQueue * 0.8)) . ' --recent=48000',
    'repair_polluted_posts_locally'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/expand_geo_seed_keywords.php') . ' --limit=' . max(1, $geoLimit) . ' --per-keyword=3 --max-queue=' . max(100, $maxQueue),
    'expand_geo_seed_keywords'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/update_local_suppression_index.php') . ' --recent=12000 --limit=3000',
    'update_local_suppression_index'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/clean_generation_queue.php') . ' --max-scan=' . max(300, $maxQueue * 2),
    'clean_generation_queue'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/purge_suppressed_caches.php'),
    'purge_suppressed_caches'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/report_local_suppression.php') . ' --sample-size=20',
    'report_local_suppression'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/process_generation_queue.php') . ' --batch=' . max(1, $batch),
    'process_generation_queue'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/update_local_suppression_index.php') . ' --recent=16000 --limit=4000',
    'update_local_suppression_index_post_process'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/report_generation_queue_state.php') . ' --limit=300',
    'report_generation_queue_state'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/report_core_coverage_gaps.php') . ' --limit=150',
    'report_core_coverage_gaps'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/report_generation_queue_failures.php') . ' --limit=800',
    'report_generation_queue_failures'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/report_local_overflow_posts.php') . ' --limit=20',
    'report_local_overflow_posts'
);
$results[] = runStep(
    'php ' . escapeshellarg(__DIR__ . '/report_growth_flywheel_health.php'),
    'report_growth_flywheel_health'
);

$flagFile = rtrim(PRIVATE_CACHE_PATH, '/') . '/growth_content_changed.flag';
if (is_file($flagFile)) {
    $results[] = runStep(
        'php ' . escapeshellarg(__DIR__ . '/generate_topic_pages.php'),
        'generate_topic_pages'
    );
    $results[] = runStep(
        'php ' . escapeshellarg(ROOT_PATH . '/sitemap.xml.php') . ' > ' . escapeshellarg(ROOT_PATH . '/sitemap.xml'),
        'generate_sitemap'
    );
    @unlink($flagFile);
} else {
    echo $logPrefix . "skip rebuild no content_changed flag\n";
}

$failed = array_filter($results, static fn(array $item): bool => (int)($item['exit_code'] ?? 1) !== 0);
exit($failed ? 1 : 0);
