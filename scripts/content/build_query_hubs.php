#!/usr/bin/env php
<?php
/**
 * 查询词聚合 Hub 静态生成器（不调 LLM，纯 PDO 聚合）
 *
 * 解决：top 重复 query（同关键词被分散到 20-46 篇）造成 cannibalization
 * 方案：每个高频 query 聚合所有相关帖子到一个 hub，主推 hub URL，老帖向 hub 内链聚拢
 *
 * 输出：
 *   /topics/queries/{slug}.html        单个 query 聚合页
 *   /topics/queries/index.html         总览
 *
 * 用法：
 *   php scripts/build_query_hubs.php --min-count=20 --max-queries=80
 *   php scripts/build_query_hubs.php --only=喷码打印机 --force
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

final class QueryHubBuilder
{
    private const OUT_DIR = '/topics/queries';
    private const LOG_FILE = '/runtime/build_query_hubs.log';

    private \PDO $pdo;
    private int $minCount;
    private int $maxQueries;
    private bool $force;
    private ?string $only;

    public function __construct(array $opts)
    {
        $this->pdo = new \PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
        );
        $this->minCount = max(5, (int)($opts['min-count'] ?? 20));
        $this->maxQueries = max(1, (int)($opts['max-queries'] ?? 80));
        $this->force = !empty($opts['force']);
        $this->only = isset($opts['only']) ? (string)$opts['only'] : null;
    }

    public function run(): void
    {
        $outDir = ROOT_PATH . self::OUT_DIR;
        if (!is_dir($outDir)) @mkdir($outDir, 0775, true);

        $rows = $this->fetchTopQueries();
        if ($this->only) {
            $rows = array_values(array_filter($rows, fn($r) => $r['query'] === $this->only));
        }
        $this->log(sprintf('candidate queries: %d (min_count=%d, max=%d)',
            count($rows), $this->minCount, $this->maxQueries));

        $stats = ['ok' => 0, 'skip' => 0, 'fail' => 0, 'links' => 0];
        $built = [];
        foreach ($rows as $r) {
            $q = (string)$r['query'];
            $cnt = (int)$r['c'];
            $slug = $this->slugFor($q);
            $path = $outDir . '/' . $slug . '.html';
            if (!$this->force && is_file($path) && filesize($path) > 6000) {
                $stats['skip']++;
                $built[] = ['query' => $q, 'slug' => $slug, 'count' => $cnt];
                continue;
            }
            try {
                $posts = $this->fetchPosts($q);
                if (count($posts) < 5) {
                    $stats['skip']++;
                    $this->log("SKIP $q: only " . count($posts) . " posts");
                    continue;
                }
                $html = $this->renderPage($q, $slug, $posts, $cnt);
                file_put_contents($path, $html);
                $built[] = ['query' => $q, 'slug' => $slug, 'count' => $cnt, 'used' => count($posts)];
                $stats['ok']++;
                $stats['links'] += count($posts);
                $this->log("OK $q → $slug.html (" . count($posts) . " linked, " . strlen($html) . " bytes)");
            } catch (\Throwable $e) {
                $stats['fail']++;
                $this->log("FAIL $q: " . $e->getMessage());
            }
        }
        if ($built) $this->writeIndex($built);
        $this->log('STATS ' . json_encode($stats, JSON_UNESCAPED_UNICODE));
    }

    private function fetchTopQueries(): array
    {
        $sql = "SELECT query, COUNT(*) c FROM posts
                WHERE query IS NOT NULL AND CHAR_LENGTH(query) BETWEEN 2 AND 24
                  AND query NOT LIKE '%加盟加盟%'
                  AND query NOT LIKE '%快速快速%'
                  AND query <> 'a标签'
                GROUP BY query
                HAVING c >= :min
                ORDER BY c DESC
                LIMIT :n";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':min', $this->minCount, \PDO::PARAM_INT);
        $st->bindValue(':n', $this->maxQueries, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    private function fetchPosts(string $q): array
    {
        // 同一 query 下所有帖子；优先 content_json 长的（更高质量）
        $sql = "SELECT id, title, summary, category, query, created_at, LENGTH(content_json) AS clen
                FROM posts
                WHERE query = :q
                ORDER BY clen DESC, id DESC
                LIMIT 60";
        $st = $this->pdo->prepare($sql);
        $st->execute([':q' => $q]);
        return $st->fetchAll();
    }

    private function slugFor(string $q): string
    {
        // 中文 query → md5 后 12 位（稳定可缓存）
        $ascii = preg_replace('/[^a-zA-Z0-9]+/', '-', $q);
        $ascii = trim((string)$ascii, '-');
        if ($ascii !== '' && preg_match('/[a-zA-Z]/', $ascii) && strlen($ascii) >= 4) {
            return strtolower($ascii);
        }
        return 'q-' . substr(md5($q), 0, 12);
    }

    private function renderPage(string $q, string $slug, array $posts, int $totalCount): string
    {
        $qH = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars("{$q}专题：选型、参数、价格与厂家询价指南合集（{$totalCount}+ 篇）", ENT_QUOTES, 'UTF-8');
        $h1 = htmlspecialchars("{$q}专题聚合：从选型到询价的完整内容", ENT_QUOTES, 'UTF-8');
        $url = "https://guonika.com/topics/queries/{$slug}.html";

        $intro = $this->buildIntro($q, $totalCount, count($posts));
        $description = htmlspecialchars(mb_substr(strip_tags($intro), 0, 150, 'UTF-8'), ENT_QUOTES, 'UTF-8');

        // 主文（按内容长度排序）
        $cardsHtml = '';
        $itemListItems = [];
        $pos = 1;
        foreach ($posts as $p) {
            $pid = (int)$p['id'];
            $pTitle = trim((string)$p['title']);
            $pSummary = trim((string)$p['summary']);
            if ($pTitle === '') continue;
            $pTitleH = htmlspecialchars($pTitle, ENT_QUOTES, 'UTF-8');
            $pSumH = htmlspecialchars(mb_substr($pSummary, 0, 90, 'UTF-8'), ENT_QUOTES, 'UTF-8');
            $cat = trim((string)($p['category'] ?? ''));
            $catH = $cat !== '' ? htmlspecialchars(mb_substr($cat, 0, 8, 'UTF-8'), ENT_QUOTES, 'UTF-8') : '';
            $date = isset($p['created_at']) ? substr((string)$p['created_at'], 0, 10) : '';
            $clen = (int)($p['clen'] ?? 0);
            $depthBadge = $clen >= 2000 ? '<span class="qhub-badge-deep">深度长文</span>'
                         : ($clen >= 1500 ? '<span class="qhub-badge-mid">完整指南</span>' : '');

            $catBadge = $catH !== '' ? "<span class=\"qhub-card-cat\">{$catH}</span>" : '';
            $dateBadge = $date !== '' ? "<span class=\"qhub-card-date\">{$date}</span>" : '';

            $cardsHtml .= "<a class=\"qhub-card\" href=\"/news/{$pid}\">
<div class=\"qhub-card-head\">{$catBadge}{$depthBadge}</div>
<h3>{$pTitleH}</h3>
<p>{$pSumH}</p>
<div class=\"qhub-card-meta\">{$dateBadge}</div>
</a>";
            $itemListItems[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'url' => "https://guonika.com/news/{$pid}",
                'name' => $pTitle,
            ];
        }

        // 同站交叉链接：从 posts 里捞 5 个不同 category 的 hub
        $crossHtml = $this->renderCrossLinks($q, $posts);

        $itemListSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => "{$q} - 专题聚合",
            'numberOfItems' => count($itemListItems),
            'itemListElement' => $itemListItems,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $breadcrumbSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => 'https://guonika.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => '专题聚合', 'item' => 'https://guonika.com/topics/queries/index.html'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $q, 'item' => $url],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $cpSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => "{$q}专题聚合",
            'description' => mb_substr(strip_tags($intro), 0, 150, 'UTF-8'),
            'url' => $url,
            'inLanguage' => 'zh-CN',
            'about' => $q,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>{$title} - 全球工业产业链</title>
<meta name="description" content="{$description}">
<meta name="keywords" content="{$qH},{$qH}选型,{$qH}价格,{$qH}厂家,{$qH}询价">
<link rel="canonical" href="{$url}">
<link rel="alternate" hreflang="zh-CN" href="{$url}">
<link rel="stylesheet" href="/assets/css/retro2013.css?v=1">
<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/font/bootstrap-icons.css">
<link rel="icon" href="/favicon.ico" sizes="any">
<style>
.qhub-shell{max-width:1280px;margin:0 auto;padding:18px 14px 40px}
.qhub-hero{background:linear-gradient(125deg,#3a2400 0%,#7a4f00 60%,#c9a45c 100%);color:#fff;padding:30px 28px;margin-bottom:14px;border-radius:0}
.qhub-hero .kicker{display:inline-block;background:rgba(0,0,0,.18);border:1px solid rgba(255,255,255,.3);font-size:12px;padding:2px 10px;margin-bottom:8px}
.qhub-hero h1{margin:0 0 10px;font-size:26px;line-height:1.4}
.qhub-hero p{margin:0 0 8px;line-height:1.85;color:rgba(255,255,255,.94);font-size:14px}
.qhub-cta-bar{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.qhub-cta-bar a{display:inline-block;padding:7px 14px;border:1px solid rgba(255,255,255,.4);background:rgba(255,255,255,.12);color:#fff;font-size:13px;text-decoration:none;border-radius:0}
.qhub-cta-bar a.primary{background:#fff;color:#3a2400;border-color:#fff;font-weight:600}
.qhub-cta-bar a:hover{background:#fff;color:#3a2400;border-color:#fff}
.qhub-quick{background:#fff8f0;border:1px solid #f4d97f;padding:14px 18px;margin-bottom:14px}
.qhub-quick h3{margin:0 0 8px;font-size:14px;color:#7a5b00}
.qhub-quick-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px}
.qhub-quick-grid div{font-size:13px;color:#444;line-height:1.7;padding:4px 0}
.qhub-quick-grid strong{color:#7a5b00;margin-right:6px}
.qhub-section-title{margin:24px 0 12px;font-size:18px;color:#1f3a63;border-bottom:2px solid #f4d97f;padding-bottom:6px}
.qhub-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:10px}
.qhub-card{display:block;background:#fff;border:1px solid #e0e4ea;padding:12px 14px;text-decoration:none;color:inherit;transition:border-color .15s}
.qhub-card:hover{border-color:#c9a45c}
.qhub-card-head{display:flex;gap:6px;margin-bottom:6px;flex-wrap:wrap}
.qhub-card-cat{display:inline-block;padding:1px 7px;background:#f0f4fa;border:1px solid #d0dce8;color:#1f3a63;font-size:11px}
.qhub-badge-deep{display:inline-block;padding:1px 7px;background:#fff3df;border:1px solid #f4ba3a;color:#a06a07;font-size:11px}
.qhub-badge-mid{display:inline-block;padding:1px 7px;background:#eef7f1;border:1px solid #b6d8c2;color:#2e7045;font-size:11px}
.qhub-card h3{margin:0 0 6px;font-size:14px;color:#1f3a63;line-height:1.55;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.qhub-card p{margin:0 0 6px;color:#5d6f84;font-size:12px;line-height:1.6;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:38px}
.qhub-card-meta{font-size:11px;color:#aab2bd}
.qhub-cross{margin-top:24px;background:#fafbfc;border:1px solid #e5e7eb;padding:16px 18px}
.qhub-cross h3{margin:0 0 10px;font-size:15px;color:#1f3a63}
.qhub-cross-list{display:flex;flex-wrap:wrap;gap:8px}
.qhub-cross-list a{display:inline-block;padding:6px 12px;background:#fff;border:1px solid #d0d6dd;color:#1f3a63;font-size:13px;text-decoration:none}
.qhub-cross-list a:hover{background:#1f3a63;color:#fff;border-color:#1f3a63}
.qhub-foot-cta{margin-top:24px;background:#0b1623;color:#fff;padding:22px 24px;text-align:center}
.qhub-foot-cta h3{margin:0 0 8px;font-size:16px;color:#f4ba3a}
.qhub-foot-cta p{margin:0 0 12px;font-size:13px;color:rgba(255,255,255,.9)}
.qhub-foot-cta a{display:inline-block;padding:8px 18px;background:#c9a45c;color:#fff;font-size:13px;text-decoration:none;border:1px solid #c9a45c;margin:0 4px}
.qhub-foot-cta a:hover{background:#fff;color:#3a2400;border-color:#fff}
@media (max-width:600px){.qhub-hero h1{font-size:20px}.qhub-grid{grid-template-columns:1fr}}
</style>
<script type="application/ld+json">{$cpSchema}</script>
<script type="application/ld+json">{$breadcrumbSchema}</script>
<script type="application/ld+json">{$itemListSchema}</script>
</head>
<body class="topic-static-page topic-query-hub">
<header class="top-bar bg-primary text-white py-2"><div class="container"><div class="row align-items-center"><div class="col-md-7"><span>欢迎来到全球工业产业链</span></div><div class="col-md-5 text-end"><span>客服热线：400-880-6688</span></div></div></div></header>
<nav class="navbar bg-white" style="border-bottom:1px solid #e5e7eb;padding:8px 0"><div class="container"><a href="/" style="font-weight:bold;color:#1f3a63;text-decoration:none;font-size:18px">全球工业产业链</a> &nbsp;<a href="/products" style="color:#444;text-decoration:none;margin:0 6px">产品</a><a href="/companies" style="color:#444;text-decoration:none;margin:0 6px">公司</a><a href="/news" style="color:#444;text-decoration:none;margin:0 6px">资讯</a><a href="/topics/quotes/index.html" style="color:#444;text-decoration:none;margin:0 6px">行情</a><a href="/topics/categories/index.html" style="color:#444;text-decoration:none;margin:0 6px">行业聚合</a><a href="/topics/queries/index.html" style="color:#c9302c;text-decoration:none;margin:0 6px">专题聚合</a></div></nav>
<main class="qhub-shell">
<nav class="qhub-breadcrumb" aria-label="breadcrumb" style="font-size:12px;color:#7a8aa0;margin:6px 0 10px"><a href="/" style="color:#1f3a63;text-decoration:none">首页</a> / <a href="/topics/queries/index.html" style="color:#1f3a63;text-decoration:none">关键词专题</a> / <span style="color:#7a5b00">{$qH}</span></nav>
<div class="qhub-hero">
<span class="kicker">关键词专题 · {$qH}</span>
<h1>{$h1}</h1>
{$intro}
<div class="qhub-cta-bar">
<a class="primary" href="/products?q={$qH}"><i class="bi bi-box-seam"></i> 立即查 {$qH} 产品</a>
<a href="/companies?q={$qH}"><i class="bi bi-buildings"></i> {$qH} 厂家</a>
<a href="/news?q={$qH}"><i class="bi bi-newspaper"></i> {$qH} 资讯</a>
<a href="/trade?q={$qH}"><i class="bi bi-bag-check"></i> {$qH} 商机</a>
</div>
</div>

<div class="qhub-quick">
<h3><i class="bi bi-lightning"></i> 一句话速览</h3>
<div class="qhub-quick-grid">
<div><strong>关键词</strong>{$qH}</div>
<div><strong>站内沉淀</strong>{$totalCount} 篇专属内容</div>
<div><strong>本页精选</strong>按质量与时效排序的前 {$pos} 篇</div>
<div><strong>采购入口</strong>产品 / 厂家 / 商机三联跳转</div>
</div>
</div>

<h2 class="qhub-section-title"><i class="bi bi-collection"></i> {$qH} 内容合集</h2>
<div class="qhub-grid">{$cardsHtml}</div>

{$crossHtml}

<div class="qhub-foot-cta">
<h3>{$qH} 询价 · 一站式厂家对接</h3>
<p>找不到合适规格？平台覆盖 {$qH} 上下游产业链，可帮你定向匹配厂家与报价。</p>
<a href="/products?q={$qH}">浏览 {$qH} 产品库</a>
<a href="/companies?q={$qH}">联系 {$qH} 厂家</a>
<a href="tel:400-880-6688">拨打 400-880-6688</a>
</div>
</main>
<footer style="background:#0b1623;color:#aab2bd;padding:18px 14px;text-align:center;font-size:12px"><div>&copy; 全球工业产业链 · <a href="/" style="color:#aab2bd;text-decoration:none">首页</a> · <a href="/topics/queries/index.html" style="color:#aab2bd;text-decoration:none">专题聚合</a> · 豫ICP备2023034280号-2</div></footer>
</body>
</html>
HTML;
    }

    private function buildIntro(string $q, int $totalCount, int $usedCount): string
    {
        $totalH = number_format($totalCount);
        $usedH = number_format($usedCount);
        $line1 = "本页围绕「{$q}」这一关键词，把站内累计沉淀的 {$totalH} 篇相关内容收口到一个聚合中转站，并按内容深度（content_json 长度）与发布时效精选了前 {$usedH} 篇覆盖选型、参数、价格、询价、采购清单等典型决策环节的专属内容。";
        $line2 = "如果你正在采购「{$q}」、做技术选型、对比厂家或撰写询价单，建议先用顶部的「产品 / 厂家 / 资讯 / 商机」四个 CTA 进入对应的实物入口，再回到本页通过下方卡片继续深读对应主题的指南文章；同一关键词下的内容已在本页统一收口，避免在搜索引擎中互相竞价分散权重。";
        $h1 = htmlspecialchars($line1, ENT_QUOTES, 'UTF-8');
        $h2 = htmlspecialchars($line2, ENT_QUOTES, 'UTF-8');
        return "<p>{$h1}</p><p>{$h2}</p>";
    }

    private function renderCrossLinks(string $q, array $posts): string
    {
        // 从 posts 里取出现的 category，每类一个 hub 链接
        $cats = [];
        foreach ($posts as $p) {
            $c = trim((string)($p['category'] ?? ''));
            if ($c === '') continue;
            $cats[$c] = ($cats[$c] ?? 0) + 1;
        }
        arsort($cats);
        $cats = array_slice($cats, 0, 8, true);

        $items = '';
        foreach ($cats as $cat => $n) {
            // 类目 hub URL（与 build_category_hubs.php 一致）
            $slug = $this->categorySlug($cat);
            $catH = htmlspecialchars($cat, ENT_QUOTES, 'UTF-8');
            $items .= "<a href=\"/topics/categories/{$slug}.html\">{$catH} 行业聚合</a>";
        }
        // 同时给 4 个相邻 query 跳转
        $sql = "SELECT query, COUNT(*) c FROM posts
                WHERE query IS NOT NULL AND CHAR_LENGTH(query) BETWEEN 2 AND 24
                  AND query <> :q
                  AND query NOT LIKE '%加盟加盟%'
                  AND query NOT LIKE '%快速快速%'
                GROUP BY query
                ORDER BY c DESC
                LIMIT 12";
        $st = $this->pdo->prepare($sql);
        $st->execute([':q' => $q]);
        foreach ($st->fetchAll() as $r) {
            $oq = (string)$r['query'];
            $oqH = htmlspecialchars($oq, ENT_QUOTES, 'UTF-8');
            $oslug = $this->slugFor($oq);
            $items .= "<a href=\"/topics/queries/{$oslug}.html\">{$oqH}</a>";
        }
        return "<div class=\"qhub-cross\"><h3><i class=\"bi bi-diagram-3\"></i> 相关行业聚合 & 其他高频关键词</h3><div class=\"qhub-cross-list\">{$items}</div></div>";
    }

    /**
     * 复用类目 slug 规则（必须与 build_category_hubs.php 保持一致）
     */
    private function categorySlug(string $name): string
    {
        static $map = [
            '制造' => 'manufacturing', '制造业' => 'manufacturing-industry', '采购' => 'purchasing',
            '供应' => 'supply', '物流' => 'logistics', '工具' => 'tools', '行情' => 'quotes',
            '实用指南' => 'practical-guide', '工业自动化' => 'industrial-automation',
            '价格费用参考' => 'price-reference', '操作方法教程' => 'how-to-guide',
            '产品应用指南' => 'product-application', '工艺流程指南' => 'process-guide',
            '汽车制造业' => 'auto-manufacturing', '分类认知指南' => 'category-overview',
            '机械设备' => 'machinery-equipment', '电子电工' => 'electronics',
            '化工及能源' => 'chemicals-energy', '金属材料' => 'metal-materials', '应用' => 'applications',
            '建材' => 'building-materials', '建筑材料' => 'construction-materials',
            '食品' => 'food', '食品加工工业' => 'food-processing', '农业' => 'agriculture',
            '纺织' => 'textile', '医药' => 'pharma', '制药工业' => 'pharma-industry',
            '环保' => 'environment', '能源' => 'energy', '仪器仪表' => 'instruments',
            '安防' => 'security', '包装' => 'packaging', '工程机械' => 'construction-machinery',
            '机械制造' => 'machinery-manufacturing', '机械工程' => 'mechanical-engineering',
            '工业设备' => 'industrial-equipment', '家电制造业' => 'home-appliance',
            '汽车工业' => 'auto-industry', '电子制造业' => 'electronics-manufacturing',
            '电气工程' => 'electrical-engineering', '智能制造' => 'smart-manufacturing',
            '电力' => 'power', '橡塑' => 'rubber-plastic', '商务服务' => 'business-services',
            '汽车' => 'automotive', '资讯' => 'news-articles', '行业资讯' => 'industry-news',
            '新闻' => 'news', '产品' => 'products-overview', '设备' => 'equipment',
            '采购联系指南' => 'procurement-contact-guide', '选购对比指南' => 'comparison-guide',
            '规格参数指南' => 'spec-parameter-guide', '产品价格指南' => 'product-price-guide',
        ];
        if (isset($map[$name])) return $map[$name];
        $ascii = preg_replace('/[^a-zA-Z0-9]+/', '-', $name);
        $ascii = trim((string)$ascii, '-');
        if ($ascii !== '' && preg_match('/[a-zA-Z]/', $ascii)) return strtolower($ascii);
        return 'c-' . substr(md5($name), 0, 10);
    }

    private function writeIndex(array $built): void
    {
        $outDir = ROOT_PATH . self::OUT_DIR;
        usort($built, static fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
        $count = count($built);

        $cardsHtml = '';
        $listItems = [];
        $pos = 1;
        foreach ($built as $b) {
            $q = (string)$b['query'];
            $slug = (string)$b['slug'];
            $cnt = (int)$b['count'];
            $url = "https://guonika.com/topics/queries/{$slug}.html";
            $qH = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
            $cntH = number_format($cnt);
            $cardsHtml .= "<a class=\"qhub-idx-card\" href=\"/topics/queries/{$slug}.html\">
<span class=\"qhub-idx-kicker\">关键词专题</span>
<h3>{$qH}</h3>
<span class=\"qhub-idx-count\">{$cntH} 篇相关内容</span>
</a>";
            $listItems[] = ['@type' => 'ListItem', 'position' => $pos++, 'url' => $url, 'name' => $q];
        }

        $listSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => '关键词专题聚合',
            'numberOfItems' => $count,
            'itemListElement' => $listItems,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $breadcrumbSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => 'https://guonika.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => '专题聚合', 'item' => 'https://guonika.com/topics/queries/index.html'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>关键词专题聚合 · {$count} 个高频工业采购词总览 - 全球工业产业链</title>
<meta name="description" content="覆盖喷码打印机、冷油机、动平衡仪、咖啡机、洗碗机、水合肼等 {$count} 个高频工业采购关键词的专题聚合，每个关键词独立 hub 收口同主题内容。">
<meta name="keywords" content="关键词专题,工业采购,选型指南,关键词聚合,高频词">
<link rel="canonical" href="https://guonika.com/topics/queries/index.html">
<link rel="stylesheet" href="/assets/css/retro2013.css?v=1">
<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/font/bootstrap-icons.css">
<link rel="icon" href="/favicon.ico" sizes="any">
<style>
.qhub-idx-shell{max-width:1280px;margin:0 auto;padding:18px 14px 40px}
.qhub-idx-hero{background:linear-gradient(125deg,#3a2400 0%,#7a4f00 100%);color:#fff;padding:32px 28px;margin-bottom:18px;border-radius:0}
.qhub-idx-hero h1{margin:0 0 12px;font-size:30px;line-height:1.3}
.qhub-idx-hero p{margin:0;line-height:1.85;color:rgba(255,255,255,.92);font-size:14px}
.qhub-idx-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.qhub-idx-card{display:flex;flex-direction:column;justify-content:flex-end;min-height:120px;padding:14px 16px;background:#fff;color:#1f3a63;text-decoration:none;border:1px solid #e0e4ea;transition:all .15s}
.qhub-idx-card:hover{background:#fff8f0;border-color:#c9a45c;color:#1f3a63;transform:translateY(-2px)}
.qhub-idx-kicker{display:inline-block;background:#fff3df;border:1px solid #f4ba3a;color:#a06a07;font-size:11px;padding:1px 8px;align-self:flex-start;margin-bottom:8px}
.qhub-idx-card h3{margin:0 0 6px;font-size:16px;line-height:1.4;color:#1f3a63}
.qhub-idx-count{font-size:12px;color:#7a8aa0}
@media (max-width:600px){.qhub-idx-hero h1{font-size:22px}.qhub-idx-grid{grid-template-columns:repeat(auto-fit,minmax(160px,1fr))}}
</style>
<script type="application/ld+json">{$listSchema}</script>
<script type="application/ld+json">{$breadcrumbSchema}</script>
</head>
<body class="topic-static-page topic-query-hub-index">
<header class="top-bar bg-primary text-white py-2"><div class="container"><div class="row align-items-center"><div class="col-md-7"><span>欢迎来到全球工业产业链</span></div><div class="col-md-5 text-end"><span>客服热线：400-880-6688</span></div></div></div></header>
<nav class="navbar bg-white" style="border-bottom:1px solid #e5e7eb;padding:8px 0"><div class="container"><a href="/" style="font-weight:bold;color:#1f3a63;text-decoration:none;font-size:18px">全球工业产业链</a> &nbsp;<a href="/products" style="color:#444;text-decoration:none;margin:0 6px">产品</a><a href="/companies" style="color:#444;text-decoration:none;margin:0 6px">公司</a><a href="/news" style="color:#444;text-decoration:none;margin:0 6px">资讯</a><a href="/topics/quotes/index.html" style="color:#444;text-decoration:none;margin:0 6px">行情</a><a href="/topics/categories/index.html" style="color:#444;text-decoration:none;margin:0 6px">行业聚合</a><a href="/topics/queries/index.html" style="color:#c9302c;text-decoration:none;margin:0 6px">专题聚合</a></div></nav>
<main class="qhub-idx-shell">
<div class="qhub-idx-hero">
<h1>关键词专题聚合 · {$count} 个高频词</h1>
<p>覆盖喷码打印机、冷油机、动平衡仪、咖啡机、洗碗机、水合肼等 {$count} 个高频工业采购关键词。每个关键词独立 hub 收口同主题内容，避免内容内耗、并将搜索流量集中到一个权威页面，再向产品库、厂家黄页与商机询盘三个实物入口分发。</p>
</div>
<div class="qhub-idx-grid">{$cardsHtml}</div>
</main>
<footer style="background:#0b1623;color:#aab2bd;padding:18px 14px;text-align:center;font-size:12px"><div>&copy; 全球工业产业链 · <a href="/" style="color:#aab2bd;text-decoration:none">首页</a> · 豫ICP备2023034280号-2</div></footer>
</body>
</html>
HTML;
        file_put_contents($outDir . '/index.html', $html);
        $this->log("INDEX written count={$count}");
    }

    private function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        $path = ROOT_PATH . self::LOG_FILE;
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, $line, FILE_APPEND);
        fwrite(STDERR, $line);
    }
}

// CLI
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)=(.+)$/', $arg, $m)) $opts[$m[1]] = $m[2];
    elseif (preg_match('/^--([a-z\-]+)$/', $arg, $m)) $opts[$m[1]] = true;
}

(new QueryHubBuilder($opts))->run();
