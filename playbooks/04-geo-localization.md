# Playbook 04 — GEO Localization

> **把内容矩阵在城市维度切一刀，承接"<词> + <城市>"类搜索意图。**

guonika 实战：50 城市 × 30 行业 = 潜在 1500 GEO 页（实际铺 850）。

---

## 核心思想

1. **不是为了铺页面而铺** — GEO 页必须有真实地区差异（产业带 / 价格 / 厂家分布），否则就是 doorway 页（命中清风算法）
2. **产业带映射比城市枚举更值钱** — 苏锡常 = 阀门泵；佛山 = 五金家具；温州乐清 = 低压电气；柳市 = 工业电器；东莞 = 模具
3. **城市选 50 而不是 300+** — 二线及以下城市搜索量稀薄，铺了也是空壳

完整 50 城列表 + 产业带映射：`references/geo-cities-china.md`。

---

## Step 1：城市层级裁剪

50 城分 4 层：

| 层 | 城市数 | 例 |
|---|---|---|
| T1 一线 | 4 | 北京 / 上海 / 广州 / 深圳 |
| T2 新一线 | 15 | 成都 / 重庆 / 杭州 / 苏州 / 武汉 ... |
| T3 强二线 | 16 | 宁波 / 无锡 / 佛山 / 东莞 / 青岛 ... |
| T4 工业带核心 | 15 | 温州 / 乐清 / 柳市 / 慈溪 / 余姚 / 张家港 ... |

**T4 是 GEO 矩阵的金矿** — 这些城市很少出现在通用 city list 里，但在工业 B2B 搜索里权重极高。

---

## Step 2：用 build_geo_pages.php

```bash
# 看占位符（DOMAIN, CITY_LIST, INDUSTRY_LIST 必改）
head -50 scripts/content/build_geo_pages.php

# 单城市试跑
php scripts/content/build_geo_pages.php --target=cities --limit=5 --dry-run

# 全量
php scripts/content/build_geo_pages.php --target=cities
```

---

## Step 3：单页结构（与 topic 页不同）

```
H1: <城市> + <行业> + 产业带（例："乐清低压电气产业带 — 工业电气批发选型"）

行业带概况段（必含）：
  - 当地产值 / 产业规模数字（例："约 1200 家厂"）
  - 至少 1 条 GB 或 JB 标准号
  - 临近产业带提及（"距柳市 8km，与温州乐清形成低压电气走廊"）

价格 / 选型表
代表企业列表（**只列公知品牌或留位"按需问询"，不编造企业名**）
本地配套服务（物流 / 仓储 / 检测）
FAQ（≥ 3，至少 1 条带 GB/T）
内链：
  - 同城其他行业 GEO 页（≥ 3）
  - 同行业其他城市 GEO 页（≥ 3）
  - 对应 topic / flagship 页（≥ 2）

schema:
  - LocalBusiness
  - FAQPage
  - BreadcrumbList
```

---

## Step 4：保护 protectedGeoSlugs

某些 GEO slug 是核心承接页，不能被自动 GC 删除 / 不能被覆盖：

```php
// 在 build_geo_pages.php 配置（必改）
$protectedGeoSlugs = [
    'shanghai-valve',
    'wenzhou-electric',
    'foshan-fastener',
    'liushi-cable',
    // ... 业务指定
];
```

清理脚本必须读取这个列表跳过。

---

## Step 5：把 GEO 内链拼回主站

每个详情页（产品 / 公司 / 资讯）按 region 字段查对应 GEO 页，渲染推荐位：

```php
// 在 product_detail.php / news_detail.php 加
$geoHubs = getGeoHubRecommendations([
    $product['name'],
    $product['region'] ?? '',
    $company['address'] ?? '',
], 3, true);
```

guonika 已实现 `getGeoHubRecommendations()`（见 `scripts/llm/llm_prompt_kit.php` 同目录的 functions.php，本 skill 没复制因为太项目特化）。**新项目要自己实现**，思路：
1. 从 context 抽 city / industry token
2. 优先匹配 city + industry 双命中的 slug
3. 单 city 命中作 fallback
4. 全 miss 时不展示（不要硬塞）

---

## 验收

- 抽 10 个 GEO 页查：每页 ≥ 800 字、≥ 3 内链、有 FAQ、有 LocalBusiness schema
- 把 GEO 页加进 sitemap（playbook 03 的 build_posts_sitemap 应已包含）
- 详情页抽样查推荐位是否生效（不命中时不显示空块）
- 50 城清单与 protectedGeoSlugs 同步

---

## 不要做的事

- ❌ **不要全 LLM 编城市数据** — 编造的"乐清有 800 家阀门厂"会被竞品揭穿，掉权
- ❌ **不要做 100+ 城** — 长尾城市搜索量不够，铺了反而拉低站点平均质量分
- ❌ **不要让 GEO 页 < 600 字** — 命中清风算法（薄页）
- ❌ **不要在 GEO 页堆同义词** — "上海阀门 上海阀门厂家 上海阀门批发 上海阀门价格"叠加 = 命中清风
