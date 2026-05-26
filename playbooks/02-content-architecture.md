# Playbook 02 — Content Architecture

> **建立 5 类内容矩阵。这是一个 B2B 站从"几百篇文章"到"几十万 URL"的关键路径。**

---

## 5 类矩阵全景

| 矩阵 | 定位 | guonika 实例 | 量级 | 主脚本 |
|---|---|---|---|---|
| **Topic 页** | 围绕一个长尾词 / 行业话题的聚合页（含 FAQ） | `/topics/<slug>/index.html` | **791** | `generate_topic_pages.php` |
| **Flagship 页** | 旗舰深度专题（行业总览 / 大词承接） | `/flagship/<slug>` | **200** | `build_flagship_pages.php` |
| **GEO 页** | 城市 × 行业切片（地市矩阵） | `/geo/<city>` `/geo/<city>-<industry>` | **50 城** | `build_geo_pages.php` |
| **Query-hub 页** | 词库聚合页（按真实搜索词建） | `/query/<keyword>` | 词库决定 | `build_query_hubs.php` |
| **Category-hub 页** | 分类承接页（产品/公司/资讯统一入口） | `/category/<slug>` | 分类数 | `build_category_hubs.php` |

**每类页都必须有：**
- 唯一 canonical
- 5 类 schema 中至少 1 类（看 playbook 03 + reference/schema-org-cookbook.md）
- og: + twitter: 完整
- FAQ schema（≥ 3 条，看 reference/schema-org-cookbook.md）
- 至少 3 个内链入口（避免孤儿页）
- 至少 800 字正文（避免薄页 → 命中清风/飓风）

---

## Step 1：先选要铺哪类（不要全做）

按"用户已有数据资产"决定。参考下表：

| 用户已有 | 必铺 | 可选 | 跳过 |
|---|---|---|---|
| 内容主表 + 分类表 | Topic + Category-hub | Flagship | Query-hub / GEO |
| 上面 + 词库 | + Query-hub | | |
| 上面 + 落地统计有省市 | + GEO | | |
| 全部 5 类资产 | 全做 | | |

**判断逻辑：** 没有数据支撑就不铺，否则全是空壳页 → 命中飓风算法（采集/聚合无价值）。

---

## Step 2：铺 Topic 页（最常用）

### 2.1 选题来源

按优先级：
1. `unmatched_keywords`（缺口词）— 用户搜过没内容，**ROI 最高**
2. `bd_keyword_tracking` 高频 q 但 landing 弱的
3. `bd_keyword_mapping` 含商业修饰词（"价格"/"多少钱"/"厂家"/"型号"/"规格"）
4. 行业词典 + LLM 扩展

### 2.2 单页结构（必须）

```
H1: <长尾主词>
摘要段（80 字）含数字 / 产业带名 / 标准号
─ 价格区间表（含来源说明）─
─ 工业带分布（含至少 3 城）─
─ 选型 / 适配场景（H2 + 列表）─
─ FAQ（≥ 3 条，≥ 1 条含 GB/T 或 JB/T 或 ISO）─
─ 相关 topic / flagship 内链（≥ 3）─
schema:
  - WebPage / Article
  - FAQPage
  - BreadcrumbList
```

### 2.3 跑

```bash
# 看 scripts/content/build_query_hubs.php 改占位符（表名 / 域名 / 行业枚举）
php scripts/content/build_query_hubs.php --limit=100 --dry-run
php scripts/content/build_query_hubs.php --limit=100
```

---

## Step 3：铺 Flagship 页（深度承接大词）

guonika 200 篇旗舰页打法：
- 每个页 3000+ 字
- 必含数字（产业规模 / 价格区间 / GB 标准号 / 城市排名）
- 必含 5 类 schema 至少 3 类
- 配图全本地（不外链）
- 至少 8 个内链锚到 topic 页

```bash
php scripts/content/build_flagship_pages.php --batch=20
```

---

## Step 4：铺 Category-hub（分类承接页）

聚合"分类下的产品 + 公司 + 资讯 + 求购供应"，给搜索意图模糊的用户用。

```bash
php scripts/content/build_category_hubs.php
```

---

## Step 5：铺 Query-hub（词库专属，需要词库）

按 `bd_keyword_mapping` 的 cluster 聚合（价格类 / 厂家类 / 型号类 / 规格类各自一组）。
**禁止**为每个词单独建页（命中清风算法 — 同质化堆砌）。

```bash
php scripts/content/build_query_hubs.php --cluster=price
php scripts/content/build_query_hubs.php --cluster=manufacturer
```

---

## Step 6：铺 GEO 页（看 playbook 04 详细版）

---

## 验收

- 所有矩阵页都进了 `sitemap-posts-*.xml`（playbook 03）
- 抽样 10 个矩阵页，view-source 找：
  - canonical ✓
  - og: + twitter: ✓
  - 至少 1 个 ld+json schema ✓
  - FAQ schema ✓
  - 字数 ≥ 800 ✓
- 矩阵页之间互链（每页至少 3 个出链到同矩阵其他页）

---

## 不要做的事

- ❌ **不要为薄数据建矩阵页** — 没有数字 / 城市 / 标准号就别建页，宁缺毋滥
- ❌ **不要让矩阵页只有 1 个内链入口** — 孤儿页是 SEO 黑洞
- ❌ **不要全 LLM 生成不查证** — 至少 30% 内容必须有真实数据支撑（价格 / 城市 / 公司名只用公知品牌）
- ❌ **不要在矩阵页放外链 image** — 见 reference 中 "Local-only image policy"
