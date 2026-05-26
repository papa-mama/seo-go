# PORTABILITY — 移植到新项目前必读

> 所有 `scripts/` 里的实现都来自 guonika.com，**有大量硬编码**。  
> 直接复制运行会失败 / 写错数据 / 污染你的项目。**先按本文档逐项替换占位符**。

---

## 通用占位符（21 个脚本都有）

### 路径类

| guonika 实际值 | 你要改成 |
|---|---|
| `/www/wwwroot/guonika.com` | 你的项目根目录 |
| `/tmp/guonika-runtime/cache` | `/tmp/<your-project>-runtime/cache` |
| `/www/wwwlogs/guonika.com.error.log` | 你的 nginx error log |
| `ROOT_PATH` 常量 | 你的 config.php 里定义 |

### 域名类

| guonika 实际值 | 你要改成 |
|---|---|
| `guonika.com` | 你的域名 |
| `https://guonika.com` | `https://<your-domain>` |
| `PC_SITE_URL` 常量 | 你的 config.php 里定义 |

### 数据库表名

| guonika 实际表 | 含义 | 你需替换为 |
|---|---|---|
| `posts` | 内容主表 | 你的 articles/news/blog 表 |
| `companies` | 公司表 | 同 |
| `products` | 产品表 | 同 |
| `trade_leads` | 求购供应表 | 同（或删除相关段）|
| `opportunities` | 商机表 | 同（或删除相关段）|
| `categories` | 分类表 | 同 |
| `bd_keyword_mapping` | 词库（190 万） | 你的关键词表 |
| `bd_keyword_tracking` | 搜索日志 | 你的 search 表 |
| `unmatched_keywords` | 缺口词 | 同 |
| `bd_landing_stats` | 落地统计 | 你的 GA / 自建统计表 |
| `hot_keyword_expansions` | 长尾扩展词 | 同（无可删）|

### LLM 配置

| 在哪 | 改什么 |
|---|---|
| `config.php` | `LLM_API_URL` / `LLM_API_KEY` / `LLM_MODEL` |
| `scripts/llm/llm_prompt_kit.php` | 顶部 fallback 值 |
| `scripts/content/*.php` 中调用 LLM 的 | 走 `llm_prompt_kit.php` 即可，无需逐个改 |

### 业务字典（重点改）

| 在哪 | 改什么 |
|---|---|
| `scripts/content/build_geo_pages.php` | `CITY_LIST` / `INDUSTRY_LIST` / `protectedGeoSlugs` |
| `scripts/content/build_query_hubs.php` | `CLUSTER_DEFINITIONS` |
| `scripts/content/build_flagship_pages.php` | `FLAGSHIP_TOPICS` |
| `scripts/content/upgrade_weak_posts.php` | 黑名单 + 必带规则（在 prompt_kit 里也有副本）|

---

## 21 个脚本逐个移植清单

### audit/

#### `cache_pulse.php`
- 改：`guonika.com` → 你的域名（行 77）
- 改：watch keys（行 39-44）改成你项目埋的 watch key 名
- 改：URL 探测列表（行 79-88）改成你的高频路径

#### `crawler_pulse.sh`
- 改：nginx access log 路径
- 改：UA 关键字（如果不在中国大陆运营，去掉 Baiduspider 加 Googlebot）

#### `report_growth_flywheel_health.php`
- 改：所有表名
- 改：阈值（默认按 guonika 量级，小站要降低）

#### `run_growth_flywheel.php`
- 改：内部调用的各 script 路径

#### `seo_geo_audit.php` / `seo_audit.php`
- 改：表名 + 域名

### content/

#### `build_geo_pages.php`
- 改：`CITY_LIST`、`INDUSTRY_LIST`、`protectedGeoSlugs`（参 `references/geo-cities-china.md`）
- 改：表名 + 域名

#### `build_query_hubs.php`
- 改：依赖 `bd_keyword_mapping` 表 — 没有词库就跳过本脚本

#### `build_flagship_pages.php`
- 改：`FLAGSHIP_TOPICS` 列表
- 改：表名 + LLM prompt 调用

#### `build_category_hubs.php`
- 改：分类表 schema 适配

#### `upgrade_weak_posts.php` / `refresh_weak_industrial_posts.php`
- 改：posts 表名
- 改：weak 判定阈值（参 `references/content-quality-rubric.md`）
- 改：LLM prompt（应当走 `llm_prompt_kit.php`）

#### `inject_faq_schema.php`
- 改：注入目标文件路径（topics / flagship 目录）

#### `inject_topic_social_meta.php`
- 同上 + og:image 默认 fallback 图

### seo/

#### `build_posts_sitemap.php`
- 改：表名 + 域名
- 改：`--per=50000` 默认值（根据数据量调）

#### `baidu_push.php`
- 改：`BAIDU_PUSH_SITE`（必须裸域）
- 改：`BAIDU_PUSH_TOKEN`
- 改：每日配额

### infra/

#### `SimpleCache.php`
- 改：`PRIVATE_CACHE_PATH` 默认值
- 其余**不要改**（特别是 `chmod 0775` 那些行，看 `references/cache-perms-trap.md`）

#### `migrate_cache_shards.php`
- 改：`PRIVATE_CACHE_PATH`

#### `fix_cache_perms.sh`
- 改环境变量：`GUONIKA_CACHE_DIR` / `WEB_USER` / `WEB_GROUP`，或者改默认值

#### `warmup_caches.php`
- 改：URL 列表
- 改：`Host:` 头

### llm/

#### `llm_prompt_kit.php`
- 改：endpoint / key / model（或保持读 config.php）
- 改：行业黑名单（如果不做工业 B2B）
- 改：产业带列表（如果不做中国市场）

---

## 移植后必跑的自检

```bash
# 1. 路径检查
grep -rn "guonika\|/www/wwwroot" scripts/  # 必须 = 0

# 2. 表名检查
grep -rn "bd_keyword_mapping\|trade_leads\|opportunities" scripts/  # 看看是否已替换或删除

# 3. 域名检查
grep -rn "guonika.com" scripts/  # 必须 = 0
```

---

## 推荐移植顺序

1. **先复制 infra/**（SimpleCache + fix_cache_perms） — 给所有其他脚本提供地基
2. **再 audit/cache_pulse + warmup_caches** — 立刻能验证地基是否正常
3. **再 seo/build_posts_sitemap + audit/seo_audit** — SEO 基础件
4. **再 llm/llm_prompt_kit** — 给 content 类脚本提供 LLM 入口
5. **最后 content/*** — 按 playbook 02 / 04 / 05 选择性铺
6. **可选 seo/baidu_push + audit/run_growth_flywheel** — 中国大陆站才用
