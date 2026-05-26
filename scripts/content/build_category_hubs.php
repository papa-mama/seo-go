#!/usr/bin/env php
<?php
/**
 * 类目聚合 Hub 静态生成器（不调 LLM，纯 PDO 聚合）
 *
 * 解决：top 大类无聚合 hub 页 → 用现有高质量帖子直接拼出 50 条/类目的"内链中转站"
 *
 * 输出：
 *   /topics/categories/{slug}.html     单个类目页
 *   /topics/categories/index.html      类目总览
 *
 * 用法：
 *   php scripts/build_category_hubs.php --top-n=30 --per-page=50
 *   php scripts/build_category_hubs.php --top-n=30 --per-page=50 --min-posts=100 --force
 *   php scripts/build_category_hubs.php --only=制造 --force
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

final class CategoryHubBuilder
{
    private const OUT_DIR = '/topics/categories';
    private const LOG_FILE = '/runtime/build_category_hubs.log';
    private const MIN_LEN = 1500;

    /** 中文类目 → slug 映射（保证稳定 URL；未命中走 md5 fallback） */
    private const SLUG_MAP = [
        '制造' => 'manufacturing',
        '制造业' => 'manufacturing-industry',
        '采购' => 'purchasing',
        '供应' => 'supply',
        '物流' => 'logistics',
        '工具' => 'tools',
        '行情' => 'quotes',
        '实用指南' => 'practical-guide',
        '工业自动化' => 'industrial-automation',
        '价格费用参考' => 'price-reference',
        '操作方法教程' => 'how-to-guide',
        '产品应用指南' => 'product-application',
        '工艺流程指南' => 'process-guide',
        '汽车制造业' => 'auto-manufacturing',
        '分类认知指南' => 'category-overview',
        '机械设备' => 'machinery-equipment',
        '电子电工' => 'electronics',
        '化工及能源' => 'chemicals-energy',
        '金属材料' => 'metal-materials',
        '应用' => 'applications',
        '建材' => 'building-materials',
        '建筑材料' => 'construction-materials',
        '食品' => 'food',
        '食品加工工业' => 'food-processing',
        '农业' => 'agriculture',
        '纺织' => 'textile',
        '医药' => 'pharma',
        '制药工业' => 'pharma-industry',
        '环保' => 'environment',
        '能源' => 'energy',
        '仪器仪表' => 'instruments',
        '安防' => 'security',
        '包装' => 'packaging',
        '工程机械' => 'construction-machinery',
        '机械制造' => 'machinery-manufacturing',
        '机械工程' => 'mechanical-engineering',
        '工业设备' => 'industrial-equipment',
        '家电制造业' => 'home-appliance',
        '汽车工业' => 'auto-industry',
        '电子制造业' => 'electronics-manufacturing',
        '电气工程' => 'electrical-engineering',
        '智能制造' => 'smart-manufacturing',
        '电力' => 'power',
        '橡塑' => 'rubber-plastic',
        '商务服务' => 'business-services',
        '汽车' => 'automotive',
        '资讯' => 'news-articles',
        '行业资讯' => 'industry-news',
        '新闻' => 'news',
        '产品' => 'products-overview',
        '设备' => 'equipment',
        '采购联系指南' => 'procurement-contact-guide',
        '选购对比指南' => 'comparison-guide',
        '规格参数指南' => 'spec-parameter-guide',
        '产品价格指南' => 'product-price-guide',
    ];

    /** 类目 → 封面图（在 assets/img/cover/ 中） */
    private const COVER_MAP = [
        '制造' => 'industrial', '制造业' => 'industrial', '采购' => 'trade', '供应' => 'logistics',
        '物流' => 'logistics', '工具' => 'industrial', '行情' => 'industrial', '实用指南' => 'industrial',
        '工业自动化' => 'robot', '价格费用参考' => 'industrial', '操作方法教程' => 'industrial',
        '产品应用指南' => 'industrial', '工艺流程指南' => 'industrial', '汽车制造业' => 'industrial',
        '分类认知指南' => 'industrial', '机械设备' => 'pump', '电子电工' => 'cable', '化工及能源' => 'chemical',
        '金属材料' => 'stainless', '应用' => 'industrial', '建材' => 'rebar', '食品' => 'packaging',
        '农业' => 'industrial', '纺织' => 'industrial', '医药' => 'chemical', '环保' => 'water',
        '能源' => 'battery', '仪器仪表' => 'sensor', '安防' => 'industrial', '包装' => 'packaging',
        '工程机械' => 'industrial', '电力' => 'cable', '橡塑' => 'rubber', '商务服务' => 'industrial',
        '汽车' => 'industrial', '资讯' => 'news', '行业资讯' => 'news', '新闻' => 'news',
        '产品' => 'industrial', '设备' => 'industrial',
    ];

    private \PDO $pdo;
    private int $topN;
    private int $perPage;
    private int $minPosts;
    private bool $force;
    private ?string $only;

    public function __construct(array $opts)
    {
        $this->pdo = new \PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
        );
        $this->topN = max(1, (int)($opts['top-n'] ?? 30));
        $this->perPage = max(10, min(80, (int)($opts['per-page'] ?? 50)));
        $this->minPosts = max(20, (int)($opts['min-posts'] ?? 100));
        $this->force = !empty($opts['force']);
        $this->only = isset($opts['only']) ? (string)$opts['only'] : null;
    }

    public function run(): void
    {
        $outDir = ROOT_PATH . self::OUT_DIR;
        if (!is_dir($outDir)) @mkdir($outDir, 0775, true);

        $cats = $this->fetchTopCategories();
        if ($this->only) {
            $cats = array_values(array_filter($cats, fn($c) => $c['category'] === $this->only));
        }
        $this->log(sprintf('candidate categories: %d (min_posts=%d, top_n=%d)',
            count($cats), $this->minPosts, $this->topN));

        $stats = ['ok' => 0, 'skip' => 0, 'fail' => 0, 'total_posts_used' => 0];
        $built = [];
        foreach ($cats as $cat) {
            $name = $cat['category'];
            $slug = $this->slugFor($name);
            $path = $outDir . '/' . $slug . '.html';
            if (!$this->force && is_file($path) && filesize($path) > 6000) {
                $stats['skip']++;
                $built[] = ['name' => $name, 'slug' => $slug, 'count' => $cat['c']];
                continue;
            }
            try {
                $totalCount = (int)$cat['c'];
                [$posts, $usedThr] = $this->fetchPostsWithFallback($name);
                if (count($posts) < 10) {
                    $this->log("SKIP $name: only " . count($posts) . " posts even at lowest threshold");
                    $stats['skip']++;
                    continue;
                }
                $html = $this->renderCategoryPage($name, $slug, $posts, $totalCount);
                file_put_contents($path, $html);
                $built[] = ['name' => $name, 'slug' => $slug, 'count' => $totalCount, 'used' => count($posts)];
                $stats['ok']++;
                $stats['total_posts_used'] += count($posts);
                $this->log("OK $name → $slug.html (total={$totalCount}, used=" . count($posts) . " @len>={$usedThr}, " . strlen($html) . " bytes)");
            } catch (\Throwable $e) {
                $stats['fail']++;
                $this->log("FAIL $name: " . $e->getMessage());
            }
        }

        if ($built) $this->writeIndex($built);
        $this->log('STATS ' . json_encode($stats, JSON_UNESCAPED_UNICODE));
    }

    private function fetchTopCategories(): array
    {
        $sql = "SELECT category, COUNT(*) c FROM posts
                WHERE category IS NOT NULL AND category <> ''
                GROUP BY category
                HAVING c >= :min
                ORDER BY c DESC
                LIMIT :n";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':min', $this->minPosts, \PDO::PARAM_INT);
        $st->bindValue(':n', $this->topN, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    private function fetchPosts(string $category, int $minLen = self::MIN_LEN): array
    {
        // 高质量帖子：content_json 长度 >= minLen，最新优先
        $sql = "SELECT id, title, summary, cover, query, tags_json, created_at
                FROM posts
                WHERE category = :cat
                  AND LENGTH(content_json) >= " . $minLen . "
                ORDER BY id DESC
                LIMIT " . $this->perPage;
        $st = $this->pdo->prepare($sql);
        $st->execute([':cat' => $category]);
        return $st->fetchAll();
    }

    /**
     * 取帖子，按 1500 → 800 → 0 三档 fallback，确保每个 hub 至少有 50 条内链
     * @return array{0:array,1:int}  [posts, used_threshold]
     */
    private function fetchPostsWithFallback(string $category): array
    {
        foreach ([self::MIN_LEN, 800, 0] as $thr) {
            $posts = $this->fetchPosts($category, $thr);
            if (count($posts) >= 30) return [$posts, $thr];
        }
        // 最后一次（无门槛）的结果
        return [$this->fetchPosts($category, 0), 0];
    }

    private function slugFor(string $name): string
    {
        if (isset(self::SLUG_MAP[$name])) return self::SLUG_MAP[$name];
        // fallback：纯 ASCII 提取 + md5 后 8
        $ascii = preg_replace('/[^a-zA-Z0-9]+/', '-', $name);
        $ascii = trim((string)$ascii, '-');
        if ($ascii !== '' && preg_match('/[a-zA-Z]/', $ascii)) {
            return strtolower($ascii);
        }
        return 'c-' . substr(md5($name), 0, 10);
    }

    private function coverFor(string $name): string
    {
        $key = self::COVER_MAP[$name] ?? 'industrial';
        $local = ROOT_PATH . '/assets/img/cover/' . $key . '.jpg';
        if (!is_file($local)) {
            // fallback 链：trade → industrial → factory
            foreach (['trade', 'industrial', 'factory'] as $f) {
                if (is_file(ROOT_PATH . '/assets/img/cover/' . $f . '.jpg')) {
                    $key = $f;
                    break;
                }
            }
        }
        return '/assets/img/cover/' . $key . '.jpg';
    }

    private function renderCategoryPage(string $name, string $slug, array $posts, int $totalCount): string
    {
        $totalH = number_format($totalCount);
        $title = htmlspecialchars("{$name}行业内容聚合 · {$totalH}+ 篇深度指南与采购参考", ENT_QUOTES, 'UTF-8');
        $h1 = htmlspecialchars("{$name}：行业聚合中心", ENT_QUOTES, 'UTF-8');
        $nameH = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $cover = $this->coverFor($name);
        $url = "https://guonika.com/topics/categories/{$slug}.html";

        $intro = $this->buildIntro($name, $totalCount, count($posts));
        $description = htmlspecialchars(mb_substr(strip_tags($intro), 0, 150, 'UTF-8'), ENT_QUOTES, 'UTF-8');

        // 文章卡片
        $cardsHtml = '';
        $itemListItems = [];
        $pos = 1;
        foreach ($posts as $p) {
            $pid = (int)$p['id'];
            $pTitle = trim((string)$p['title']);
            $pSummary = trim((string)$p['summary']);
            if ($pTitle === '') continue;
            $pTitleH = htmlspecialchars($pTitle, ENT_QUOTES, 'UTF-8');
            $pSumH = htmlspecialchars(mb_substr($pSummary, 0, 80, 'UTF-8'), ENT_QUOTES, 'UTF-8');
            $kw = trim((string)($p['query'] ?? ''));
            $kwH = $kw !== '' ? htmlspecialchars(mb_substr($kw, 0, 16, 'UTF-8'), ENT_QUOTES, 'UTF-8') : '';
            $date = isset($p['created_at']) ? substr((string)$p['created_at'], 0, 10) : '';

            $kwBadge = $kwH !== '' ? "<span class=\"hub-card-kw\">{$kwH}</span>" : '';
            $dateBadge = $date !== '' ? "<span class=\"hub-card-date\">{$date}</span>" : '';

            $cardsHtml .= "<a class=\"hub-card\" href=\"/news/{$pid}\">
<h3>{$pTitleH}</h3>
<p>{$pSumH}</p>
<div class=\"hub-card-meta\">{$kwBadge}{$dateBadge}</div>
</a>";
            $itemListItems[] = [
                '@type' => 'ListItem',
                'position' => $pos++,
                'url' => "https://guonika.com/news/{$pid}",
                'name' => $pTitle,
            ];
        }

        // 同站交叉链接（其他类目 hub）
        $crossHtml = $this->renderCrossLinks($name);

        // schema
        $itemListSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => "{$name} - 行业内容聚合",
            'numberOfItems' => count($itemListItems),
            'itemListElement' => $itemListItems,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $breadcrumbSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => 'https://guonika.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => '行业聚合', 'item' => 'https://guonika.com/topics/categories/index.html'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => $url],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $articleSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => "{$name} - 行业聚合中心",
            'description' => mb_substr(strip_tags($intro), 0, 150, 'UTF-8'),
            'url' => $url,
            'image' => 'https://guonika.com' . $cover,
            'inLanguage' => 'zh-CN',
            'isPartOf' => ['@type' => 'WebSite', 'name' => '全球工业产业链', 'url' => 'https://guonika.com/'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $introH = $intro; // already HTML-escaped inside builder

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>{$title} - 全球工业产业链</title>
<meta name="description" content="{$description}">
<meta name="keywords" content="{$name},采购,选型,询价,厂家,行业">
<link rel="canonical" href="{$url}">
<link rel="alternate" hreflang="zh-CN" href="{$url}">
<link rel="stylesheet" href="/assets/css/retro2013.css?v=1">
<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/font/bootstrap-icons.css">
<link rel="icon" href="/favicon.ico" sizes="any">
<style>
.hub-shell{max-width:1280px;margin:0 auto;padding:18px 14px 40px}
.hub-hero{position:relative;background:linear-gradient(125deg,#173451 0%,#245e92 100%);color:#fff;padding:28px 26px;margin-bottom:14px;border-radius:0;overflow:hidden}
.hub-hero::after{content:"";position:absolute;inset:0;background:url("{$cover}") right center / cover no-repeat;opacity:.18;pointer-events:none}
.hub-hero-inner{position:relative;z-index:1}
.hub-hero .kicker{display:inline-block;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);color:#fff;font-size:12px;padding:2px 10px;margin-bottom:8px}
.hub-hero h1{margin:0 0 10px;font-size:26px;line-height:1.4}
.hub-hero p{margin:0 0 8px;line-height:1.85;color:rgba(255,255,255,.94);font-size:14px}
.hub-cta-bar{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.hub-cta-bar a{display:inline-block;padding:7px 14px;border:1px solid rgba(255,255,255,.4);background:rgba(255,255,255,.1);color:#fff;font-size:13px;text-decoration:none;border-radius:0}
.hub-cta-bar a.primary{background:#f4ba3a;color:#3a2400;border-color:#f4ba3a}
.hub-cta-bar a:hover{background:#fff;color:#1f3a63;border-color:#fff}
.hub-section-title{margin:24px 0 12px;font-size:18px;color:#1f3a63;border-bottom:2px solid #f4d97f;padding-bottom:6px}
.hub-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:10px}
.hub-card{display:block;background:#fff;border:1px solid #e0e4ea;padding:12px 14px;text-decoration:none;color:inherit;transition:border-color .15s}
.hub-card:hover{border-color:#c9a45c}
.hub-card h3{margin:0 0 6px;font-size:14px;color:#1f3a63;line-height:1.55;font-weight:600;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.hub-card p{margin:0 0 8px;color:#5d6f84;font-size:12px;line-height:1.6;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:38px}
.hub-card-meta{font-size:11px;color:#8c9aab;display:flex;gap:8px;align-items:center}
.hub-card-kw{display:inline-block;padding:1px 7px;background:#fff3df;border:1px solid #f4d97f;color:#a06a07}
.hub-card-date{color:#aab2bd}
.hub-cross{margin-top:24px;background:#fafbfc;border:1px solid #e5e7eb;padding:16px 18px}
.hub-cross h3{margin:0 0 10px;font-size:15px;color:#1f3a63}
.hub-cross-list{display:flex;flex-wrap:wrap;gap:8px}
.hub-cross-list a{display:inline-block;padding:6px 12px;background:#fff;border:1px solid #d0d6dd;color:#1f3a63;font-size:13px;text-decoration:none}
.hub-cross-list a:hover{background:#1f3a63;color:#fff;border-color:#1f3a63}
.hub-foot-cta{margin-top:24px;background:#fff8f0;border:1px solid #f4d97f;padding:18px 22px;text-align:center}
.hub-foot-cta h3{margin:0 0 8px;font-size:16px;color:#7a5b00}
.hub-foot-cta p{margin:0 0 12px;font-size:13px;color:#444}
.hub-foot-cta a{display:inline-block;padding:8px 18px;background:#c9a45c;color:#fff;font-size:13px;text-decoration:none;border:1px solid #c9a45c;margin:0 4px}
.hub-foot-cta a:hover{background:#a8852f;border-color:#a8852f}
@media (max-width:600px){.hub-hero h1{font-size:20px}.hub-grid{grid-template-columns:1fr}}
</style>
<script type="application/ld+json">{$articleSchema}</script>
<script type="application/ld+json">{$breadcrumbSchema}</script>
<script type="application/ld+json">{$itemListSchema}</script>
</head>
<body class="topic-static-page topic-category-hub">
<header class="top-bar bg-primary text-white py-2"><div class="container"><div class="row align-items-center"><div class="col-md-7"><span>欢迎来到全球工业产业链</span></div><div class="col-md-5 text-end"><span>客服热线：400-880-6688</span></div></div></div></header>
<nav class="navbar bg-white" style="border-bottom:1px solid #e5e7eb;padding:8px 0"><div class="container"><a href="/" style="font-weight:bold;color:#1f3a63;text-decoration:none;font-size:18px">全球工业产业链</a> &nbsp;<a href="/products" style="color:#444;text-decoration:none;margin:0 6px">产品</a><a href="/companies" style="color:#444;text-decoration:none;margin:0 6px">公司</a><a href="/news" style="color:#444;text-decoration:none;margin:0 6px">资讯</a><a href="/topics/quotes/index.html" style="color:#444;text-decoration:none;margin:0 6px">行情</a><a href="/topics/flagship/index.html" style="color:#444;text-decoration:none;margin:0 6px">旗舰指南</a><a href="/topics/categories/index.html" style="color:#c9302c;text-decoration:none;margin:0 6px">行业聚合</a></div></nav>
<main class="hub-shell">
<nav class="hub-breadcrumb" aria-label="breadcrumb" style="font-size:12px;color:#7a8aa0;margin:6px 0 10px"><a href="/" style="color:#1f3a63;text-decoration:none">首页</a> / <a href="/topics/categories/index.html" style="color:#1f3a63;text-decoration:none">行业聚合</a> / <span style="color:#a06a07">{$nameH}</span></nav>
<div class="hub-hero">
<div class="hub-hero-inner">
<span class="kicker">行业聚合中心 · {$name}</span>
<h1>{$h1}</h1>
{$introH}
<div class="hub-cta-bar">
<a class="primary" href="/products?q={$name}"><i class="bi bi-box-seam"></i> 查看 {$name} 产品</a>
<a href="/companies?q={$name}"><i class="bi bi-buildings"></i> {$name} 厂家供应商</a>
<a href="/news?q={$name}"><i class="bi bi-newspaper"></i> {$name} 最新资讯</a>
<a href="/trade?q={$name}"><i class="bi bi-bag-check"></i> {$name} 商机</a>
</div>
</div>
</div>
<h2 class="hub-section-title"><i class="bi bi-collection"></i> 精选 {$name} 内容（最新 50 篇深度指南）</h2>
<div class="hub-grid">{$cardsHtml}</div>
{$crossHtml}
<div class="hub-foot-cta">
<h3>需要采购报价或厂家对接？</h3>
<p>欢迎使用站内询价工具或拨打客服热线，平台覆盖 {$name} 上下游产业链。</p>
<a href="/products?q={$name}">浏览 {$name} 产品库</a>
<a href="/companies?q={$name}">联系 {$name} 厂家</a>
<a href="tel:400-880-6688">拨打 400-880-6688</a>
</div>
</main>
<footer style="background:#0b1623;color:#aab2bd;padding:18px 14px;text-align:center;font-size:12px"><div>&copy; 全球工业产业链 · <a href="/" style="color:#aab2bd;text-decoration:none">首页</a> · <a href="/topics/categories/index.html" style="color:#aab2bd;text-decoration:none">行业聚合</a> · 豫ICP备2023034280号-2</div></footer>
</body>
</html>
HTML;
    }

    /**
     * 类目导读段（200-300 字，纯 HTML p 标签，已转义）
     */
    private function buildIntro(string $name, int $totalCount, int $usedCount): string
    {
        $totalH = number_format($totalCount);
        $usedH = number_format($usedCount);
        $line1 = "本页汇集了「{$name}」类目下平台累计沉淀的 {$totalH} 篇内容中，按内容质量与时效精选出的最新 {$usedH} 篇深度指南、采购参考与工艺解读，覆盖选型、规格、询价、产业带分布与价格区间等高频采购决策环节。";
        $line2 = "{$name}板块整合产品库、厂家黄页、行情快讯与商机询盘四类入口，方便读者从一篇内容直接跳转到「找产品 → 比厂家 → 看行情 → 发询盘」的完整采购闭环；如果你正在做{$name}相关的项目立项、设备选型、年度集采或对外出口贸易，建议结合页面右上角的「产品 / 公司 / 资讯 / 商机」四个 CTA 一起使用。";
        $line3 = "下方内容卡片按发布时间倒序排列，标题点击直达原文（带行业 schema 标注，对搜索引擎与 AI 检索都更友好）；本页底部还提供同站其他高频行业的快速跳转链接，可作为站内信息架构的中转站使用。";

        $h1 = htmlspecialchars($line1, ENT_QUOTES, 'UTF-8');
        $h2 = htmlspecialchars($line2, ENT_QUOTES, 'UTF-8');
        $h3 = htmlspecialchars($line3, ENT_QUOTES, 'UTF-8');
        return "<p>{$h1}</p><p>{$h2}</p><p>{$h3}</p>";
    }

    /**
     * 同站交叉链接（其他高频类目 hub）
     */
    private function renderCrossLinks(string $current): string
    {
        $sql = "SELECT category, COUNT(*) c FROM posts
                WHERE category IS NOT NULL AND category <> '' AND category <> :cur
                GROUP BY category
                HAVING c >= 100
                ORDER BY c DESC
                LIMIT 24";
        $st = $this->pdo->prepare($sql);
        $st->execute([':cur' => $current]);
        $rows = $st->fetchAll();
        if (!$rows) return '';

        $items = '';
        foreach ($rows as $r) {
            $cn = (string)$r['category'];
            $slug = $this->slugFor($cn);
            $cnH = htmlspecialchars($cn, ENT_QUOTES, 'UTF-8');
            $cnt = number_format((int)$r['c']);
            $items .= "<a href=\"/topics/categories/{$slug}.html\">{$cnH} <span style=\"color:#8c9aab;font-size:11px\">({$cnt})</span></a>";
        }
        return "<div class=\"hub-cross\"><h3><i class=\"bi bi-diagram-3\"></i> 同站其他高频行业</h3><div class=\"hub-cross-list\">{$items}</div></div>";
    }

    private function writeIndex(array $built): void
    {
        $outDir = ROOT_PATH . self::OUT_DIR;
        // 按 count desc 排序
        usort($built, static fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
        $count = count($built);

        $cardsHtml = '';
        $listItems = [];
        $pos = 1;
        foreach ($built as $b) {
            $name = (string)$b['name'];
            $slug = (string)$b['slug'];
            $cnt = (int)$b['count'];
            $url = "https://guonika.com/topics/categories/{$slug}.html";
            $nameH = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $cntH = number_format($cnt);
            $cover = $this->coverFor($name);
            $cardsHtml .= "<a class=\"cathub-card\" href=\"/topics/categories/{$slug}.html\" style=\"background-image:linear-gradient(135deg,rgba(23,52,81,.92) 0%,rgba(36,94,146,.86) 100%),url('{$cover}')\">
<span class=\"cathub-card-kicker\">行业聚合</span>
<h3>{$nameH}</h3>
<span class=\"cathub-card-count\">{$cntH} 篇内容</span>
</a>";
            $listItems[] = ['@type' => 'ListItem', 'position' => $pos++, 'url' => $url, 'name' => $name];
        }

        $listSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => '行业聚合中心',
            'numberOfItems' => $count,
            'itemListElement' => $listItems,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $breadcrumbSchema = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => 'https://guonika.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => '行业聚合', 'item' => 'https://guonika.com/topics/categories/index.html'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>行业聚合中心 · {$count} 大工业类目内容总览 - 全球工业产业链</title>
<meta name="description" content="覆盖工业制造、采购供应、物流仓储、机械设备、电子电工、化工能源、金属材料等 {$count} 大类目的内容聚合中心，每个类目精选最新 50 篇深度指南与采购参考。">
<meta name="keywords" content="行业聚合,工业内容,工业采购,选型指南,行业目录">
<link rel="canonical" href="https://guonika.com/topics/categories/index.html">
<link rel="alternate" hreflang="zh-CN" href="https://guonika.com/topics/categories/index.html">
<link rel="stylesheet" href="/assets/css/retro2013.css?v=1">
<link rel="stylesheet" href="/assets/vendor/bootstrap-icons/font/bootstrap-icons.css">
<link rel="icon" href="/favicon.ico" sizes="any">
<style>
.cathub-shell{max-width:1280px;margin:0 auto;padding:18px 14px 40px}
.cathub-hero{background:linear-gradient(125deg,#0b1623 0%,#173451 60%,#245e92 100%);color:#fff;padding:32px 28px;margin-bottom:18px;border-radius:0}
.cathub-hero h1{margin:0 0 12px;font-size:30px;line-height:1.3}
.cathub-hero p{margin:0;line-height:1.85;color:rgba(255,255,255,.92);font-size:14px}
.cathub-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.cathub-card{display:flex;flex-direction:column;justify-content:flex-end;min-height:140px;padding:14px 16px;background:#173451 center/cover no-repeat;color:#fff;text-decoration:none;border:1px solid #2c4d75;transition:transform .15s}
.cathub-card:hover{transform:translateY(-2px);color:#fff}
.cathub-card-kicker{display:inline-block;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);font-size:11px;padding:1px 8px;align-self:flex-start;margin-bottom:8px}
.cathub-card h3{margin:0 0 6px;font-size:18px;line-height:1.4}
.cathub-card-count{font-size:12px;color:rgba(255,255,255,.78)}
@media (max-width:600px){.cathub-hero h1{font-size:22px}.cathub-grid{grid-template-columns:repeat(auto-fit,minmax(160px,1fr))}}
</style>
<script type="application/ld+json">{$listSchema}</script>
<script type="application/ld+json">{$breadcrumbSchema}</script>
</head>
<body class="topic-static-page topic-category-hub-index">
<header class="top-bar bg-primary text-white py-2"><div class="container"><div class="row align-items-center"><div class="col-md-7"><span>欢迎来到全球工业产业链</span></div><div class="col-md-5 text-end"><span>客服热线：400-880-6688</span></div></div></div></header>
<nav class="navbar bg-white" style="border-bottom:1px solid #e5e7eb;padding:8px 0"><div class="container"><a href="/" style="font-weight:bold;color:#1f3a63;text-decoration:none;font-size:18px">全球工业产业链</a> &nbsp;<a href="/products" style="color:#444;text-decoration:none;margin:0 6px">产品</a><a href="/companies" style="color:#444;text-decoration:none;margin:0 6px">公司</a><a href="/news" style="color:#444;text-decoration:none;margin:0 6px">资讯</a><a href="/topics/quotes/index.html" style="color:#444;text-decoration:none;margin:0 6px">行情</a><a href="/topics/flagship/index.html" style="color:#444;text-decoration:none;margin:0 6px">旗舰指南</a><a href="/topics/categories/index.html" style="color:#c9302c;text-decoration:none;margin:0 6px">行业聚合</a></div></nav>
<main class="cathub-shell">
<div class="cathub-hero">
<h1>行业聚合中心 · {$count} 大工业类目</h1>
<p>覆盖工业制造、采购供应、物流仓储、机械设备、电子电工、化工能源、金属材料等核心行业的内容聚合，每个类目精选最新 50 篇深度指南与采购参考；点击任一类目即可进入对应行业的内容中转站，从一站直达产品库、厂家黄页、行情快讯与商机询盘。</p>
</div>
<div class="cathub-grid">{$cardsHtml}</div>
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

(new CategoryHubBuilder($opts))->run();
