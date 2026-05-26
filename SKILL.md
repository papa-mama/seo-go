---
name: seo-go
description: 给已有内容站（B2B / 行业门户 / 资讯站 / 工具站）做"内容 + SEO + GEO + 缓存基建"全链路升级的工程化 SOP。当用户提到 SEO 改造 / GEO 本地化 / 内容矩阵 / 长尾聚合页 / topic 页 / flagship 页 / 弱稿升级 / 内容自动生产 / FAQ schema / Product schema / LocalBusiness schema / Dataset schema / sitemap 切片 / robots / TDK / 百度推送 / 百度收录 / 百度蜘蛛 / 关键词词库 / unmatched_keywords / 长尾词 / 站点审计 / 内容审计 / 缓存优化 / SimpleCache / shard 权限 / 256 桶 / cache pulse / warmup / growth flywheel / weak posts / 提示词工程 / 李继刚风格 / AI 八股 / 反 AI 黑名单 / 城市矩阵 / 产业带 / 苏锡常 / 佛山 / 温州乐清 / 柳市 / 工业带 / E-E-A-T / 惊雷 / 飓风 / 细雨 / 清风 / 绿萝 / 内链 / canonical / og: / twitter: / 站点扩张 / 内容深度，主动调用本 skill。**调用规则：第一步永远是读 `playbooks/00-onboarding.md`，盘点 5 类数据资产再动手；任何脚本都先看头部 PORTABILITY 块改占位符再跑。**
---

# seo-go skill — AI 入口

## 你是谁，要做什么

你（AI 助手）现在拿到一个用户站点项目，用户希望你：
- 把站做 SEO / GEO 升级
- 铺内容矩阵（topic / flagship / geo / query-hub / category-hub）
- 救弱稿、自动生成内容
- 加缓存基建、做监控
- 接百度推送 / sitemap 切片 / FAQ schema

**不要临场发挥**。本 skill 已经把 guonika.com 一年的实战路径整理成 9 个剧本。
对应你要做的事 → 对应剧本 → 按步骤抄实现 → 改占位符 → 跑。

---

## 强制工作流

### 第 1 步：onboarding（任何任务都不能跳过）

```
读 playbooks/00-onboarding.md
```

它会让你：
1. 识别用户项目的 5 类核心数据资产是否存在（词库 / 搜索日志 / 缺口词 / 落地统计 / 内容表）
2. 摸一遍基线（cache 命中率、sitemap 状态、schema 覆盖率、TDK 完整度）
3. 给用户一份"可改造空间"清单
4. 让用户选切入剧本

**禁止**在 onboarding 没跑完之前直接动手改任何文件。

### 第 2 步：选剧本

| 用户想做的事 | 跳到 |
|---|---|
| 网站慢 / cache 没生效 / 性能优化 | playbook 01 |
| 内容矩阵 / topic 页 / flagship 页 | playbook 02 |
| robots / sitemap / TDK / canonical / schema | playbook 03 |
| GEO 本地化 / 城市矩阵 / 产业带 | playbook 04 |
| 弱稿升级 / 自动生成内容 | playbook 05 |
| LLM 提示词改造 / 反 AI 八股 | playbook 06 |
| 缓存观测 / 爬虫观测 / growth flywheel | playbook 07 |
| 百度推送 / 收录 API | playbook 08 |

### 第 3 步：照剧本跑，遇坑查 references

每个 playbook 在踩坑处会指 `references/` 里的具体页。例：
- "shard 桶被 root 建了写不进" → `references/cache-perms-trap.md`
- "AI 写的稿子全是套话" → `references/prompt-anti-patterns.md`
- "schema 怎么写才被 Google 认" → `references/schema-org-cookbook.md`

### 第 4 步：用脚本前先读 PORTABILITY 块

`scripts/` 里所有脚本的文件头都有：
```
/**
 * PORTABILITY:
 *   - 必改占位符: $TABLE_*, $CACHE_DIR, $SITE_DOMAIN, ...
 *   - 项目假设: ...
 *   - 不可移植段: line X-Y (...)
 */
```
**先改占位符再跑**，否则会把 guonika 的硬编码（trade_leads 表 / industry 枚举 / /tmp/guonika-runtime/cache 路径）带到用户项目里。

---

## 仓库索引

### playbooks/（顺序执行的剧本）

- `00-onboarding.md` — 数据资产盘点 + 基线测量 + 剧本路线规划
- `01-cache-foundation.md` — 256 桶分片 + 权限坑 + pulse + warmup（**所有性能优化的地基**）
- `02-content-architecture.md` — 5 类内容矩阵：topic / flagship / geo / query-hub / category-hub
- `03-seo-foundations.md` — robots / sitemap-index 切片 / canonical / TDK / 5 类 schema
- `04-geo-localization.md` — 50 城地市矩阵 + 产业带映射 + protectedGeoSlugs
- `05-content-pipeline.md` — weak posts 升级 + 自动生成 + 队列治理
- `06-llm-prompting.md` — 李继刚四层结构 + 黑名单 + 量化锚 + 自检闭环
- `07-monitoring.md` — cache_pulse + crawler_pulse + growth_flywheel
- `08-baidu-push.md` — 百度推送 API + 配额 + 站点验证

### references/（按需查询的知识）

- `seo-algorithms.md` — 百度（惊雷/飓风/细雨/清风/绿萝）+ Google E-E-A-T 完整边界
- `schema-org-cookbook.md` — 5 类 schema 真实站点模板
- `prompt-anti-patterns.md` — AI 八股黑名单 + 数字标准号必带 + 反编造规则
- `cache-perms-trap.md` — SimpleCache 权限坑完整复盘（含 nginx error log 信号）
- `geo-cities-china.md` — 50 城 + 产业带映射表
- `content-quality-rubric.md` — 弱稿打分规则
- `data-pipeline.md` — 5 类核心数据资产规范

### scripts/（参考实现，标了占位符）

- `audit/` — 审计/盘点脚本：`seo_geo_audit.php` / `cache_pulse.php` / `crawler_pulse.sh` / `seo_audit.php`
- `content/` — 内容生成：`build_geo_pages.php` / `build_query_hubs.php` / `build_flagship_pages.php` / `build_category_hubs.php` / `upgrade_weak_posts.php` / `inject_faq_schema.php` / `inject_topic_social_meta.php`
- `seo/` — SEO 配套：`build_posts_sitemap.php` / `baidu_push.php`
- `infra/` — 基建：`SimpleCache.php` / `migrate_cache_shards.php` / `fix_cache_perms.sh` / `warmup_caches.php`
- `llm/` — 提示词工具箱：`llm_prompt_kit.php`

### templates/（开箱模板）

- `robots.txt.tpl`
- `sitemap-index.xml.tpl`
- `faq-schema-block.html.tpl`
- `geo-page-html.tpl`

---

## 三条不可违反的硬规则

### 1. 数据资产先盘后用

5 类核心资产（词库 / 真实搜索 / 缺口词 / 落地统计 / 内容表）的存在性决定路线。
**没有词库的站不要套用 baidu-push 剧本**；**没有内容表的站不要套用 weak-posts 升级剧本**。

### 2. 任何 LLM 生成都走四层结构

参见 `playbooks/06-llm-prompting.md`：角色（含立场/经历/心法）/ 规则（含黑名单）/ 自检闭环 / 温度。
不允许直接 `messages: [{user: "写一篇 xxx 的文章"}]`。

### 3. 缓存改完必跑 perm check

`find $CACHE_DIR -type d -uid 0 | wc -l` 必须 = 0。
任何 root 跑过的 cache 相关脚本，跑完立刻 `bash scripts/infra/fix_cache_perms.sh`。
原因见 `references/cache-perms-trap.md`。
