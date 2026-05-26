#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../api/generate_content.php';

final class WeakIndustrialPostRefresher
{
    private PDO $pdo;
    private ContentGenerator $generator;
    private bool $dryRun;
    private int $scanLimit;
    private int $enqueueLimit;
    private int $recent;
    private int $minAgeHours;
    private string $statePath;
    private array $queuedKeywordMap = [];

    public function __construct(array $options)
    {
        $this->pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        $this->generator = new ContentGenerator();
        $this->dryRun = !empty($options['dry_run']);
        $this->scanLimit = max(50, min(5000, (int)($options['scan_limit'] ?? 900)));
        $this->enqueueLimit = max(1, min(120, (int)($options['limit'] ?? 24)));
        $this->recent = max(1000, min(250000, (int)($options['recent'] ?? 24000)));
        $this->minAgeHours = max(0, min(720, (int)($options['min_age_hours'] ?? 2)));
        $this->statePath = rtrim(PRIVATE_CACHE_PATH, '/') . '/weak_industrial_post_refresher_state.json';
        $this->loadQueuedKeywords();
    }

    public function run(): int
    {
        $rows = $this->fetchRows();
        $stats = [
            'scanned' => count($rows),
            'queued' => 0,
            'skipped_allowed' => 0,
            'skipped_suppressed' => 0,
            'skipped_exists_local_overlay' => 0,
            'skipped_in_queue' => 0,
            'skipped_recent' => 0,
            'skipped_exists_quality' => 0,
            'skipped_regen_reject' => 0,
            'enqueue_failed' => 0,
            'candidates' => 0,
        ];
        $samples = [];

        foreach ($rows as $row) {
            $query = normalizeIndustrialKeyword((string)($row['query'] ?? ''));
            if ($query === '') {
                continue;
            }

            if ($this->isTooRecent($row)) {
                $stats['skipped_recent']++;
                continue;
            }

            if (isLocallySuppressedIndustrialQuery($query)) {
                $stats['skipped_suppressed']++;
                continue;
            }

            if (getLocalOverflowPostByQuery($query)) {
                $stats['skipped_exists_local_overlay']++;
                continue;
            }

            $evaluation = evaluateIndustrialKeywordCandidate($query, [
                'min_signal_score' => 2,
                'allow_geo_assist' => true,
                'source' => 'quality_check',
                'source_keyword' => (string)($row['source_keyword'] ?? ''),
            ]);

            if (!$evaluation['allowed']) {
                $stats['skipped_suppressed']++;
                continue;
            }

            $regenReject = getIndustrialQualityRegenerationRejectReason($query, $evaluation);
            if ($regenReject !== null) {
                $stats['skipped_regen_reject']++;
                continue;
            }

            $qualityScore = $this->scorePost($row, $query, $evaluation);
            $threshold = getIndustrialQualityRegenerationThreshold($query, $evaluation);
            if ($threshold === null || $qualityScore >= $threshold) {
                $stats['skipped_exists_quality']++;
                continue;
            }

            $hash = md5(mb_strtolower($query, 'UTF-8'));
            if (isset($this->queuedKeywordMap[$hash])) {
                $stats['skipped_in_queue']++;
                continue;
            }

            $stats['candidates']++;
            $samples[] = [
                'id' => (int)($row['id'] ?? 0),
                'query' => $query,
                'score' => $qualityScore,
                'threshold' => $threshold,
                'source_keyword' => mb_substr((string)($row['source_keyword'] ?? ''), 0, 120, 'UTF-8'),
            ];

            if ($this->dryRun) {
                if ($stats['queued'] < $this->enqueueLimit) {
                    $stats['queued']++;
                    $this->queuedKeywordMap[$hash] = true;
                }
                continue;
            }

            $ok = $this->generator->addToQueue($query, getContentQueuePriorityBySource('quality_check', true), [
                'source' => 'quality_check',
                'source_keyword' => 'weak_post_refresh|' . (int)($row['id'] ?? 0),
                'regenerate' => true,
                'force_local_overflow' => true,
                'overflow_reason' => 'quality_check_local_overlay',
                'score' => $qualityScore,
                'existing_post_id' => (int)($row['id'] ?? 0),
                'retry_count' => 0,
            ]);

            if (!$ok) {
                $stats['enqueue_failed']++;
                continue;
            }

            $stats['queued']++;
            $this->queuedKeywordMap[$hash] = true;
            if ($stats['queued'] >= $this->enqueueLimit) {
                break;
            }
        }

        $this->writeState($stats, $samples);
        $this->log(sprintf(
            'done scanned=%d candidates=%d queued=%d suppressed=%d local_overlay=%d in_queue=%d recent=%d quality_ok=%d regen_reject=%d enqueue_failed=%d dry_run=%d',
            $stats['scanned'],
            $stats['candidates'],
            $stats['queued'],
            $stats['skipped_suppressed'],
            $stats['skipped_exists_local_overlay'],
            $stats['skipped_in_queue'],
            $stats['skipped_recent'],
            $stats['skipped_exists_quality'],
            $stats['skipped_regen_reject'],
            $stats['enqueue_failed'],
            $this->dryRun ? 1 : 0
        ));

        return 0;
    }

    private function fetchRows(): array
    {
        $maxId = (int)($this->pdo->query('SELECT MAX(id) FROM posts')->fetchColumn() ?: 0);
        $minId = max(1, $maxId - $this->recent);
        $stmt = $this->pdo->prepare(
            "SELECT id, `query`, title, summary, content_json, category, tags_json, keywords_json, source_type, source_keyword, created_at, updated_at
             FROM posts
             WHERE id >= :min_id
               AND source_type IN ('llm_generated', 'hot_expansion')
               AND (
                    source_keyword IS NULL
                    OR source_keyword = ''
                    OR source_keyword LIKE '%server_content_hits%'
                    OR source_keyword LIKE '%shared_tracking%'
                    OR source_keyword LIKE '%shared_unmatched%'
                    OR source_keyword LIKE '%shared_hot_expansion%'
                    OR source_keyword LIKE '%geo_seed_expansion%'
                    OR source_keyword LIKE '%core_coverage_seed%'
                    OR source_keyword LIKE '%guonika_queue%'
               )
             ORDER BY id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':min_id', $minId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $this->scanLimit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    private function isTooRecent(array $row): bool
    {
        if ($this->minAgeHours <= 0) {
            return false;
        }

        $value = (string)($row['updated_at'] ?? $row['created_at'] ?? '');
        $ts = strtotime($value);
        if ($ts === false || $ts <= 0) {
            return false;
        }

        return (time() - $ts) < ($this->minAgeHours * 3600);
    }

    private function scorePost(array $row, string $query, array $evaluation): int
    {
        $score = 0;
        $title = normalizeIndustrialKeyword((string)($row['title'] ?? ''));
        $summary = normalizeIndustrialKeyword((string)($row['summary'] ?? ''));
        $contentText = $this->contentText($row);
        $contentLen = mb_strlen($contentText, 'UTF-8');
        $paragraphCount = $this->paragraphCount($row);

        if ($contentLen >= 800) {
            $score += 30;
        } elseif ($contentLen >= 600) {
            $score += 24;
        } elseif ($contentLen >= 420) {
            $score += 16;
        } elseif ($contentLen >= 260) {
            $score += 8;
        }

        if ($paragraphCount >= 5) {
            $score += 18;
        } elseif ($paragraphCount >= 4) {
            $score += 14;
        } elseif ($paragraphCount >= 2) {
            $score += 7;
        }

        if ($title !== '' && mb_stripos($title, $query, 0, 'UTF-8') !== false) {
            $score += 14;
        }
        if ($summary !== '' && mb_stripos($summary, $query, 0, 'UTF-8') !== false) {
            $score += 10;
        }
        if ($contentText !== '' && mb_stripos($contentText, $query, 0, 'UTF-8') !== false) {
            $score += 12;
        }

        $root = normalizeIndustrialGrowthRootCandidate($query);
        if ($root !== '' && $root !== $query) {
            if (mb_stripos($title, $root, 0, 'UTF-8') !== false) {
                $score += 8;
            }
            if (mb_stripos($contentText, $root, 0, 'UTF-8') !== false) {
                $score += 8;
            }
        }

        if ($summary !== '') {
            $summaryLen = mb_strlen($summary, 'UTF-8');
            if ($summaryLen >= 70 && $summaryLen <= 180) {
                $score += 12;
            } elseif ($summaryLen >= 40) {
                $score += 6;
            }
        }

        $commercialIntentScore = (int)($evaluation['commercial_intent_score'] ?? 0);
        $signalScore = (int)($evaluation['signal_score'] ?? 0);
        if (!empty($evaluation['geo_signal'])) {
            $score += 6;
        }
        if ($commercialIntentScore >= 6) {
            $score += 8;
        } elseif ($commercialIntentScore >= 4) {
            $score += 4;
        }
        if ($signalScore >= 4) {
            $score += 4;
        }

        if (preg_match('/(场景判断与实用参考|实用指南|全解析|如何正确理解和判断不同场景适用性)$/u', $title) === 1 && $contentLen < 700) {
            $score -= 16;
        }

        if (industrialSourceKeywordHasTemplatePollution((string)($row['source_keyword'] ?? '')) || industrialKeywordHasResidualTemplateArtifact($query)) {
            $score -= 18;
        }

        return max(0, min(100, $score));
    }

    private function contentText(array $row): string
    {
        $contentJson = (string)($row['content_json'] ?? '');
        if ($contentJson === '') {
            return '';
        }

        $decoded = json_decode($contentJson, true);
        if (!is_array($decoded)) {
            return normalizeIndustrialKeyword(strip_tags($contentJson));
        }

        $parts = [];
        foreach ($decoded as $item) {
            if (is_scalar($item)) {
                $parts[] = (string)$item;
            }
        }

        return normalizeIndustrialKeyword(implode(' ', $parts));
    }

    private function paragraphCount(array $row): int
    {
        $contentJson = (string)($row['content_json'] ?? '');
        $decoded = $contentJson !== '' ? json_decode($contentJson, true) : null;
        if (is_array($decoded)) {
            return count(array_filter($decoded, static fn($item): bool => is_scalar($item) && trim((string)$item) !== ''));
        }

        return $contentJson !== '' ? 1 : 0;
    }

    private function loadQueuedKeywords(): void
    {
        try {
            $redis = new Redis();
            if (!$redis->connect(REDIS_HOST, REDIS_PORT, 0.7)) {
                return;
            }
            if (defined('REDIS_PASSWORD') && REDIS_PASSWORD) {
                $redis->auth(REDIS_PASSWORD);
            }
            if (defined('REDIS_DB') && REDIS_DB) {
                $redis->select(REDIS_DB);
            }

            $queueKey = defined('CONTENT_QUEUE_KEY') ? CONTENT_QUEUE_KEY : 'guonika_content_generation_queue';
            foreach ($redis->zRange($queueKey, 0, -1) as $member) {
                $task = json_decode((string)$member, true);
                if (!is_array($task)) {
                    continue;
                }

                $keyword = normalizeIndustrialKeyword((string)($task['keyword'] ?? ''));
                if ($keyword !== '') {
                    $this->queuedKeywordMap[md5(mb_strtolower($keyword, 'UTF-8'))] = true;
                }
            }
            $redis->close();
        } catch (Throwable $e) {
            return;
        }
    }

    private function writeState(array $stats, array $samples): void
    {
        $state = [
            'last_run_at' => date('c'),
            'last_stats' => $stats,
            'samples' => array_slice($samples, 0, 30),
        ];
        file_put_contents(
            $this->statePath,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private function log(string $message): void
    {
        echo '[weak-post-refresh] ' . date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
    }
}

$options = [
    'dry_run' => false,
    'limit' => 24,
    'scan_limit' => 900,
    'recent' => 24000,
    'min_age_hours' => 2,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $options['dry_run'] = true;
        continue;
    }
    if (strpos($arg, '--limit=') === 0) {
        $options['limit'] = (int)substr($arg, 8);
        continue;
    }
    if (strpos($arg, '--scan-limit=') === 0) {
        $options['scan_limit'] = (int)substr($arg, 13);
        continue;
    }
    if (strpos($arg, '--recent=') === 0) {
        $options['recent'] = (int)substr($arg, 9);
        continue;
    }
    if (strpos($arg, '--min-age-hours=') === 0) {
        $options['min_age_hours'] = (int)substr($arg, 16);
    }
}

try {
    $refresher = new WeakIndustrialPostRefresher($options);
    exit($refresher->run());
} catch (Throwable $e) {
    fwrite(STDERR, '[weak-post-refresh] fatal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
