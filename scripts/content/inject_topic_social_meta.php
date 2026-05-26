#!/usr/bin/env php
<?php
/**
 * 静态 topic 页 og: + twitter: 元标注入器（无 LLM，幂等）
 * - 扫描 topics/{flagship,categories,queries,geo}/*.html
 * - 从 <title>/<meta description>/<link canonical> 抽数据
 * - 注入：og:type, og:title, og:description, og:url, og:image, og:site_name, og:locale,
 *   twitter:card, twitter:title, twitter:description, twitter:image
 * - 图片走本地 fallback：/assets/img/cover/{section}.jpg → industrial.jpg
 * - 幂等：若已含 og:image 跳过；--force 重写
 *
 * 用法：
 *   php scripts/inject_topic_social_meta.php --dry-run
 *   php scripts/inject_topic_social_meta.php --section=flagship
 *   php scripts/inject_topic_social_meta.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

final class TopicSocialMetaInjector
{
    private bool $dryRun;
    private bool $force;
    private ?string $section;
    private int $okCount = 0;
    private int $skipCount = 0;
    private int $failCount = 0;

    private const COVER_BY_SECTION = [
        'flagship' => '/assets/img/cover/industrial.jpg',
        'categories' => '/assets/img/cover/factory.jpg',
        'queries' => '/assets/img/cover/industrial.jpg',
        'geo' => '/assets/img/cover/trade.jpg',
    ];

    public function __construct(array $opts)
    {
        $this->dryRun = !empty($opts['dry-run']);
        $this->force = !empty($opts['force']);
        $this->section = isset($opts['section']) ? (string)$opts['section'] : null;
    }

    public function run(): void
    {
        $sections = ['flagship', 'categories', 'queries', 'geo', 'quotes'];
        foreach ($sections as $sec) {
            if ($this->section && $this->section !== $sec) continue;
            $files = glob(ROOT_PATH . "/topics/$sec/*.html") ?: [];
            foreach ($files as $file) {
                $base = basename($file);
                if ($base === 'index.html') {
                    // index 也注入 og/twitter
                }
                $this->process($file, $sec, $base);
            }
        }
        fprintf(STDERR, "DONE ok=%d skip=%d fail=%d\n", $this->okCount, $this->skipCount, $this->failCount);
    }

    private function process(string $file, string $section, string $base): void
    {
        $html = file_get_contents($file);
        if ($html === false) { $this->failCount++; return; }

        if (!$this->force && strpos($html, 'og:image') !== false) {
            $this->skipCount++;
            return;
        }

        // 抽 title
        $title = '';
        if (preg_match('#<title>([^<]+)</title>#u', $html, $tm)) {
            $title = trim($tm[1]);
            // 截掉 " - 全球工业产业链" 后缀
            $title = preg_replace('/\s*-\s*全球工业产业链\s*$/u', '', $title) ?? $title;
        }
        if ($title === '') {
            $this->failCount++;
            fprintf(STDERR, "FAIL no title: $section/$base\n");
            return;
        }

        // 抽 description
        $desc = '';
        if (preg_match('#<meta name="description" content="([^"]+)"#u', $html, $dm)) {
            $desc = trim($dm[1]);
        }
        if ($desc === '') $desc = $title;
        if (mb_strlen($desc, 'UTF-8') > 200) $desc = mb_substr($desc, 0, 200, 'UTF-8') . '…';

        // 抽 canonical
        $canon = '';
        if (preg_match('#<link rel="canonical" href="([^"]+)"#u', $html, $cm)) {
            $canon = trim($cm[1]);
        }
        if ($canon === '') {
            $canon = 'https://guonika.com/topics/' . $section . '/' . $base;
        }

        // 选 cover 图（本地 only）
        $coverPath = self::COVER_BY_SECTION[$section] ?? '/assets/img/cover/industrial.jpg';
        $absCover = ROOT_PATH . $coverPath;
        if (!is_file($absCover)) {
            // fallback 链：industrial → factory → trade
            foreach (['/assets/img/cover/industrial.jpg', '/assets/img/cover/factory.jpg', '/assets/img/cover/trade.jpg'] as $alt) {
                if (is_file(ROOT_PATH . $alt)) { $coverPath = $alt; break; }
            }
        }
        $coverUrl = 'https://guonika.com' . $coverPath;

        // 构建 meta 块
        $tEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $dEsc = htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');
        $cEsc = htmlspecialchars($canon, ENT_QUOTES, 'UTF-8');
        $iEsc = htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8');

        $ogType = ($section === 'flagship' || $section === 'queries') ? 'article' : 'website';

        $metaBlock = ""
            . "<meta property=\"og:type\" content=\"$ogType\">\n"
            . "<meta property=\"og:title\" content=\"$tEsc\">\n"
            . "<meta property=\"og:description\" content=\"$dEsc\">\n"
            . "<meta property=\"og:url\" content=\"$cEsc\">\n"
            . "<meta property=\"og:image\" content=\"$iEsc\">\n"
            . "<meta property=\"og:site_name\" content=\"全球工业产业链\">\n"
            . "<meta property=\"og:locale\" content=\"zh_CN\">\n"
            . "<meta name=\"twitter:card\" content=\"summary_large_image\">\n"
            . "<meta name=\"twitter:title\" content=\"$tEsc\">\n"
            . "<meta name=\"twitter:description\" content=\"$dEsc\">\n"
            . "<meta name=\"twitter:image\" content=\"$iEsc\">\n";

        // 若 force：剥离旧的
        if ($this->force) {
            $html = preg_replace('#<meta property="og:[^"]+"[^>]*>\s*#', '', $html);
            $html = preg_replace('#<meta name="twitter:[^"]+"[^>]*>\s*#', '', $html);
        }

        // 注入：在 canonical 之后；fallback 在 description 之后；最末 fallback 在 </head> 前
        $cnt = 0;
        $newHtml = preg_replace(
            '#(<link rel="canonical"[^>]*>)#',
            '$1' . "\n" . $metaBlock,
            $html,
            1,
            $cnt
        );
        if ($cnt !== 1) {
            $newHtml = preg_replace(
                '#(<meta name="description"[^>]*>)#',
                '$1' . "\n" . $metaBlock,
                $html,
                1,
                $cnt
            );
        }
        if ($cnt !== 1) {
            $newHtml = preg_replace('#</head>#', $metaBlock . '</head>', $html, 1, $cnt);
        }
        if ($cnt !== 1) {
            $this->failCount++;
            fprintf(STDERR, "FAIL no anchor: $section/$base\n");
            return;
        }

        if ($this->dryRun) {
            $this->okCount++;
            return;
        }

        file_put_contents($file, $newHtml);
        $this->okCount++;
    }
}

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)=(.+)$/', $arg, $m)) $opts[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z\-]+)$/', $arg, $m)) $opts[$m[1]] = true;
}
(new TopicSocialMetaInjector($opts))->run();
