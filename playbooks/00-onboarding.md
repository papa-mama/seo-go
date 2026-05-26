# Playbook 00 — Onboarding

> **任何 seo-go 任务的第一步。不允许跳过。**

你（AI 助手）刚被用户拉进一个新项目。在动任何代码之前，**必须**完成本剧本的 4 步盘点。

---

## Step 1：识别 5 类核心数据资产

询问用户或直接 grep / SQL 探查，确认下列资产是否存在。**结果决定后续剧本的可用性。**

| 资产类型 | 含义 | 在 guonika 的对应表 | 没有时的影响 |
|---|---|---|---|
| **A. 关键词词库** | 历史投放词 / 竞价词 / SEO 词典 | `bd_keyword_mapping`（190 万）| 跳过 playbook 02 的 query-hub 矩阵 |
| **B. 真实搜索日志** | 用户搜过 + 落地 URL + IP/UA | `bd_keyword_tracking`（31 万）| 选题靠词频统计而非真实热度 |
| **C. 缺口词** | 搜过但站内没内容 | `unmatched_keywords`（17 万）| 跳过"按缺口生成长尾页"流程 |
| **D. 落地统计** | 落地页 PV/UV + 省市维度 | `bd_landing_stats`（1191 万）| 跳过 GEO 矩阵的"按真实地区分布裁剪" |
| **E. 内容主表** | posts / articles / news / products | `posts`（247 万）| 几乎所有剧本都需要，缺则只能从零生产 |

**最低门槛：E 必须有**。A/B/C/D 至少有 A 才能跑完整 SEO 路径，否则只能跑"基建+模板"那部分。

输出物（给用户看）：

```
数据资产体检：
  A. 词库            [ ✓ / ✗ ]   表名: ___    行数: ___
  B. 真实搜索日志    [ ✓ / ✗ ]   表名: ___    行数: ___
  C. 缺口词          [ ✓ / ✗ ]   表名: ___    行数: ___
  D. 落地统计        [ ✓ / ✗ ]   表名: ___    行数: ___
  E. 内容主表        [ ✓ / ✗ ]   表名: ___    行数: ___
```

---

## Step 2：摸基线（4 大维度）

### 2.1 性能基线

```bash
# 测首页 / 列表页 / 详情页 cold + hot 各 3 次
for path in / /products/ /products/1 /news/ /companies/; do
  for i in 1 2 3; do
    curl -sk -o /dev/null -H "Host: $DOMAIN" \
      -w "$path try$i %{time_total}s\n" "http://127.0.0.1$path"
  done
done
```

记录：每页 cold / hot ms，用作后续优化的对比基线。

### 2.2 SEO 基础件

| 检查项 | 命令 / 看哪 | 合格线 |
|---|---|---|
| robots.txt | `curl https://$DOMAIN/robots.txt` | 必须有 Sitemap: 行 + Host: 行 |
| sitemap-index | `curl https://$DOMAIN/sitemap-index.xml` | 必须存在且引用所有分片 |
| sitemap 分片 | 单文件 < 50MB / < 5 万 URL | 超过必切片 |
| canonical | 抽样 10 个详情页查 `<link rel="canonical">` | 100% 覆盖 |
| og: / twitter: | 抽样查 head | 100% 覆盖 |
| schema | view-source 找 `application/ld+json` | 详情页至少 1 类 |

参见 `references/data-pipeline.md` 第 2 节。

### 2.3 缓存状态

```bash
# 是否有 cache 层
grep -r "SimpleCache\|memcache\|redis\|apcu\|wp_cache" includes/ pages/ api/ | head
# cache dir 在哪
echo $CACHE_DIR
# shard 桶健康
find $CACHE_DIR -type d -uid 0 | wc -l   # 必须 = 0
ls $CACHE_DIR | head
```

**如果没有 cache 层**：playbook 01 是必须先跑的（其他剧本性能受限）。
**如果有但 shard 桶 root_owned > 0**：先看 `references/cache-perms-trap.md`，再跑 `scripts/infra/fix_cache_perms.sh`。

### 2.4 内容矩阵覆盖度

```bash
# 看路由 / pages 目录有哪些类别
ls pages/
# 看 topics / flagship / geo 目录是否存在
ls topics/ flagship/ 2>/dev/null
```

---

## Step 3：识别"想做什么"

让用户从下面 6 类目标里选（可多选）：

| # | 目标 | 主剧本 | 副剧本 |
|---|---|---|---|
| 1 | 站慢，先解决性能 | 01 cache-foundation | 07 monitoring |
| 2 | 内容太薄，要铺矩阵 | 02 content-architecture | 04 geo / 05 pipeline |
| 3 | SEO 基础件不全（无 sitemap / 无 schema） | 03 seo-foundations | — |
| 4 | 要做 GEO / 地市矩阵 | 04 geo-localization | 02 / 03 |
| 5 | 已有大量低质内容，要救 | 05 content-pipeline | 06 prompting |
| 6 | LLM 写出来全是 AI 八股 | 06 llm-prompting | — |

---

## Step 4：路线建议输出（必须给用户）

给用户一份"建议执行顺序 + 预期产出 + 不可执行项"的总结。例：

```
【seo-go 路线建议】

资产现状：
  - 内容主表 ✓（articles，1.2 万）
  - 词库 ✗（无）→ 跳过 query-hub 矩阵
  - 缓存层 ✗（无）→ 必须先跑 playbook 01

建议路线（按顺序）：
  Phase 1：playbook 01 — 加缓存层（预期：列表页 hot < 50ms）
  Phase 2：playbook 03 — 补 SEO 基础件（sitemap-index / schema / canonical）
  Phase 3：playbook 02（去掉 query-hub 部分）— 铺 topic + flagship 矩阵
  Phase 4：playbook 04 — 用文章 region 字段铺 GEO 矩阵
  Phase 5：playbook 05 + 06 — 用 LLM 升级低质文 + 反 AI 八股

预计不可执行：
  - playbook 08（百度推送）：用户站不在中国大陆主推，先不做
  - playbook 02 query-hub 矩阵：缺词库
```

---

## 完成 onboarding 的 checklist

- [ ] 5 类资产清单已给用户
- [ ] 性能基线数字已记录（保存到 `logs/baseline.txt` 或对话记录）
- [ ] SEO 基础件状态已盘
- [ ] 缓存层状态已盘
- [ ] 路线建议已给用户，用户已选定 phase 1

只有 5 个全部 ✓，才能进入下一个 playbook。
