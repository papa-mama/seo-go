# Playbook 08 — Baidu Push

> **百度收录 API。中国大陆 SEO 必跑；海外站可跳过。**

guonika 用法：每日定额 + 优先级队列。`reference_baidu_push_quirks.md` 记了具体坑。

---

## 关键事实（不要踩）

1. **site 必须裸域** — `https://guonika.com`，不带 `www`，不带 `/`，不带 `?`
2. **新站每日普通收录配额 ~10 条** — 老站要看 [百度站长平台 - 资源提交]
3. **快速收录配额另算** — 需站长平台单独申请
4. **同一 URL 推过 1 次会被 dedupe** — 不要重复推
5. **接口返回 not_same_site / not_valid 都是 site 字段格式错** — 90% 的故障是这个

完整坑：`reference_baidu_push_quirks.md`。

---

## Step 1：配置

在 `config.php` 加：

```php
define('BAIDU_PUSH_SITE', 'https://your-domain.com');     // 裸域，必须
define('BAIDU_PUSH_TOKEN', '<from baidu webmaster>');
define('BAIDU_PUSH_ENDPOINT', 'http://data.zz.baidu.com/urls');
define('BAIDU_PUSH_DAILY_QUOTA', 10);                     // 新站默认
```

---

## Step 2：生成推送队列

按优先级排：

| 优先级 | 内容 |
|---|---|
| P0 | 当日新生成的 topic / flagship 页 |
| P1 | 当日新升级的 weak posts |
| P2 | 当日新公司 / 产品 / trade leads |
| P3 | 历史未推送的高价值长尾页 |

跑：

```bash
php scripts/seo/baidu_push.php --plan-only      # 看今天准备推哪些
php scripts/seo/baidu_push.php --execute        # 真推
php scripts/seo/baidu_push.php --execute --max=10  # 限量
```

---

## Step 3：站点验证（一次性）

如果站长平台没验证过，curl 推会返回 401 / 403。

3 种验证方式（任选）：
1. **HTML 文件验证** — 下载验证文件，扔到站点根目录（如 `baidu_verify_codeva-XXXX.html`）
2. **CNAME 验证** — DNS 加 CNAME 指向百度
3. **meta 标签验证** — `<head>` 加 `<meta name="baidu-site-verification" content="...">`

guonika 用方式 1。

---

## Step 4：监控配额

每次推送后接口返回：

```json
{
  "remain": 9999,         ← 剩余配额
  "success": 8,            ← 成功数
  "not_same_site": [],     ← 域名不匹配的（必须排空）
  "not_valid": []          ← URL 格式错的
}
```

`baidu_push.php` 已经记录到 `logs/baidu_push.log`。每日 health 脚本会读它。

---

## 验收

- 站长平台 [资源提交]页 看"普通收录" 24h 内有数据
- `baidu_push.log` 最近 7 天每天有记录
- `not_same_site` / `not_valid` 累计 = 0
- 收录率（推送 N 条 → 实际收录 M 条）≥ 30%

---

## 不要做的事

- ❌ 不要推 `?q=` `?utm_*` 类 URL — 100% 会被拒
- ❌ 不要推 < 800 字的页 — 即使收录了也没排名
- ❌ 不要重复推已经收录的 URL — 浪费配额
- ❌ 不要用宝塔默认 site URL 配置 — 那个带 `https://www.` 是错的
