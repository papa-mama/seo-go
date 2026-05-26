# Data Pipeline（5 类核心数据资产规范）

任何 SEO/GEO/内容矩阵改造的前提：知道你站里有什么数据。

---

## 5 类资产

### A. 关键词词库

**作用：** 选题词典 / 长尾页矩阵的源头。

**字段建议：**
```sql
CREATE TABLE bd_keyword_mapping (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    keyword VARCHAR(255) NOT NULL,
    keyword_md5 CHAR(32) UNIQUE,
    cluster VARCHAR(64),       -- price / manufacturer / model / spec
    intent VARCHAR(32),        -- info / nav / trans / commercial
    search_volume INT,
    cpc DECIMAL(8,2),
    industry VARCHAR(64),
    region VARCHAR(64),
    created_at TIMESTAMP,
    INDEX idx_cluster (cluster),
    INDEX idx_industry (industry)
);
```

**guonika 实例：** 190 万词，含价格类 5.1 万 / 厂家类 4 万 / 多少钱类 5.5 万 / 型号类 1.2 万 / 品牌类 2.6 万。

---

### B. 真实搜索日志

**作用：** 反映真实用户意图（比静态词库更有热度信号）。

**字段建议：**
```sql
CREATE TABLE search_tracking (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    q VARCHAR(255) NOT NULL,
    q_md5 CHAR(32),
    landing_url VARCHAR(512),
    referer VARCHAR(512),
    user_ip VARCHAR(64),
    user_agent VARCHAR(255),
    province VARCHAR(64),
    city VARCHAR(64),
    is_bot TINYINT(1),
    created_at TIMESTAMP,
    INDEX idx_q_md5 (q_md5),
    INDEX idx_created (created_at)
);
```

**采集：** nginx access log → 解析 `?q=` → 入表（异步批处理）。

---

### C. 缺口词

**作用：** 用户搜过但站内无对应内容 = 直接选题清单。

**字段建议：**
```sql
CREATE TABLE unmatched_keywords (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    keyword VARCHAR(255) NOT NULL UNIQUE,
    search_count INT DEFAULT 1,
    first_seen_at TIMESTAMP,
    last_seen_at TIMESTAMP,
    status ENUM('pending', 'queued', 'generated', 'rejected') DEFAULT 'pending',
    generated_post_id BIGINT,
    INDEX idx_status (status),
    INDEX idx_search_count (search_count)
);
```

**采集逻辑：**
```sql
-- 每 1 小时跑一次
INSERT IGNORE INTO unmatched_keywords (keyword, search_count, first_seen_at, last_seen_at)
SELECT s.q, COUNT(*), MIN(s.created_at), MAX(s.created_at)
FROM search_tracking s
LEFT JOIN posts p ON MATCH(p.title) AGAINST (s.q IN BOOLEAN MODE)
WHERE p.id IS NULL                 -- 没匹配到内容
  AND s.created_at > NOW() - INTERVAL 24 HOUR
  AND s.is_bot = 0
GROUP BY s.q
HAVING COUNT(*) >= 2;              -- 至少搜过 2 次
```

---

### D. 落地统计

**作用：** 知道哪些页在哪些省市有流量 → GEO 矩阵裁剪的依据。

**字段建议：**
```sql
CREATE TABLE landing_stats (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    landing_url VARCHAR(512),
    landing_url_md5 CHAR(32),
    province VARCHAR(64),
    city VARCHAR(64),
    pv_count INT DEFAULT 0,
    uv_count INT DEFAULT 0,
    bounce_count INT DEFAULT 0,
    date DATE,
    INDEX idx_url_date (landing_url_md5, date),
    INDEX idx_city_date (city, date)
);
```

---

### E. 内容主表

**作用：** 几乎所有剧本都依赖。

**字段建议（最小集）：**
```sql
CREATE TABLE posts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE,
    content LONGTEXT,
    summary VARCHAR(512),
    cover_image VARCHAR(512),
    category_id INT,
    region VARCHAR(64),                -- 用于 GEO 矩阵
    industry VARCHAR(64),               -- 用于行业切片
    source_keyword VARCHAR(255),        -- 来自哪个词
    quality_score TINYINT DEFAULT 0,    -- 0-100
    status TINYINT DEFAULT 1,           -- 1=public, 0=hidden
    views INT DEFAULT 0,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_status_score (status, quality_score),
    INDEX idx_region (region),
    INDEX idx_industry (industry),
    FULLTEXT idx_ft (title, summary)
);
```

---

## 数据资产盘点 SQL（onboarding 用）

```sql
-- 5 类资产存在性 + 行数 + 最新数据时间
SELECT 'keyword_mapping' AS tbl, COUNT(*) AS rows, MAX(created_at) AS latest FROM bd_keyword_mapping
UNION ALL
SELECT 'search_tracking', COUNT(*), MAX(created_at) FROM search_tracking
UNION ALL
SELECT 'unmatched_keywords', COUNT(*), MAX(last_seen_at) FROM unmatched_keywords
UNION ALL
SELECT 'landing_stats', COUNT(*), MAX(date) FROM landing_stats
UNION ALL
SELECT 'posts', COUNT(*), MAX(updated_at) FROM posts;
```

---

## 数据流图

```
nginx access log
   ↓ parse （每 5min）
search_tracking
   ↓ aggregate （每 1h）
unmatched_keywords ── pending → queue
                                  ↓ LLM 生成
                                posts (status=1, source_keyword=<kw>)
                                  ↓ 标 generated
                              unmatched_keywords.status=generated
   ↓
bd_keyword_mapping ── cluster → query_hub_pages
   ↓
landing_stats ── city aggregation → GEO 城市清单 → geo_pages

posts ── quality_score → weak_posts ── LLM 升级 → posts (refreshed)
```

---

## 不要做的事

- ❌ 不要把 search_tracking 设成无 TTL（行数会爆）— guonika 保留 90 天，超过批量归档
- ❌ 不要把 unmatched_keywords 全部入队 — 至少要 search_count ≥ 2 才有价值
- ❌ 不要在 posts 表上不建 FULLTEXT — 词匹配性能差 100 倍
- ❌ 不要让 5 类表都用同一个时区 — 全部 UTC 或全部 +08:00 选一个，不要混
