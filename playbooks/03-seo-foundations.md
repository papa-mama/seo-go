# Playbook 03 — SEO Foundations

> **没有这些基础件，再多内容矩阵都白搭。**

---

## 必备 6 件套

| # | 件 | 作用 | 验收命令 |
|---|---|---|---|
| 1 | robots.txt | 抓取指引 + sitemap 入口 | `curl https://$DOMAIN/robots.txt` |
| 2 | sitemap-index.xml | 主索引（指向所有分片） | `curl https://$DOMAIN/sitemap-index.xml` |
| 3 | sitemap-posts-*.xml | 内容分片（< 50MB / < 5万 URL/file） | `ls -lh sitemap-posts-*.xml` |
| 4 | canonical | 防重复内容 | `view-source` 抽样 |
| 5 | og: + twitter: | 社交预览 | `view-source` 抽样 |
| 6 | 5 类 schema | 结构化数据 | `view-source` 找 ld+json |

参见 templates 目录的开箱模板。

---

## Step 1：robots.txt

复制 `templates/robots.txt.tpl`，改 `$DOMAIN`。**关键约束：**

- `Disallow: /admin/ /api/ /cache/ /config/ /includes/ /logs/ /runtime/ /vendor/`
- `Disallow: /*.php$`（除非有不带 rewrite 的 .php 入口）
- `Disallow: /search` `/*?q=`（搜索结果页不索引，否则命中清风算法）
- `Sitemap: https://$DOMAIN/sitemap-index.xml`
- `Host: $DOMAIN`（裸域）

---

## Step 2：sitemap 切片（分片必须！）

### 为什么必须切

- 单文件 > 50MB 或 > 5 万 URL → 百度 / Google 拒绝解析
- guonika 实测：247 万 posts 需要切成 4 片才能全量提交

### 怎么切

复制 `scripts/seo/build_posts_sitemap.php`，改占位符（表名 / 域名 / per）。

```bash
php scripts/seo/build_posts_sitemap.php --per=50000
# 输出 sitemap-posts-1.xml ... sitemap-posts-N.xml
# 同时更新 sitemap-index.xml
```

### sitemap-index.xml 模板

见 `templates/sitemap-index.xml.tpl`。

---

## Step 3：5 类 schema 落地

参考 `references/schema-org-cookbook.md` 的完整模板。

| schema 类型 | 用在哪 | 必带字段 |
|---|---|---|
| **Product** | 产品详情页 / 求购供应详情 | name, description, image, offers (priceCurrency / lowPrice / highPrice / availability) |
| **LocalBusiness** | 公司详情 / GEO 页 | name, address (含 streetAddress / addressLocality / addressRegion / postalCode), telephone, geo |
| **ClassifiedAdsPosting** | 求购供应详情（trade leads） | name, description, datePosted, validThrough, price, areaServed |
| **Dataset** | 行情页 / 数据中心 | name, description, creator, distribution, temporalCoverage |
| **FAQPage** | 所有矩阵页 + topic + flagship + 详情页底部 | mainEntity (Question + Question.acceptedAnswer.Answer) |

**注入工具：**
```bash
php scripts/content/inject_faq_schema.php --target=topics
php scripts/content/inject_faq_schema.php --target=flagship
```

---

## Step 4：canonical（每页必有）

模板（在 `<head>` 内）：

```html
<link rel="canonical" href="<?php echo rtrim(SITE_URL,'/') . $currentUrlPath; ?>">
```

**注意：**
- 必须用绝对 URL（含 https://domain）
- 带参页（?page=2）的 canonical 通常指向不带参的版本
- 详情页用 `id` 不是 `slug`，统一一种

---

## Step 5：og: + twitter:

注入工具（已写好）：

```bash
php scripts/content/inject_topic_social_meta.php
```

模板字段：

```html
<meta property="og:title" content="<title>">
<meta property="og:description" content="<desc 不超过 160 字>">
<meta property="og:image" content="<本地 cover 绝对 URL>">
<meta property="og:type" content="article">
<meta property="og:url" content="<canonical>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<title>">
<meta name="twitter:description" content="<desc>">
<meta name="twitter:image" content="<本地 cover 绝对 URL>">
```

**约束：** og:image 永远本地（不外链），看 feedback_local_images_only。

---

## Step 6：TDK 模板

`<title>` 长度：30-60 字符（中文 12-30 个汉字）。

模板（按页面类型）：

| 页型 | title 模板 |
|---|---|
| 详情页 | `<主词> - <副词> | <品牌词>` |
| 列表页 | `<分类> - <数量>+条 - <品牌词>` |
| Topic 页 | `<长尾词> - <数字 + 城市/产业带> - <品牌词>` |
| Flagship 页 | `<行业大词>完全指南：<5 个细分要点 | 拼接>` |

`<meta name="description">`：80-160 字，必含数字 / 城市 / 标准号至少一项。

---

## 验收

- robots.txt + sitemap-index.xml + sitemap-posts-*.xml 全部 200
- 用 [Google 富媒体测试工具](https://search.google.com/test/rich-results) 抽 10 页查 schema
- 用 `curl -I` 验证 canonical 不冲突
- 用 [百度站长平台](https://ziyuan.baidu.com/) 提交 sitemap-index 看抓取统计

---

## 不要做的事

- ❌ 不要让 sitemap 包含 `?q=` 类搜索结果页
- ❌ 不要让 canonical 跨域（除非确实做了主备域）
- ❌ 不要 schema 字段编造（虚假 priceCurrency / availability 会被 Google 标黄）
- ❌ 不要 robots.txt `Disallow: /` 然后期望被收录（用 noindex meta 才对）
