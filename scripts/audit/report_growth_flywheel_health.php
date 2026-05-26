#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/functions.php';

function readJsonFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function fileMeta(string $path): array
{
    $exists = is_file($path);
    $mtime = $exists ? (int)@filemtime($path) : 0;
    return [
        'path' => $path,
        'exists' => $exists,
        'updated_at' => $mtime > 0 ? date('c', $mtime) : null,
        'age_seconds' => $mtime > 0 ? max(0, time() - $mtime) : null,
    ];
}

function isoAgeSeconds(?string $value): ?int
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $ts = strtotime($value);
    if ($ts === false || $ts <= 0) {
        return null;
    }

    return max(0, time() - $ts);
}

function readLiveQueueMeta(): array
{
    $queueKey = defined('CONTENT_QUEUE_KEY') ? CONTENT_QUEUE_KEY : 'guonika_content_generation_queue';

    try {
        $redis = new Redis();
        if (!$redis->connect(REDIS_HOST, REDIS_PORT, 1.0)) {
            throw new RuntimeException('Redis connection failed');
        }
        if (defined('REDIS_PASSWORD') && REDIS_PASSWORD) {
            $redis->auth(REDIS_PASSWORD);
        }
        if (defined('REDIS_DB') && REDIS_DB) {
            $redis->select(REDIS_DB);
        }

        $queueLength = (int)$redis->zCard($queueKey);
        $redis->close();

        return [
            'ok' => true,
            'queue_key' => $queueKey,
            'queue_length' => $queueLength,
            'updated_at' => date('c'),
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'queue_key' => $queueKey,
            'queue_length' => null,
            'updated_at' => date('c'),
            'error' => $e->getMessage(),
        ];
    }
}

function pushIssue(array &$issues, string $level, string $code, string $message, array $meta = []): void
{
    $issues[] = [
        'level' => $level,
        'code' => $code,
        'message' => $message,
        'meta' => $meta,
    ];
}

$queueStatePath = rtrim(PRIVATE_LOG_PATH, '/') . '/generation_queue_state_report.json';
$queueFailurePath = rtrim(PRIVATE_LOG_PATH, '/') . '/generation_queue_failure_report.json';
$overflowPath = rtrim(PRIVATE_LOG_PATH, '/') . '/local_overflow_posts_report.json';
$suppressionPath = rtrim(PRIVATE_LOG_PATH, '/') . '/local_suppression_report.json';
$sharedStatePath = rtrim(PRIVATE_CACHE_PATH, '/') . '/shared_keyword_puller_state.json';
$geoStatePath = rtrim(PRIVATE_CACHE_PATH, '/') . '/geo_seed_expander_state.json';
$coreCoverageStatePath = rtrim(PRIVATE_CACHE_PATH, '/') . '/core_industrial_coverage_state.json';
$weakPostRefreshStatePath = rtrim(PRIVATE_CACHE_PATH, '/') . '/weak_industrial_post_refresher_state.json';
$pollutedLocalRepairStatePath = rtrim(PRIVATE_CACHE_PATH, '/') . '/polluted_post_local_repair_state.json';
$coreCoverageGapPath = rtrim(PRIVATE_LOG_PATH, '/') . '/core_coverage_gap_report.json';
$sitemapPath = ROOT_PATH . '/sitemap.xml';
$topicsIndexPath = ROOT_PATH . '/topics/index.html';

$queueState = readJsonFile($queueStatePath) ?? [];
$queueFailures = readJsonFile($queueFailurePath) ?? [];
$overflow = readJsonFile($overflowPath) ?? [];
$suppression = readJsonFile($suppressionPath) ?? [];
$sharedState = readJsonFile($sharedStatePath) ?? [];
$geoState = readJsonFile($geoStatePath) ?? [];
$coreCoverageState = readJsonFile($coreCoverageStatePath) ?? [];
$weakPostRefreshState = readJsonFile($weakPostRefreshStatePath) ?? [];
$pollutedLocalRepairState = readJsonFile($pollutedLocalRepairStatePath) ?? [];
$coreCoverageGap = readJsonFile($coreCoverageGapPath) ?? [];
$liveQueue = readLiveQueueMeta();

$issues = [];

$queueLength = !empty($liveQueue['ok'])
    ? (int)($liveQueue['queue_length'] ?? 0)
    : (int)($queueState['queue_length'] ?? 0);
$dueCount = (int)($queueState['due_count'] ?? 0);
$delayedCount = (int)($queueState['delayed_count'] ?? 0);
$invalidCount = (int)($queueState['invalid_count'] ?? 0);
$activeRetryCount = (int)($queueFailures['active_retry_count'] ?? 0);
$overflowTotal = (int)(($overflow['stats'] ?? [])['total'] ?? 0);
$suppressedTotalIds = (int)($suppression['suppressed_total_ids'] ?? 0);
$sharedLastRunAge = isoAgeSeconds((string)($sharedState['last_run_at'] ?? ''));
$geoLastRunAge = isoAgeSeconds((string)($geoState['last_run_at'] ?? ''));
$coreCoverageLastRunAge = isoAgeSeconds((string)($coreCoverageState['last_run_at'] ?? ''));
$weakPostRefreshLastRunAge = isoAgeSeconds((string)($weakPostRefreshState['last_run_at'] ?? ''));
$pollutedLocalRepairLastRunAge = isoAgeSeconds((string)($pollutedLocalRepairState['last_run_at'] ?? ''));
$sharedAdded = (int)((($sharedState['last_stats'] ?? [])['added']) ?? 0);
$sharedCandidates = (int)((($sharedState['last_stats'] ?? [])['candidate_total']) ?? 0);
$sharedFiltered = (int)((($sharedState['last_stats'] ?? [])['skipped_filtered']) ?? 0);
$geoAdded = (int)($geoState['last_added'] ?? 0);
$coreCoverageAdded = (int)($coreCoverageState['last_added'] ?? 0);
$weakPostRefreshQueued = (int)((($weakPostRefreshState['last_stats'] ?? [])['queued']) ?? 0);
$pollutedLocalRepairQueued = (int)((($pollutedLocalRepairState['last_stats'] ?? [])['queued']) ?? 0);
$coreCoverageMissing = (int)((($coreCoverageGap['summary'] ?? [])['missing']) ?? 0);
$coreCoverageRate = (float)((($coreCoverageGap['summary'] ?? [])['coverage_rate']) ?? 0);
$topRejectReasons = (array)((($sharedState['last_stats'] ?? [])['top_reject_reasons']) ?? []);
$failureTypeCounts = (array)($queueFailures['failure_type_counts'] ?? []);

if ($sharedLastRunAge === null || $sharedLastRunAge > 3600) {
    pushIssue($issues, 'critical', 'shared_keyword_stale', '共享关键词拉取超过 1 小时未更新', [
        'last_run_at' => $sharedState['last_run_at'] ?? null,
        'age_seconds' => $sharedLastRunAge,
    ]);
}

if ($geoLastRunAge === null || $geoLastRunAge > 3600) {
    pushIssue($issues, 'critical', 'geo_expansion_stale', 'GEO 种子扩展超过 1 小时未更新', [
        'last_run_at' => $geoState['last_run_at'] ?? null,
        'age_seconds' => $geoLastRunAge,
    ]);
}

if ($coreCoverageLastRunAge === null || $coreCoverageLastRunAge > 7200) {
    pushIssue($issues, 'warn', 'core_coverage_stale', '核心工业覆盖种子超过 2 小时未更新', [
        'last_run_at' => $coreCoverageState['last_run_at'] ?? null,
        'age_seconds' => $coreCoverageLastRunAge,
    ]);
}

if ($weakPostRefreshLastRunAge === null || $weakPostRefreshLastRunAge > 7200) {
    pushIssue($issues, 'warn', 'weak_post_refresh_stale', '旧页质量回查超过 2 小时未更新', [
        'last_run_at' => $weakPostRefreshState['last_run_at'] ?? null,
        'age_seconds' => $weakPostRefreshLastRunAge,
    ]);
}

if ($pollutedLocalRepairLastRunAge === null || $pollutedLocalRepairLastRunAge > 10800) {
    pushIssue($issues, 'warn', 'polluted_local_repair_stale', '污染页本地替换超过 3 小时未更新', [
        'last_run_at' => $pollutedLocalRepairState['last_run_at'] ?? null,
        'age_seconds' => $pollutedLocalRepairLastRunAge,
    ]);
}

if ($coreCoverageGap === []) {
    pushIssue($issues, 'warn', 'core_coverage_gap_report_missing', '核心工业覆盖缺口报告缺失', [
        'path' => $coreCoverageGapPath,
    ]);
} elseif ($coreCoverageMissing >= 500 || $coreCoverageRate < 35) {
    pushIssue($issues, 'warn', 'core_coverage_gap_high', '核心工业覆盖缺口仍然较大，需要持续补位', [
        'missing' => $coreCoverageMissing,
        'coverage_rate' => $coreCoverageRate,
    ]);
}

if ($queueLength >= 1500 || $dueCount >= 300) {
    pushIssue($issues, 'critical', 'queue_backlog_high', '内容生成队列积压过高', [
        'queue_length' => $queueLength,
        'due_count' => $dueCount,
    ]);
} elseif ($queueLength >= 500 || $dueCount >= 80) {
    pushIssue($issues, 'warn', 'queue_backlog_warn', '内容生成队列开始积压', [
        'queue_length' => $queueLength,
        'due_count' => $dueCount,
    ]);
}

if ($delayedCount >= 80 || $activeRetryCount >= 40) {
    pushIssue($issues, 'warn', 'retry_backoff_growing', '延迟重试任务较多，需关注上游生成稳定性', [
        'delayed_count' => $delayedCount,
        'active_retry_count' => $activeRetryCount,
    ]);
}

if ($invalidCount > 0) {
    pushIssue($issues, 'warn', 'queue_invalid_members', '队列中存在无效成员，清理脚本需要关注', [
        'invalid_count' => $invalidCount,
    ]);
}

if ($overflowTotal > 0) {
    $overflowReasonCounts = (array)($overflowReport['stats']['overflow_reason_counts'] ?? []);
    $capacityOverflowTotal = 0;
    $localOverlayTotal = 0;
    foreach ($overflowReasonCounts as $reason => $count) {
        $reason = trim((string)$reason);
        $count = (int)$count;
        if ($count <= 0) {
            continue;
        }

        if (in_array($reason, ['quality_check_local_overlay', 'polluted_local_repair'], true)) {
            $localOverlayTotal += $count;
            continue;
        }

        $capacityOverflowTotal += $count;
    }

    if ($capacityOverflowTotal > 0) {
        $level = $capacityOverflowTotal >= 200 ? 'critical' : 'warn';
        pushIssue($issues, $level, 'local_overflow_capacity_pressure', '本地溢出库存含容量兜底内容，表示共享 posts 容量压力仍在', [
            'overflow_total' => $overflowTotal,
            'capacity_overflow_total' => $capacityOverflowTotal,
            'reason_counts' => $overflowReasonCounts,
        ]);
    }

    if ($localOverlayTotal > 0) {
        pushIssue($issues, 'info', 'local_overlay_active', '本地覆盖层正在承接质量回刷与污染词修复，不影响共享站内容', [
            'overflow_total' => $overflowTotal,
            'local_overlay_total' => $localOverlayTotal,
            'reason_counts' => $overflowReasonCounts,
        ]);
    }
}

if ($sharedCandidates >= 200 && $sharedAdded === 0 && $sharedFiltered >= max(100, (int)floor($sharedCandidates * 0.8))) {
    pushIssue($issues, 'warn', 'shared_keyword_all_filtered', '共享词源本轮大部分被过滤，需关注词源漂移或过滤策略', [
        'candidate_total' => $sharedCandidates,
        'added' => $sharedAdded,
        'filtered' => $sharedFiltered,
        'top_reject_reasons' => $topRejectReasons,
    ]);
}

if (($failureTypeCounts['http_500'] ?? 0) >= 20) {
    pushIssue($issues, 'warn', 'upstream_http_500_spike', '近期内容生成失败以上游 500 为主', [
        'http_500' => (int)$failureTypeCounts['http_500'],
    ]);
}

$sitemapMeta = fileMeta($sitemapPath);
$topicsMeta = fileMeta($topicsIndexPath);

if (!$sitemapMeta['exists'] || (($sitemapMeta['age_seconds'] ?? 0) > 7200)) {
    pushIssue($issues, 'warn', 'sitemap_stale', 'sitemap 输出偏旧或缺失', $sitemapMeta);
}

if (!$topicsMeta['exists'] || (($topicsMeta['age_seconds'] ?? 0) > 7200)) {
    pushIssue($issues, 'warn', 'topics_index_stale', 'topics 索引输出偏旧或缺失', $topicsMeta);
}

if ($suppressedTotalIds <= 0) {
    pushIssue($issues, 'warn', 'suppression_index_empty', '本地抑制索引为空，需确认抑制刷新是否异常', [
        'suppressed_total_ids' => $suppressedTotalIds,
    ]);
}

$status = 'ok';
foreach ($issues as $issue) {
    if (($issue['level'] ?? '') === 'critical') {
        $status = 'critical';
        break;
    }
    if (($issue['level'] ?? '') === 'warn') {
        $status = 'warn';
    }
}

$report = [
    'updated_at' => date('c'),
    'status' => $status,
    'summary' => [
        'queue_length' => $queueLength,
        'due_count' => $dueCount,
        'delayed_count' => $delayedCount,
        'active_retry_count' => $activeRetryCount,
        'local_overflow_total' => $overflowTotal,
        'suppressed_total_ids' => $suppressedTotalIds,
        'shared_last_run_at' => $sharedState['last_run_at'] ?? null,
        'shared_last_added' => $sharedAdded,
        'shared_last_candidates' => $sharedCandidates,
        'geo_last_run_at' => $geoState['last_run_at'] ?? null,
        'geo_last_added' => $geoAdded,
        'core_coverage_last_run_at' => $coreCoverageState['last_run_at'] ?? null,
        'core_coverage_last_added' => $coreCoverageAdded,
        'weak_post_refresh_last_run_at' => $weakPostRefreshState['last_run_at'] ?? null,
        'weak_post_refresh_last_queued' => $weakPostRefreshQueued,
        'polluted_local_repair_last_run_at' => $pollutedLocalRepairState['last_run_at'] ?? null,
        'polluted_local_repair_last_queued' => $pollutedLocalRepairQueued,
        'core_coverage_missing' => $coreCoverageMissing,
        'core_coverage_rate' => $coreCoverageRate,
    ],
    'issues' => $issues,
    'sources' => [
        'queue_state_report' => [
            'path' => $queueStatePath,
            'updated_at' => $queueState['updated_at'] ?? null,
            'age_seconds' => isoAgeSeconds((string)($queueState['updated_at'] ?? '')),
            'reported_queue_length' => (int)($queueState['queue_length'] ?? 0),
        ],
        'live_queue' => $liveQueue,
        'queue_failure_report' => [
            'path' => $queueFailurePath,
            'updated_at' => $queueFailures['updated_at'] ?? null,
            'age_seconds' => isoAgeSeconds((string)($queueFailures['updated_at'] ?? '')),
        ],
        'local_overflow_report' => [
            'path' => $overflowPath,
            'updated_at' => $overflow['updated_at'] ?? null,
            'age_seconds' => isoAgeSeconds((string)($overflow['updated_at'] ?? '')),
        ],
        'local_suppression_report' => [
            'path' => $suppressionPath,
            'updated_at' => $suppression['updated_at'] ?? null,
            'age_seconds' => isoAgeSeconds((string)($suppression['updated_at'] ?? '')),
        ],
        'shared_keyword_puller_state' => [
            'path' => $sharedStatePath,
            'updated_at' => $sharedState['last_run_at'] ?? null,
            'age_seconds' => $sharedLastRunAge,
            'top_reject_reasons' => $topRejectReasons,
        ],
        'geo_seed_expander_state' => [
            'path' => $geoStatePath,
            'updated_at' => $geoState['last_run_at'] ?? null,
            'age_seconds' => $geoLastRunAge,
        ],
        'core_industrial_coverage_state' => [
            'path' => $coreCoverageStatePath,
            'updated_at' => $coreCoverageState['last_run_at'] ?? null,
            'age_seconds' => $coreCoverageLastRunAge,
            'last_added' => $coreCoverageAdded,
            'candidate_total' => (int)($coreCoverageState['candidate_total'] ?? 0),
        ],
        'weak_industrial_post_refresher_state' => [
            'path' => $weakPostRefreshStatePath,
            'updated_at' => $weakPostRefreshState['last_run_at'] ?? null,
            'age_seconds' => $weakPostRefreshLastRunAge,
            'last_queued' => $weakPostRefreshQueued,
        ],
        'polluted_post_local_repair_state' => [
            'path' => $pollutedLocalRepairStatePath,
            'updated_at' => $pollutedLocalRepairState['last_run_at'] ?? null,
            'age_seconds' => $pollutedLocalRepairLastRunAge,
            'last_queued' => $pollutedLocalRepairQueued,
        ],
        'core_coverage_gap_report' => [
            'path' => $coreCoverageGapPath,
            'updated_at' => $coreCoverageGap['updated_at'] ?? null,
            'age_seconds' => isoAgeSeconds((string)($coreCoverageGap['updated_at'] ?? '')),
            'missing' => $coreCoverageMissing,
            'coverage_rate' => $coreCoverageRate,
        ],
        'sitemap' => $sitemapMeta,
        'topics_index' => $topicsMeta,
    ],
];

$reportPath = rtrim(PRIVATE_LOG_PATH, '/') . '/growth_flywheel_health_report.json';
file_put_contents($reportPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);

echo '[growth-health-report] ' . date('Y-m-d H:i:s')
    . ' status=' . $status
    . ' issues=' . count($issues)
    . ' queue=' . $queueLength
    . ' retries=' . $activeRetryCount
    . ' overflow=' . $overflowTotal
    . PHP_EOL;
