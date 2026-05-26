# Playbook 05 — Content Pipeline

> **大批量低质内容怎么救活 + 怎么持续生产新内容。**

guonika 实战：247 万 posts 中 ~40 万被识别为 weak posts；首轮升级 1336 篇（concurrency 10 是 LLM 甜点）。

---

## 三件事

1. **识别低质内容**（weak posts detection）
2. **批量升级**（refresh + upgrade）
3. **持续生产新内容**（generation queue + growth flywheel）

---

## Step 1：定义"低质"

参考 `references/content-quality-rubric.md`。打分维度：

| 维度 | 权重 | 不及格信号 |
|---|---|---|
| 字数 | 20% | < 400 字 |
| 数字密度 | 20% | 全文 < 3 个数字 |
| 标准号 | 15% | 无 GB/JB/ISO/HG 标识 |
| 城市 / 产业带 | 15% | 无具体地名 |
| AI 八股黑名单命中 | 15% | 命中 ≥ 3 个 |
| 段落均匀度 | 10% | 单段 > 800 字（堆砌迹象） |
| 标题质量 | 5% | 标题含"等"/"的方法"等模糊词 |

总分 < 60 → weak post。

---

## Step 2：升级 weak posts

```bash
# 看占位符（表名 / LLM endpoint）
head -50 scripts/content/upgrade_weak_posts.php

# 试跑
php scripts/content/upgrade_weak_posts.php --limit=10 --dry-run

# 批量
php scripts/content/upgrade_weak_posts.php --limit=500 --concurrency=10
```

**concurrency=10 是 guonika 实测的 LLM 甜点**：
- < 5 → 太慢，500 篇要 4 小时
- > 15 → LLM 端开始限流 / 出错率↑
- 10 → 500 篇约 35 分钟，错误率 < 1%

---

## Step 3：升级时的反 AI 八股

每次 LLM 调用都走"李继刚四层结构"（playbook 06 详解）：
- **角色**：含立场 / 经历 / 心法
- **规则**：含黑名单（"蓬勃发展"/"未来可期"/"赋能"/"生态闭环"等 14 个禁词）
- **自检**：必须列 3 条数字 / 1 条标准号 / 1 个城市
- **温度**：0.7（创造性 / 严谨平衡点）

完整黑名单：`references/prompt-anti-patterns.md`。

---

## Step 4：内容生成队列

guonika 用 `generation_queue` 表 + `process_generation_queue.php` worker：

```
任务源 → unmatched_keywords / 缺口词列表
  ↓
入队（generation_queue: pending）
  ↓
worker 拉队列 → LLM 生成 → 自检 → 入库
  ↓ 失败：retry 3 次后标 failed
  ↓ 成功：标 generated + 写 posts 表
```

**关键参数：**
- batch_size: 50
- concurrency: 10（LLM 甜点）
- max_retry: 3
- queue 高水位：1000（超过先 throttle 不再入队）

---

## Step 5：growth flywheel（自动循环）

`scripts/seo/run_growth_flywheel.php`（在 audit 下，guonika 实战版）做了一件事：
**每天循环跑：识别缺口 → 入队 → 生成 → 注入 schema → 推送 → 监控**。

```bash
# 单次手跑
php scripts/audit/run_growth_flywheel.php --once

# cron 挂法
0 2 * * * cd /path/to/project && php scripts/audit/run_growth_flywheel.php --once >> logs/growth.log 2>&1
```

监控产出：`scripts/audit/report_growth_flywheel_health.php`。

---

## 验收

- 升级前后抽样 20 篇，质量分提升 ≥ 20 分
- 抽 10 篇升级稿查 AI 八股黑名单：命中数 ≤ 1
- 每篇至少 3 个数字 + 1 个标准号 + 1 个城市
- generation_queue 健康度（pending / generated / failed 比例）通过 health 脚本输出

---

## 不要做的事

- ❌ **不要无 dry-run 直接批量升级** — 出 bug 会污染几百篇文章
- ❌ **不要让 LLM 失败稿默默入库** — 必须自检不通过就回滚
- ❌ **不要靠 concurrency 30+ 抢速度** — 命中限流后稿子质量飘忽
- ❌ **不要用 LLM 改公司名 / 电话 / 价格** — 永远透传源数据，LLM 只重写描述段
