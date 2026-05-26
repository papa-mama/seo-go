# Playbook 07 — Monitoring

> **不监控就是盲飞。一年里最值钱的不是新功能而是"哪天出问题第一时间知道"。**

---

## 三层监控

| 层 | 频率 | 脚本 | 看什么 |
|---|---|---|---|
| L1 cache_pulse | 5 min | `scripts/audit/cache_pulse.php` | shard 健康 + 热 key 命中 + 8 URL 延迟 |
| L2 crawler_pulse | 1 hour | `scripts/audit/crawler_pulse.sh` | 百度/Google/ClaudeBot 抓取频次 + 4xx/5xx 比例 |
| L3 growth_flywheel_health | 1 day | `scripts/audit/report_growth_flywheel_health.php` | 内容生成 / 升级 / 推送 / 收录 全链路 KPI |

---

## L1：cache_pulse（playbook 01 已部分覆盖）

### 看什么

```
[cache-pulse] 2026-05-26 13:20:27
  shards: total=256 root_owned=0 writable=256       ← 必须 root_owned=0
  watch keys:
    [HIT] home_quote_cards_v1     age=  907s ...    ← HIT 率 100%
    [HIT] home_hub_count_v1       age=  980s ...
  urls (cold / hot-avg ms):
    [OK ] home    414ms /  373ms  195323B            ← hot < 500ms
    [OK ] news     11ms /   13ms  104421B            ← hot < 50ms
```

### 报警阈值

- `root_owned > 0` → 立即跑 `fix_cache_perms.sh`，看 `references/cache-perms-trap.md`
- `hot_avg_ms > 500` 持续 3 次 → 缓存 miss 或 LLM 慢，查 logs/cache_pulse.log 趋势
- 任何 watch key MISS → 该 key 业务路径有问题，bump v 强制重建

---

## L2：crawler_pulse（爬虫观测）

`scripts/audit/crawler_pulse.sh` 做的事：
- 解析 nginx access log
- 统计 1 小时内不同 bot UA（Baiduspider / Googlebot / Bingbot / ClaudeBot 等）的命中数
- 统计 4xx / 5xx 响应比例
- 输出 JSON 行到 `logs/crawler_pulse.log`

### 看什么

- **百度抓取数突降 > 50%** → 看是否有 robots.txt 误改 / sitemap 超大 / 站点 503
- **5xx 比例 > 1%** → 看 nginx error.log 找根因
- **某 bot 完全没来 > 24 小时** → 大概率被自己的防火墙拦了（Cloudflare / 安全狗 / 宝塔防火墙）

### cron 挂法

```cron
0 * * * * cd /path/to/project && bash scripts/audit/crawler_pulse.sh >> logs/crawler_pulse.log 2>&1
```

---

## L3：growth_flywheel_health

`scripts/audit/report_growth_flywheel_health.php` 输出整站健康分（满分 100）：

```
【growth flywheel health】2026-05-26
  内容生成： queue=234 generated_24h=167 failed_24h=3       ↑+12  分: 18/20
  弱稿升级： weak_remaining=3.4万 upgraded_24h=212          →     分: 14/20
  schema 覆盖： topic 100% / flagship 100% / detail 87%      ↓-2   分: 17/20
  sitemap 健康： 4 分片 / 247 万 URL / 上次 build=3h ago     →     分: 18/20
  百度推送： 余额=12 today=10 yesterday=10                  →     分: 8/10
  cache 命中： 4/4 watch HIT, root_owned=0                   →     分: 10/10
  ────────────
  total: 85 / 100   trend: ↑ +2 vs yesterday
```

### 报警阈值

- total 单日掉 > 5 分 → 立即查趋势
- 某项目掉 > 30% → 进对应 playbook 排查

---

## 持续记录最重要

`logs/cache_pulse.log` / `logs/crawler_pulse.log` / `logs/growth_health.log` 都是**追加式 JSON 行**。

历史趋势比单点数字更值钱。养成查"过去 7 天均值"的习惯，而不是只看现在。

guonika 实战：cache 权限坑就是因为单点延迟看起来正常，但 7 天 hot_avg 偷偷涨了 80% 才暴露。

---

## 验收

- 三层监控的 cron 都挂上
- 各 log 文件最近 24h 都有 ≥ 6 条新数据
- growth health 总分稳定 ≥ 80
- 任意一天看 7 天趋势能发现"今天比平均高 2 倍"类异常

---

## 不要做的事

- ❌ 不要把监控写到 stdout 不落 log — 出问题没法回溯
- ❌ 不要用单一阈值 — 看趋势 / 7 天均值偏移更靠谱
- ❌ 不要把 log 跟项目 git 一起 push — 加 .gitignore
- ❌ 不要监控不报警 — 阈值触发要至少打到 stderr 让 cron 邮件能收到
