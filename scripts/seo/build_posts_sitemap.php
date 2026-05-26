<?php
/**
 * build_posts_sitemap.php
 *
 * 把 posts 表中通过价值过滤的 17 万条切成多片 sitemap：
 *   - 过滤：LENGTH(content_json) >= 800 AND title NOT LIKE '%{%' AND title <> ''
 *   - 每片 10000 URL，输出 /sitemap-posts-N.xml
 *   - 同时生成 /sitemap-index.xml 串起所有分片 + 原 sitemap.xml
 *
 * 用法：
 *   php scripts/build_posts_sitemap.php           # 生成 + 写盘
 *   php scripts/build_posts_sitemap.php --dry-run # 只打印 SQL/统计，不写文件
 *   php scripts/build_posts_sitemap.php --per=5000
 *
 * 回滚：删除 sitemap-posts-*.xml + sitemap-index.xml，原 sitemap.xml 不动。
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/functions.php';

$opts = getopt('', ['dry-run::', 'per::', 'min-bytes::']);
$dryRun  = array_key_exists('dry-run', $opts);
$perFile = isset($opts['per']) ? max(1000, (int)$opts['per']) : 10000;
$minBytes = isset($opts['min-bytes']) ? max(200, (int)$opts['min-bytes']) : 800;

$siteRoot = realpath(__DIR__ . '/..');
$baseUrl = defined('PC_SITE_URL') ? rtrim(PC_SITE_URL, '/') : 'https://guonika.com';

$db = getDB();

$totalRow = $db->fetchOne(
    "SELECT COUNT(*) AS c FROM posts
     WHERE LENGTH(content_json) >= :minBytes
       AND title NOT LIKE '%{%'
       AND title <> ''
       AND IFNULL(source_type, '') <> 'suppressed_drift'",
    ['minBytes' => $minBytes]
);
$total = (int)($totalRow['c'] ?? 0);

echo "[posts-sitemap] candidate after SQL filter = {$total} (will further drop suppressed posts in PHP)\n";

$chunks = (int)ceil($total / $perFile);

echo "[posts-sitemap] (estimated) per file = {$perFile}, max chunks = {$chunks}\n";
if ($dryRun) {
    echo "[posts-sitemap] --dry-run set, nothing written.\n";
    return;
}

if ($total === 0) {
    echo "[posts-sitemap] no qualifying posts, abort.\n";
    return;
}

// 删除旧分片（保持目录干净，便于回滚验证）
foreach (glob($siteRoot . '/sitemap-posts-*.xml') ?: [] as $old) {
    @unlink($old);
}

// 流式分片
$written = 0;
$fileIndex = 0;
$fh = null;
$inFile = 0;

$openFile = function () use (&$fh, &$fileIndex, &$inFile, $siteRoot) {
    $fileIndex++;
    $path = $siteRoot . "/sitemap-posts-{$fileIndex}.xml";
    $fh = fopen($path, 'w');
    if (!$fh) {
        throw new RuntimeException("无法写入 {$path}");
    }
    fwrite($fh, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    fwrite($fh, "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n");
    $inFile = 0;
};

$closeFile = function () use (&$fh, &$fileIndex, &$inFile, $siteRoot) {
    if ($fh) {
        fwrite($fh, "</urlset>\n");
        fclose($fh);
        $path = $siteRoot . "/sitemap-posts-{$fileIndex}.xml";
        echo "  ✓ wrote {$path} ({$inFile} urls)\n";
    }
};

$openFile();

// 用 cursor 分批拉，避免一次性把 17 万 ID 拉进内存
$batchSize = 5000;
$lastId = 0;
$conn = $db->getConnection();
$conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

while (true) {
    $stmt = $conn->prepare(
        "SELECT id, title, summary, query, source_type, source_keyword,
                COALESCE(updated_at, date, created_at) AS lastmod
         FROM posts
         WHERE LENGTH(content_json) >= :minBytes
           AND title NOT LIKE '%{%'
           AND title <> ''
           AND IFNULL(source_type, '') <> 'suppressed_drift'
           AND id > :lastId
         ORDER BY id ASC
         LIMIT {$batchSize}"
    );
    $stmt->execute(['minBytes' => $minBytes, 'lastId' => $lastId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        break;
    }

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $lastId = $id;

        // 与前台/列表/搜索保持一致：再走一遍 shouldSuppressIndustrialPost
        if (shouldSuppressIndustrialPost($row)) {
            continue;
        }

        $lastmod = !empty($row['lastmod']) ? date('Y-m-d', strtotime((string)$row['lastmod'])) : date('Y-m-d');
        fwrite($fh, "  <url>\n");
        fwrite($fh, "    <loc>{$baseUrl}/news/{$id}</loc>\n");
        fwrite($fh, "    <lastmod>{$lastmod}</lastmod>\n");
        fwrite($fh, "    <changefreq>monthly</changefreq>\n");
        fwrite($fh, "    <priority>0.6</priority>\n");
        fwrite($fh, "  </url>\n");
        $inFile++;
        $written++;

        if ($inFile >= $perFile) {
            $closeFile();
            $openFile();
        }
    }
}

if ($fh) {
    if ($inFile === 0) {
        // 最后一个文件没写入任何 URL（开了头但全被 suppressed），删掉
        fclose($fh);
        @unlink($siteRoot . "/sitemap-posts-{$fileIndex}.xml");
        $fileIndex--;
        $fh = null;
    } else {
        $closeFile();
    }
}

echo "[posts-sitemap] total written = {$written}\n";

// 生成 sitemap-index.xml：把所有 sitemap-posts-N.xml + 原 sitemap.xml 串起来
$indexPath = $siteRoot . '/sitemap-index.xml';
$nowIso = date('Y-m-d');
$indexFh = fopen($indexPath, 'w');
if (!$indexFh) {
    throw new RuntimeException("无法写入 {$indexPath}");
}
fwrite($indexFh, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
fwrite($indexFh, "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n");

// 原 sitemap.xml（包含首页、products、companies、trade、topics 等）放第一位
$baseSitemap = $siteRoot . '/sitemap.xml';
if (is_file($baseSitemap)) {
    $mtime = date('Y-m-d', filemtime($baseSitemap) ?: time());
    fwrite($indexFh, "  <sitemap>\n");
    fwrite($indexFh, "    <loc>{$baseUrl}/sitemap.xml</loc>\n");
    fwrite($indexFh, "    <lastmod>{$mtime}</lastmod>\n");
    fwrite($indexFh, "  </sitemap>\n");
}

for ($i = 1; $i <= $fileIndex; $i++) {
    fwrite($indexFh, "  <sitemap>\n");
    fwrite($indexFh, "    <loc>{$baseUrl}/sitemap-posts-{$i}.xml</loc>\n");
    fwrite($indexFh, "    <lastmod>{$nowIso}</lastmod>\n");
    fwrite($indexFh, "  </sitemap>\n");
}

fwrite($indexFh, "</sitemapindex>\n");
fclose($indexFh);

echo "[posts-sitemap] index written → {$indexPath}\n";
echo "[posts-sitemap] verify:\n";
echo "  curl -sI {$baseUrl}/sitemap-index.xml\n";
echo "  curl -s  {$baseUrl}/sitemap-posts-1.xml | head -20\n";
