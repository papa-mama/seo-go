<?php
/**
 * SEO 自检脚本 - seo_audit.php
 *
 * 对照 references/site-audit.md 与 references/baidu-algorithms.md，扫描线上状态。
 * 每项给出 ✅/⚠️/❌ 三态，最后给出 P0/P1/P2 待办清单。
 *
 * 用法：
 *   php scripts/seo_audit.php                    # 全量
 *   php scripts/seo_audit.php --section=A         # 仅跑 A 区
 *   php scripts/seo_audit.php --json             # JSON 输出
 *   php scripts/seo_audit.php --url=https://guonika.com/topics/q-xxx.html  # 抽检具体 URL
 */

require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../includes/functions.php';

@date_default_timezone_set('Asia/Shanghai');

$opts = getopt('', ['section::', 'json::', 'url::']);
$section = isset($opts['section']) ? strtoupper((string)$opts['section']) : '';
$asJson = isset($opts['json']);
$probeUrl = $opts['url'] ?? '';

$db = getDB();
$root = realpath(__DIR__ . '/../../../../');
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://guonika.com';
$probeBase = 'http://127.0.0.1:18888'; // 本机端口，绕过外网解析与 SSL
$hostHeader = parse_url($siteUrl, PHP_URL_HOST) ?: 'guonika.com';
$year = date('Y');

function fetchLocal(string $path, string $host): string {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "Host: $host\r\nUser-Agent: SEO-Audit-Bot/1.0\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $r = @file_get_contents('http://127.0.0.1:18888' . $path, false, $ctx);
    return $r !== false ? $r : '';
}

$results = []; // [['section'=>'A','code'=>'A1','title'=>'...','status'=>'ok|warn|fail','msg'=>'']]

function check(string $sec, string $code, string $title, string $status, string $msg = ''): array {
    return ['section' => $sec, 'code' => $code, 'title' => $title, 'status' => $status, 'msg' => $msg];
}

function st(bool $ok, string $okMsg = '', string $failMsg = ''): array {
    return [$ok ? 'ok' : 'fail', $ok ? $okMsg : $failMsg];
}

// ============== A. 基础元信息 ==============
if ($section === '' || $section === 'A') {
    // A1: 首页 200 + title
    $home = fetchLocal('/', $hostHeader);
    $titleOk = $home && preg_match('/<title>([^<]+)<\/title>/u', $home, $m) && mb_strlen(trim($m[1])) > 0;
    $titleLen = $titleOk ? mb_strlen(trim($m[1])) : 0;
    $results[] = check('A', 'A1', '首页 title 存在且 ≤ 60 字',
        $titleOk && $titleLen <= 60 ? 'ok' : 'warn',
        "len=$titleLen");

    $descOk = $home && preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)/u', $home, $m);
    $descLen = $descOk ? mb_strlen(trim($m[1])) : 0;
    $results[] = check('A', 'A2', '首页 description 80-160 字',
        $descLen >= 80 && $descLen <= 200 ? 'ok' : 'warn',
        "len=$descLen");

    $canonOk = $home && preg_match('/<link[^>]+rel=["\']canonical["\']/u', $home);
    $results[] = check('A', 'A3', '首页有 canonical', $canonOk ? 'ok' : 'fail');

    $viewportOk = $home && preg_match('/<meta\s+name=["\']viewport["\']/u', $home);
    $results[] = check('A', 'A4', '首页有 viewport', $viewportOk ? 'ok' : 'fail');
}

// ============== B. URL 与 robots ==============
if ($section === '' || $section === 'B') {
    $robotsFile = $root . '/robots.txt';
    $hasRobots = is_file($robotsFile);
    $robotsContent = $hasRobots ? file_get_contents($robotsFile) : '';
    $results[] = check('B', 'B1', 'robots.txt 存在', $hasRobots ? 'ok' : 'fail');

    $blocksSearch = $hasRobots && stripos($robotsContent, 'Disallow: /search') !== false;
    $results[] = check('B', 'B2', 'robots.txt 屏蔽 /search', $blocksSearch ? 'ok' : 'warn');

    $blocksAdmin = $hasRobots && (stripos($robotsContent, 'Disallow: /admin') !== false || stripos($robotsContent, 'Disallow: /api') !== false);
    $results[] = check('B', 'B3', 'robots.txt 屏蔽 /admin /api', $blocksAdmin ? 'ok' : 'warn');

    $hasSitemapDirective = $hasRobots && stripos($robotsContent, 'Sitemap:') !== false;
    $results[] = check('B', 'B4', 'robots.txt 有 Sitemap 指令', $hasSitemapDirective ? 'ok' : 'warn');

    $sitemapFile = $root . '/sitemap.xml';
    $hasSitemap = is_file($sitemapFile) && filesize($sitemapFile) > 100;
    $results[] = check('B', 'B5', 'sitemap.xml 存在且非空', $hasSitemap ? 'ok' : 'warn',
        $hasSitemap ? 'size=' . round(filesize($sitemapFile) / 1024) . 'KB' : '');
}

// ============== C. 内容质量 ==============
if ($section === '' || $section === 'C') {
    // C1: posts 总量 vs source_keyword 覆盖率
    $totalPosts = (int)$db->fetchOne("SELECT COUNT(*) c FROM posts")['c'];
    $withKw = (int)$db->fetchOne("SELECT COUNT(*) c FROM posts WHERE source_keyword IS NOT NULL AND source_keyword<>''")['c'];
    $cov = $totalPosts > 0 ? $withKw * 100 / $totalPosts : 0;
    $results[] = check('C', 'C1', 'posts.source_keyword 覆盖率 ≥ 30%',
        $cov >= 30 ? 'ok' : ($cov >= 5 ? 'warn' : 'fail'),
        sprintf("当前 %.2f%% (%d/%d)", $cov, $withKw, $totalPosts));

    // C2: 模板未渲染 (% 或 {} 残留)
    $broken = (int)$db->fetchOne("SELECT COUNT(*) c FROM posts WHERE title LIKE '%{%' OR title LIKE '%}%'")['c'];
    $results[] = check('C', 'C2', 'posts 标题无未替换模板',
        $broken === 0 ? 'ok' : 'warn',
        "$broken 条标题含 {} 残留");

    // C3: trade_leads 价格合理性
    try {
        $weird = (int)$db->fetchOne("SELECT COUNT(*) c FROM trade_leads WHERE (price_min > 0 AND price_max > 0 AND price_min > price_max)")['c'];
        $results[] = check('C', 'C3', 'trade_leads 价格区间合理 (min ≤ max)',
            $weird === 0 ? 'ok' : 'fail',
            "$weird 条 min > max");
    } catch (Throwable $e) {
        $results[] = check('C', 'C3', 'trade_leads 价格区间合理',  'warn', '表/字段不存在');
    }

    // C4: 标题年份动态化（抽样首页）
    if (isset($home)) {
        $titleHasYear = (bool)preg_match('/' . $year . '/', (string)$home);
        $results[] = check('C', 'C4', '首页含当年年份 ' . $year,
            $titleHasYear ? 'ok' : 'warn');
    }
}

// ============== D. 内链密度 ==============
if ($section === '' || $section === 'D') {
    // 抽 1 个 trade 详情页测内链
    $sampleTrade = $db->fetchOne("SELECT id FROM trade_leads ORDER BY id DESC LIMIT 1");
    if ($sampleTrade) {
        $h = fetchLocal('/trade/' . $sampleTrade['id'], $hostHeader);
        if ($h) {
            $links = substr_count($h, 'href=');
            $results[] = check('D', 'D1', "trade 详情页内链 ≥ 15",
                $links >= 15 ? 'ok' : 'warn',
                "/trade/" . $sampleTrade['id'] . " links=$links");
        } else {
            $results[] = check('D', 'D1', 'trade 详情页可访问', 'fail', '/trade/' . $sampleTrade['id']);
        }
    }
}

// ============== E. 速度与体验 ==============
if ($section === '' || $section === 'E') {
    // CSS 是否带版本号（缓存友好）
    if (isset($home)) {
        $cssVersioned = (bool)preg_match('/retro2013\.css\?v=/u', (string)$home);
        $results[] = check('E', 'E1', '首页 CSS 带版本号',
            $cssVersioned ? 'ok' : 'warn');

        $hasLazyLoading = (bool)preg_match('/loading=["\']lazy["\']/u', (string)$home);
        $results[] = check('E', 'E2', '首页有 lazy 加载', $hasLazyLoading ? 'ok' : 'warn');

        $hasAspectRatio = (bool)preg_match('/aspect-ratio/u', (string)$home);
        $results[] = check('E', 'E3', '首页有 aspect-ratio (避免 CLS)',
            $hasAspectRatio ? 'ok' : 'warn');
    }
}

// ============== F. Schema ==============
if ($section === '' || $section === 'F') {
    if (isset($home)) {
        $hasJsonLd = (bool)preg_match('/<script[^>]+application\/ld\+json/u', (string)$home);
        $results[] = check('F', 'F1', '首页有 JSON-LD schema',
            $hasJsonLd ? 'ok' : 'warn');
    }

    // 抽 1 个长尾页测 schema
    $longtailFiles = glob($root . '/topics/q-*.html');
    if (!empty($longtailFiles)) {
        $sample = $longtailFiles[0];
        $sh = @file_get_contents($sample);
        $hasArticle = $sh && strpos($sh, '"@type":"Article"') !== false;
        $hasBreadcrumb = $sh && strpos($sh, '"@type":"BreadcrumbList"') !== false;
        $results[] = check('F', 'F2', '长尾页含 Article + Breadcrumb schema',
            ($hasArticle && $hasBreadcrumb) ? 'ok' : 'warn',
            basename($sample));
    } else {
        $results[] = check('F', 'F2', '长尾页 schema',
            'warn', '尚无 /topics/q-*.html，请先跑 generate_longtail_pages.php');
    }
}

// ============== G. 百度专属 ==============
if ($section === '' || $section === 'G') {
    $hasPushToken = defined('BAIDU_PUSH_TOKEN') && BAIDU_PUSH_TOKEN !== '';
    $results[] = check('G', 'G1', 'BAIDU_PUSH_TOKEN 已配置',
        $hasPushToken ? 'ok' : 'fail',
        $hasPushToken ? '' : '需在 config.php 加 define(\'BAIDU_PUSH_TOKEN\', ...);');

    // mobile-agent meta
    if (isset($home)) {
        $hasMobAgent = (bool)preg_match('/<meta\s+name=["\']mobile-agent["\']/u', (string)$home);
        $results[] = check('G', 'G2', '首页含 mobile-agent meta',
            $hasMobAgent ? 'ok' : 'warn');
    }

    // baidu push log freshness
    $pushLog = __DIR__ . '/baidu_push.log';
    if (is_file($pushLog)) {
        $age = time() - filemtime($pushLog);
        $results[] = check('G', 'G3', '百度推送日志 < 24h',
            $age < 86400 ? 'ok' : 'warn',
            "last update " . round($age / 3600) . 'h ago');
    } else {
        $results[] = check('G', 'G3', '百度推送日志', 'warn', 'baidu_push.php 还没跑过');
    }
}

// ============== H. 词库流水 ==============
if ($section === '' || $section === 'H') {
    $unmatched = (int)$db->fetchOne("SELECT COUNT(*) c FROM unmatched_keywords WHERE status='pending'")['c'];
    $results[] = check('H', 'H1', 'unmatched_keywords pending < 10000',
        $unmatched < 10000 ? 'ok' : ($unmatched < 30000 ? 'warn' : 'fail'),
        "pending=$unmatched");

    $kwTotal = (int)$db->fetchOne("SELECT COUNT(*) c FROM bd_keyword_mapping")['c'];
    $results[] = check('H', 'H2', '关键词库 ≥ 10万',
        $kwTotal >= 100000 ? 'ok' : 'warn',
        "$kwTotal");

    // bd_keyword_tracking freshness
    $maxTrack = $db->fetchOne("SELECT MAX(created_at) m FROM bd_keyword_tracking")['m'] ?? null;
    if ($maxTrack) {
        $trackAge = time() - strtotime($maxTrack);
        $results[] = check('H', 'H3', 'bd_keyword_tracking 最近 7 天有数据',
            $trackAge < 7 * 86400 ? 'ok' : 'warn',
            "last $maxTrack (" . round($trackAge / 86400) . 'd ago)');
    }

    // 长尾聚合页计数
    $longtailCount = is_dir($root . '/topics') ? count(glob($root . '/topics/q-*.html')) : 0;
    $results[] = check('H', 'H4', '长尾聚合页已生成',
        $longtailCount >= 100 ? 'ok' : ($longtailCount > 0 ? 'warn' : 'fail'),
        "/topics/q-*.html count=$longtailCount");
}

// ============== I. 抽检 URL（如指定）==============
if ($probeUrl !== '') {
    // 把绝对 URL 转为本地 path + Host
    $parts = parse_url($probeUrl);
    $probeHost = $parts['host'] ?? $hostHeader;
    $probePath = ($parts['path'] ?? '/') . (isset($parts['query']) ? ('?' . $parts['query']) : '');
    $h = fetchLocal($probePath, $probeHost);
    if ($h) {
        $size = strlen($h);
        $hasH1 = (bool)preg_match('/<h1[^>]*>[^<]+<\/h1>/u', $h);
        $hasCanon = (bool)preg_match('/<link[^>]+rel=["\']canonical["\']/u', $h);
        $hasSchema = strpos($h, 'application/ld+json') !== false;
        $linkCount = substr_count($h, 'href=');
        $results[] = check('I', 'I1', "[probe] $probeUrl 200 OK",
            'ok',
            "size={$size}b h1=" . ($hasH1 ? 'Y' : 'N') . " canon=" . ($hasCanon ? 'Y' : 'N') . " schema=" . ($hasSchema ? 'Y' : 'N') . " links=$linkCount");
    } else {
        $results[] = check('I', 'I1', "[probe] $probeUrl", 'fail', '抓不到');
    }
}

// =================== 输出 ===================

if ($asJson) {
    echo json_encode(['date' => date('c'), 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit;
}

$icons = ['ok' => '✅', 'warn' => '⚠️', 'fail' => '❌'];
$buckets = ['ok' => 0, 'warn' => 0, 'fail' => 0];

echo "===================================\n";
echo " SEO 自检 · " . date('Y-m-d H:i:s') . "\n";
echo "===================================\n\n";

$cur = '';
foreach ($results as $r) {
    if ($r['section'] !== $cur) {
        $cur = $r['section'];
        echo "\n【$cur】\n";
    }
    $icon = $icons[$r['status']] ?? '?';
    $buckets[$r['status']]++;
    echo "  $icon  [{$r['code']}] {$r['title']}";
    if ($r['msg'] !== '') echo "  -- {$r['msg']}";
    echo "\n";
}

echo "\n-----------------------------------\n";
echo " 通过 {$buckets['ok']} / 警告 {$buckets['warn']} / 失败 {$buckets['fail']}\n";
echo "-----------------------------------\n";

// P0/P1 待办
$todos = [];
foreach ($results as $r) {
    if ($r['status'] === 'fail') $todos[] = "[P0] {$r['code']} {$r['title']} — {$r['msg']}";
}
foreach ($results as $r) {
    if ($r['status'] === 'warn') $todos[] = "[P1] {$r['code']} {$r['title']} — {$r['msg']}";
}
if (!empty($todos)) {
    echo "\n【待办】\n";
    foreach ($todos as $t) echo "  $t\n";
}
echo "\n完整 JSON 输出加 --json\n";
