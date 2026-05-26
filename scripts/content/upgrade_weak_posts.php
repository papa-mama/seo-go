#!/usr/bin/env php
<?php
/**
 * 历史 weak posts 升级 worker
 * - 拉取 LENGTH(content_json) < 800 的 posts（清洁 query/title）
 * - 30 路 curl_multi 并发调 LLM
 * - 校验 ≥ MIN_CONTENT_BODY_LENGTH 后原地 UPDATE posts（保留 id/url）
 * - 失败 ID 写入 state，下轮跳过；可重置
 *
 * 用法：
 *   php scripts/upgrade_weak_posts.php --limit=300 --concurrency=30
 *   php scripts/upgrade_weak_posts.php --dry-run --limit=10
 *   php scripts/upgrade_weak_posts.php --reset-state
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/llm_prompt_kit.php';

final class WeakPostsUpgrader
{
    private const MIN_NEW_BODY_LEN = 850;       // 升级后正文（content_json 数组拼字符）字符下限
    private const MIN_NEW_BYTES = 1200;         // 升级后 content_json JSON 字节下限
    private const STATE_FILE = '/runtime/upgrade_weak_posts_state.json';
    private const LOG_FILE = '/runtime/upgrade_weak_posts.log';

    private PDO $pdo;
    private string $apiUrl;
    private string $apiKey;
    private string $model;
    private int $limit;
    private int $concurrency;
    private bool $dryRun;
    private bool $verbose;
    private array $failedIds = [];

    public function __construct(array $opts)
    {
        $this->pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $this->apiUrl = rtrim(LLM_API_URL, '/') . '/chat/completions';
        $this->apiKey = LLM_API_KEY;
        $this->model = LLM_MODEL;
        $this->limit = max(1, (int)($opts['limit'] ?? 60));
        $this->concurrency = max(1, min(50, (int)($opts['concurrency'] ?? 30)));
        $this->dryRun = !empty($opts['dry-run']);
        $this->verbose = !empty($opts['verbose']);
        $this->loadState();
    }

    public function resetState(): void
    {
        $path = ROOT_PATH . self::STATE_FILE;
        if (is_file($path)) @unlink($path);
        $this->log('state reset');
    }

    private function statePath(): string { return ROOT_PATH . self::STATE_FILE; }

    private function loadState(): void
    {
        $path = $this->statePath();
        if (!is_file($path)) { $this->failedIds = []; return; }
        $raw = @file_get_contents($path);
        $arr = $raw ? json_decode($raw, true) : [];
        $this->failedIds = is_array($arr['failed_ids'] ?? null) ? array_flip(array_map('intval', $arr['failed_ids'])) : [];
    }

    private function saveState(): void
    {
        if (!is_dir(dirname($this->statePath()))) @mkdir(dirname($this->statePath()), 0775, true);
        @file_put_contents($this->statePath(), json_encode([
            'failed_ids' => array_keys($this->failedIds),
            'updated_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function run(): void
    {
        $rows = $this->fetchCandidates();
        if (!$rows) { $this->log('no candidates'); return; }

        $this->log(sprintf('candidates=%d concurrency=%d dry=%s', count($rows), $this->concurrency, $this->dryRun ? 'yes' : 'no'));

        $stats = ['ok' => 0, 'fail_http' => 0, 'fail_parse' => 0, 'fail_short' => 0, 'fail_suppress' => 0];

        foreach (array_chunk($rows, $this->concurrency) as $batchIdx => $batch) {
            $t0 = microtime(true);
            $results = $this->callBatch($batch);
            foreach ($results as $i => $resp) {
                try {
                    $row = $batch[$i];
                    $id = (int)$row['id'];
                    if ($resp['err'] !== '') {
                        $stats['fail_http']++;
                        $this->failedIds[$id] = 1;
                        $this->log("FAIL HTTP id=$id err={$resp['err']}");
                        continue;
                    }
                    $parsed = $this->parseResponse((string)$resp['body'], (string)$row['query']);
                    if (!is_array($parsed)) {
                        $stats['fail_parse']++;
                        $this->failedIds[$id] = 1;
                        $this->log("FAIL PARSE id=$id");
                        continue;
                    }
                    $bodyText = implode('', $parsed['content']);
                    if (mb_strlen($bodyText, 'UTF-8') < self::MIN_NEW_BODY_LEN) {
                        $stats['fail_short']++;
                        $this->failedIds[$id] = 1;
                        $this->log("FAIL SHORT id=$id len=" . mb_strlen($bodyText, 'UTF-8'));
                        continue;
                    }
                    $contentJson = json_encode($parsed['content'], JSON_UNESCAPED_UNICODE);
                    if (strlen($contentJson) < self::MIN_NEW_BYTES) {
                        $stats['fail_short']++;
                        $this->failedIds[$id] = 1;
                        $this->log("FAIL SHORT_BYTES id=$id bytes=" . strlen($contentJson));
                        continue;
                    }
                    $newTitle = trim((string)($parsed['title'] ?? $row['title']));
                    if ($newTitle === '' || str_contains($newTitle, '{') || preg_match('/参数\s*参数|价格\s*价格/u', $newTitle)) {
                        $newTitle = trim((string)$row['title']);
                    }
                    if ($newTitle === '' || !isIndustrialContentTextAllowed($newTitle)) {
                        $stats['fail_suppress']++;
                        $this->failedIds[$id] = 1;
                        $this->log("FAIL SUPPRESS id=$id title=" . mb_substr($newTitle, 0, 60, 'UTF-8'));
                        continue;
                    }

                    if ($this->dryRun) {
                        $this->log(sprintf('DRY id=%d new_title=%s new_len=%d', $id, mb_substr($newTitle, 0, 40, 'UTF-8'), mb_strlen($bodyText, 'UTF-8')));
                    } else {
                        try {
                            $this->writeBack($id, $newTitle, $parsed, (string)$row['category']);
                        } catch (\Throwable $e) {
                            $this->log("FAIL WRITE id=$id err=" . $e->getMessage());
                            $this->failedIds[$id] = 1;
                            continue;
                        }
                        $this->log(sprintf('OK id=%d len=%d', $id, mb_strlen($bodyText, 'UTF-8')));
                    }
                    $stats['ok']++;
                    unset($this->failedIds[$id]);
                } catch (\Throwable $e) {
                    $idDbg = isset($row['id']) ? $row['id'] : '?';
                    $this->log("FAIL EXCEPTION id=$idDbg err=" . $e->getMessage());
                    if (isset($id)) $this->failedIds[$id] = 1;
                }
            }
            $dt = round((microtime(true) - $t0) * 1000);
            $this->log(sprintf('batch %d done in %dms ok=%d', $batchIdx + 1, $dt, $stats['ok']));
            $this->saveState();
        }

        $this->log('STATS ' . json_encode($stats, JSON_UNESCAPED_UNICODE));
    }

    private function fetchCandidates(): array
    {
        // 优先升级：query 清洁（4-20 字符）、title 干净、内容偏短（提升收益高）、views 高（影响面广）
        $exclude = array_keys($this->failedIds);
        $excludeSql = '';
        $params = [];
        if ($exclude) {
            $placeholders = implode(',', array_fill(0, count($exclude), '?'));
            $excludeSql = " AND p.id NOT IN ($placeholders)";
            $params = $exclude;
        }
        $sql = "SELECT p.id, p.title, p.query, p.category, LENGTH(p.content_json) AS len
                FROM posts p
                WHERE LENGTH(p.content_json) < 800
                  AND p.query IS NOT NULL
                  AND CHAR_LENGTH(p.query) BETWEEN 2 AND 24
                  AND p.title IS NOT NULL
                  AND CHAR_LENGTH(p.title) BETWEEN 10 AND 40
                  AND p.title NOT LIKE '%{%'
                  AND p.title NOT LIKE '%参数 参数%'
                  AND p.title NOT LIKE '%价格价格%'
                  AND p.query NOT LIKE '%参数 参数%'
                  AND p.query NOT LIKE '%价格价格%'
                  AND p.source_type IN ('manual','llm_generated')
                  $excludeSql
                ORDER BY p.id DESC
                LIMIT {$this->limit}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function buildPrompt(string $keyword, string $title): string
    {
        $kw = $this->normalize($keyword);
        $title = $this->normalize($title);
        $rules = LLMPromptKit::rules([
            'reader_persona', 'anti_ai_slop', 'industrial_quantify',
            'industrial_voice', 'rfq_oriented', 'no_fabrication',
            'writing_density', 'no_markdown', 'geo_locality',
        ]);
        $checklist = LLMPromptKit::checklist('weak_post');
        return <<<PROMPT
{$rules}

【本次任务】重写一篇高信息密度的工业文章，用于覆盖一篇质量偏低的历史页面。
旧标题（仅供主题参考，可改写）：{$title}
核心关键词：{$kw}

要求：
1. 新标题 18-32 字，自然包含核心关键词或同义实体，不出现"参数 参数""价格价格"等堆叠词。
2. 摘要 80-140 字，先点明问题与读者价值，再概括正文要点。
3. 正文写 5-7 段，每段 130-220 字，总正文不少于 900 字。
4. 第一段直接回答用户问题；后续段落覆盖：典型规格/选型、采购询价口径、交付/服务、地域或产业带（如关键词含地名）、常见误区、判断标准。
5. category 在以下列表中择一：制造、采购、供应、物流、机械设备、电子电工、化工及能源、维修、应用。
6. tags 返回 4-6 个精准短词，覆盖核心实体 + 采购动作 + 交付场景 / 地域。

{$checklist}

返回 JSON：
{
  "title": "...",
  "summary": "...",
  "content": ["段1","段2","段3","段4","段5"],
  "category": "...",
  "tags": ["...","..."]
}
只返回 JSON，不要任何额外说明。
PROMPT;
    }

    private function normalize(string $s): string
    {
        $s = preg_replace('/[\s\x{3000}]+/u', ' ', $s);
        return trim((string)$s);
    }

    /**
     * 30 路 curl_multi 批量调 LLM
     * @return array<int,array{err:string,body:string}>
     */
    private function callBatch(array $batch): array
    {
        fwrite(STDERR, "[callBatch] start size=" . count($batch) . "\n");
        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $i => $row) {
            $prompt = $this->buildPrompt((string)$row['query'], (string)$row['title']);
            $payload = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => LLMPromptKit::buildSystem('procurement_consultant', ['anti_ai_slop', 'industrial_quantify', 'no_markdown', 'strict_json'])],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => LLMPromptKit::temperature('weak_post'),
                'max_tokens' => 3600,
            ];
            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        fwrite(STDERR, "[callBatch] all handles added, executing...\n");
        $running = null;
        do {
            $mrc = curl_multi_exec($mh, $running);
            if ($mrc !== CURLM_OK) {
                fwrite(STDERR, "[callBatch] curl_multi_exec returned $mrc\n");
                break;
            }
            if ($running > 0) curl_multi_select($mh, 1.0);
        } while ($running > 0);
        fwrite(STDERR, "[callBatch] exec done, collecting...\n");

        $out = [];
        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            $errMsg = '';
            if ($err) $errMsg = $err;
            elseif ($code !== 200) $errMsg = "http_$code";
            if ($i < 3) fwrite(STDERR, "[callBatch] i=$i code=$code err=$errMsg bodylen=" . strlen((string)$body) . "\n");
            $out[$i] = ['err' => $errMsg, 'body' => is_string($body) ? $body : ''];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private function parseResponse(string $rawBody, string $keyword): ?array
    {
        $j = json_decode($rawBody, true);
        if (!is_array($j) || !isset($j['choices'][0]['message']['content'])) return null;
        $content = (string)$j['choices'][0]['message']['content'];
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', trim($content));
        // 抽取首个 { 到末尾最后一个 }（不用递归正则，避免大文本爆栈）
        $first = strpos($content, '{');
        $last = strrpos($content, '}');
        if ($first === false || $last === false || $last <= $first) return null;
        $content = substr($content, $first, $last - $first + 1);
        $parsed = json_decode($content, true);
        if (!is_array($parsed) || empty($parsed['content'])) return null;
        if (!is_array($parsed['content'])) $parsed['content'] = [(string)$parsed['content']];
        $parsed['content'] = array_values(array_filter(array_map(static function ($p): string {
            if (!is_scalar($p)) return '';
            return trim(strip_tags((string)$p));
        }, $parsed['content']), static fn(string $s): bool => $s !== ''));
        if (count($parsed['content']) < 4) return null;
        $parsed['title'] = trim((string)($parsed['title'] ?? ''));
        $parsed['summary'] = trim((string)($parsed['summary'] ?? mb_substr(implode('', $parsed['content']), 0, 110, 'UTF-8')));
        $parsed['category'] = trim((string)($parsed['category'] ?? ''));
        $parsed['tags'] = array_values(array_filter(array_map(static function ($t): string {
            return trim((string)$t);
        }, (array)($parsed['tags'] ?? [])), static fn(string $s): bool => $s !== ''));
        if (!$parsed['tags']) $parsed['tags'] = ['工业', '采购', '供应链', '制造'];
        $parsed['tags'] = array_slice($parsed['tags'], 0, 6);
        return $parsed;
    }

    private function writeBack(int $id, string $newTitle, array $parsed, string $oldCategory): void
    {
        $contentJson = json_encode($parsed['content'], JSON_UNESCAPED_UNICODE);
        $tagsJson = json_encode($parsed['tags'], JSON_UNESCAPED_UNICODE);
        $summary = mb_substr($parsed['summary'], 0, 240, 'UTF-8');
        $category = $parsed['category'] !== '' ? $parsed['category'] : $oldCategory;
        $sql = "UPDATE posts
                SET title = :title,
                    summary = :summary,
                    content_json = :content_json,
                    category = :category,
                    tags_json = :tags_json,
                    source_type = 'llm_generated',
                    updated_at = NOW()
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':title' => $newTitle,
            ':summary' => $summary,
            ':content_json' => $contentJson,
            ':category' => $category,
            ':tags_json' => $tagsJson,
            ':id' => $id,
        ]);
    }

    private function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        $path = ROOT_PATH . self::LOG_FILE;
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, $line, FILE_APPEND);
        if ($this->verbose) fwrite(STDERR, $line);
    }
}

// ---------- CLI ----------
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)=(.+)$/', $arg, $m)) $opts[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z\-]+)$/', $arg, $m)) $opts[$m[1]] = true;
}

$upgrader = new WeakPostsUpgrader($opts + ['verbose' => true]);
if (!empty($opts['reset-state'])) { $upgrader->resetState(); exit(0); }
$upgrader->run();
