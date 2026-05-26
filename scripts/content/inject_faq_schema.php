<?php
/**
 * inject_faq_schema.php
 *
 * 给 /topics/q-*.html 静态长尾页注入 FAQPage schema：
 *   - 读现有 <script type="application/ld+json"> 的 @graph
 *   - 从 HTML 中提取 <div class="lt-faq-item"><h3>Q</h3><p>A</p></div>
 *   - 把这些 Q/A 包成 FAQPage 对象 push 进 @graph
 *   - 写回原文件（重复运行幂等：先剔除已有 FAQPage）
 *
 * 用法：
 *   php scripts/inject_faq_schema.php             # 全量
 *   php scripts/inject_faq_schema.php --dry-run   # 抽检
 *   php scripts/inject_faq_schema.php --limit=5
 *
 * 回滚：找一份旧 q-*.html 重生成；或重跑此脚本（幂等剔除）
 */

declare(strict_types=1);

$opts = getopt('', ['dry-run::', 'limit::', 'file::']);
$dryRun = array_key_exists('dry-run', $opts);
$limit = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 0;
$singleFile = isset($opts['file']) ? (string)$opts['file'] : '';

$topicsDir = realpath(__DIR__ . '/../topics');
if (!$topicsDir) {
    fwrite(STDERR, "topics 目录不存在\n");
    exit(1);
}

$files = $singleFile !== ''
    ? [$singleFile]
    : (glob($topicsDir . '/q-*.html') ?: []);

if ($limit > 0 && count($files) > $limit) {
    $files = array_slice($files, 0, $limit);
}

echo "[faq-schema] 待处理：" . count($files) . " 个文件，dry-run=" . ($dryRun ? '1' : '0') . "\n";

$updated = 0;
$skipped = 0;
$noFaq = 0;
$errors = 0;

foreach ($files as $file) {
    $html = @file_get_contents($file);
    if ($html === false) {
        $errors++;
        continue;
    }

    // 1. 找 ld+json script
    if (!preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m)) {
        $skipped++;
        continue;
    }
    $jsonRaw = $m[1];
    $jsonObj = json_decode($jsonRaw, true);
    if (!is_array($jsonObj) || empty($jsonObj['@graph']) || !is_array($jsonObj['@graph'])) {
        $skipped++;
        continue;
    }

    // 2. 抽 FAQ Q/A
    if (!preg_match_all('#<div class="lt-faq-item"><h3>(.*?)</h3><p>(.*?)</p></div>#s', $html, $faqMatches, PREG_SET_ORDER)) {
        $noFaq++;
        continue;
    }
    $faqs = [];
    foreach ($faqMatches as $fm) {
        $q = trim(html_entity_decode($fm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $a = trim(html_entity_decode($fm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($q === '' || $a === '') {
            continue;
        }
        $faqs[] = [
            '@type' => 'Question',
            'name' => $q,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $a,
            ],
        ];
    }

    if (empty($faqs)) {
        $noFaq++;
        continue;
    }

    // 3. 幂等：剔除旧 FAQPage
    $newGraph = [];
    foreach ($jsonObj['@graph'] as $item) {
        if (is_array($item) && ($item['@type'] ?? '') === 'FAQPage') {
            continue;
        }
        $newGraph[] = $item;
    }

    // 4. push 新 FAQPage
    $newGraph[] = [
        '@type' => 'FAQPage',
        'mainEntity' => $faqs,
    ];

    $jsonObj['@graph'] = $newGraph;
    $newJson = json_encode($jsonObj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($newJson === false) {
        $errors++;
        continue;
    }

    // 5. 写回（替换 script 标签里的内容）
    $newHtml = preg_replace(
        '#<script type="application/ld\+json">.*?</script>#s',
        '<script type="application/ld+json">' . $newJson . '</script>',
        $html,
        1
    );

    if ($newHtml === null || $newHtml === $html) {
        $skipped++;
        continue;
    }

    if (!$dryRun) {
        if (file_put_contents($file, $newHtml) === false) {
            $errors++;
            continue;
        }
    }

    $updated++;
    if ($dryRun && $updated <= 3) {
        $base = basename($file);
        echo "  [dry] {$base} → +" . count($faqs) . " FAQ\n";
    }
}

echo "[faq-schema] 完成 — updated={$updated} no_faq={$noFaq} skipped={$skipped} errors={$errors}\n";
