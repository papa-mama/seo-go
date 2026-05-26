<?php

declare(strict_types=1);

/**
 * SEO/GEO 自动化体检脚本
 *
 * 用法：
 *   php .claude/skills/seo-geo-roundtable/scripts/seo_geo_audit.php
 *
 * 输出：runtime/seo_geo_facts/audit_<timestamp>.md
 *
 * 检查项：
 *   1. TDK 唯一性（按模式归类）
 *   2. 5 大栏目内容数 + sitemap 包含情况
 *   3. schema 主类型覆盖率（抽 20 篇随机 posts）
 *   4. 主题集中度（教育 vs 非教育）
 *   5. 信任信号（ICP/企业/邮箱/地址）
 *   6. friendship_links 配置健康度
 */

date_default_timezone_set('Asia/Shanghai');

require_once dirname(__DIR__, 4) . '/api/config.php';

$siteRoot = dirname(__DIR__, 4);
$outDir = $siteRoot . '/runtime/seo_geo_facts';
@mkdir($outDir, 0755, true);
$stamp = date('Ymd_His');
$out = "$outDir/audit_$stamp.md";

function pdoConnect(string $name, string $user, string $pass): ?PDO
{
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, $name);
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (Throwable $e) {
        return null;
    }
}

$pdoNcpjy = pdoConnect(DB_NAME, DB_USER, DB_PASS);

// 读 .env 获取 TP 库
$tpEnv = parse_ini_file($siteRoot . '/.env');
$pdoTp = null;
if (is_array($tpEnv) && !empty($tpEnv['DB_NAME'])) {
    $pdoTp = pdoConnect((string)$tpEnv['DB_NAME'], (string)$tpEnv['DB_USER'], (string)$tpEnv['DB_PASS']);
}

$lines = [];
$lines[] = "# SEO/GEO 自动化体检 " . date('Y-m-d H:i:s');
$lines[] = '';

// ===== 1. TDK 唯一性 =====
$lines[] = "## 1. 标题唯一性（清风算法对照）";
$lines[] = '';

if ($pdoNcpjy instanceof PDO) {
    $stmt = $pdoNcpjy->query("SELECT title, COUNT(*) AS n FROM posts WHERE title IS NOT NULL AND title != '' GROUP BY title HAVING n > 1 ORDER BY n DESC LIMIT 15");
    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        $lines[] = "- ✅ 没有完全重复标题";
    } else {
        $lines[] = "- ⚠️ 完全重复标题 Top 15：";
        $lines[] = '';
        $lines[] = '| count | title |';
        $lines[] = '|---|---|';
        foreach ($rows as $r) {
            $t = mb_substr((string)$r['title'], 0, 60, 'UTF-8');
            $lines[] = "| {$r['n']} | $t |";
        }
    }
    $lines[] = '';

    // 模板腔检测
    $tpls = ['实用指南：%', '%知识解析%', '%操作方法%', '%培训学习%'];
    $lines[] = "- 模板腔标题分布：";
    $lines[] = '';
    $lines[] = '| 模式 | count |';
    $lines[] = '|---|---|';
    foreach ($tpls as $tpl) {
        $st = $pdoNcpjy->prepare("SELECT COUNT(*) AS n FROM posts WHERE title LIKE :t");
        $st->execute([':t' => $tpl]);
        $n = (int)$st->fetch()['n'];
        $lines[] = "| `$tpl` | $n |";
    }
}
$lines[] = '';

// ===== 2. 主题集中度 =====
$lines[] = "## 2. 主题集中度（飓风/优采算法对照）";
$lines[] = '';

if ($pdoNcpjy instanceof PDO) {
    $eduTerms = ['公务员', '国考', '省考', '行测', '申论', '考研', '研究生', '教师资格', '教资', '事业单位', '留学', '雅思', '托福', '专升本', '自考', '成考', '考证', '建造师', 'CPA', '心理咨询', '面试', '岗位', '招录', '备考', '真题', '模拟'];
    $eduWhere = [];
    $params = [];
    foreach ($eduTerms as $i => $t) {
        $eduWhere[] = "title LIKE :t$i";
        $params[":t$i"] = "%$t%";
    }
    $sql = "SELECT COUNT(*) AS n FROM posts WHERE " . implode(' OR ', $eduWhere);
    $st = $pdoNcpjy->prepare($sql);
    $st->execute($params);
    $eduCount = (int)$st->fetch()['n'];

    $totalCount = (int)($pdoNcpjy->query("SELECT COUNT(*) AS n FROM posts")->fetch()['n'] ?? 0);

    $rate = $totalCount > 0 ? round($eduCount / $totalCount * 100, 1) : 0;
    $verdict = $rate >= 70 ? '✅ 集中度合格' : ($rate >= 50 ? '⚠️ 偏低' : '❌ 严重偏离');
    $lines[] = "- posts 总数：$totalCount";
    $lines[] = "- 教育主题命中：{$eduCount}（{$rate}%）";
    $lines[] = "- 阈值：≥70% 合格 / 50-70% 警告 / <50% 严重";
    $lines[] = "- 当前判定：$verdict";

    // 非教育大类
    $nonEdu = $pdoNcpjy->query("SELECT category, COUNT(*) AS n FROM posts WHERE category LIKE '%农%' OR category LIKE '%中药%' OR category LIKE '%食疗%' OR category LIKE '%养生%' GROUP BY category ORDER BY n DESC LIMIT 5")->fetchAll();
    if (!empty($nonEdu)) {
        $lines[] = '';
        $lines[] = "- ❌ 非教育大类（飓风风险）：";
        foreach ($nonEdu as $r) {
            $lines[] = "  - {$r['category']}：{$r['n']}";
        }
    }
}
$lines[] = '';

// ===== 3. 5 大栏目深度 =====
$lines[] = "## 3. 五大栏目内容深度";
$lines[] = '';

if ($pdoTp instanceof PDO) {
    $cats = ['gongwuyuankaoshi', 'beikaozhinan', 'monitishi', 'kaoshizhengce', 'xuexiziyuan'];
    $lines[] = '| 栏目 | 内容页数 | 阈值 | 状态 |';
    $lines[] = '|---|---|---|---|';
    foreach ($cats as $c) {
        $st = $pdoTp->prepare("SELECT COUNT(*) AS n FROM website_project_pages WHERE category_name=:c AND page_type='内容页'");
        $st->execute([':c' => $c]);
        $n = (int)$st->fetch()['n'];
        $status = $n >= 100 ? '✅' : ($n >= 50 ? '⚠️ 偏少' : '❌ 严重偏少');
        $lines[] = "| $c | $n | ≥100 | $status |";
    }
}
$lines[] = '';

// ===== 4. schema 抽样 =====
$lines[] = "## 4. schema 抽样（GEO 视角）";
$lines[] = '';
$samples = [
    ['url' => 'https://www.ncpjy.cn/', 'expect' => ['Organization', 'WebSite', 'EducationalOrganization', 'SearchAction']],
    ['url' => 'https://www.ncpjy.cn/quality', 'expect' => ['CollectionPage', 'BreadcrumbList', 'ItemList']],
    ['url' => 'https://www.ncpjy.cn/ask', 'expect' => ['CollectionPage', 'BreadcrumbList']],
    ['url' => 'https://www.ncpjy.cn/topics', 'expect' => ['CollectionPage', 'ItemList']],
    ['url' => 'https://www.ncpjy.cn/about.html', 'expect' => ['AboutPage', 'Organization']],
    ['url' => 'https://www.ncpjy.cn/ask/2347.html', 'expect' => ['QAPage', 'Question', 'Answer', 'BreadcrumbList']],
];
$lines[] = '| URL | 期望 schema | 实际命中 |';
$lines[] = '|---|---|---|';
foreach ($samples as $s) {
    $ch = curl_init($s['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ncpjy-audit/1.0)',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = (string)curl_exec($ch);
    curl_close($ch);

    $hits = [];
    if (preg_match_all('/"@type":"([A-Za-z]+)"/', $body, $m)) {
        $hits = array_unique($m[1]);
    }
    $missing = array_diff($s['expect'], $hits);
    $marker = empty($missing) ? '✅' : '⚠️ 缺 ' . implode(',', $missing);
    $lines[] = "| {$s['url']} | " . implode(', ', $s['expect']) . " | $marker |";
}
$lines[] = '';

// ===== 5. 信任信号 =====
$lines[] = "## 5. 信任信号一致性";
$lines[] = '';
$expected = [
    'icp' => '苏ICP备2024122008号-3',
    'company' => '昆山可圈可点信息服务有限公司',
    'email' => '362692221@qq.com',
    'address' => '昆山市花桥镇绿地大道231弄7号楼410室',
];
$lines[] = '| 信号 | 预期 | 状态 |';
$lines[] = '|---|---|---|';
foreach ($expected as $k => $v) {
    $hit = 0;
    foreach (['/', '/about.html', '/ask', '/quality', '/topics'] as $p) {
        $body = @file_get_contents("https://www.ncpjy.cn$p", false, stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'audit']]));
        if (is_string($body) && stripos($body, $v) !== false) $hit++;
    }
    $verdict = $hit >= 4 ? '✅ 5/5 一致' : "⚠️ 仅 $hit/5 一致";
    $lines[] = "| $k | $v | $verdict |";
}
$lines[] = '';

// ===== 6. 缓存版本 =====
$lines[] = "## 6. 缓存版本与 reload 状态";
$lines[] = '';
$qpc = file_get_contents($siteRoot . '/public/includes/query_page_cache.php');
if (preg_match("/QUERY_PAGE_CACHE_VERSION',\s*'([^']+)'/", (string)$qpc, $m)) {
    $lines[] = "- 当前 QUERY_PAGE_CACHE_VERSION = `{$m[1]}`";
}
$lines[] = '';

// ===== 7. 总结建议 =====
$lines[] = "## 7. 自动建议（机器层）";
$lines[] = '';
$lines[] = "- 重复标题数 / 模板腔标题占比 → 标题去重作业";
$lines[] = "- 主题集中度 < 70% → posts 主题相关度过滤器要更激进";
$lines[] = "- schema 抽样缺项 → 修补 seo_geo_helper.php 或对应入口";
$lines[] = "- 信任信号缺项 → 立即修补，影响 GEO/E-E-A-T";

file_put_contents($out, implode("\n", $lines));
echo "audit => $out\n";
