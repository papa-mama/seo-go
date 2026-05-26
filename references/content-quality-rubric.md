# Content Quality Rubric（弱稿打分）

> **80 分以上的稿不动，60-80 分的标 normal，< 60 分的进 weak 队列升级。**

---

## 7 维打分（满分 100）

| 维度 | 权重 | 计分规则 |
|---|---|---|
| 字数 | 20 | < 400 字 = 0；400-800 = 10；800-1500 = 18；1500+ = 20 |
| 数字密度 | 20 | 每 200 字 1 个数字 = 满分 20；按比例计 |
| 标准号 | 15 | 0 条 = 0；1 条 = 10；2+ 条 = 15 |
| 产业带 / 城市 | 15 | 0 个 = 0；1 个 = 10；2+ 个 = 15 |
| AI 八股黑名单 | 15 | 0 个命中 = 15；每命中 1 个 -3；命中 ≥ 5 = 0 |
| 段落均匀度 | 10 | 单段 > 800 字 = 0；段落 8-30 句 = 10；中间按比例 |
| 标题质量 | 5 | 含模糊词（"等" "的方法" "深度解析"）= 0；具体型号/数字 = 5 |

---

## 实施代码（伪码）

```python
def score_post(post):
    score = 0
    text = post['content']
    n = char_count(text)

    # 字数
    if n >= 1500: score += 20
    elif n >= 800: score += 18
    elif n >= 400: score += 10

    # 数字密度
    num_count = count_numbers(text)
    expected = n / 200
    score += min(20, int(20 * num_count / max(1, expected)))

    # 标准号
    std_count = count_regex(text, r'(GB|JB|HG|ISO|IEC)/?T?\s*\d+')
    score += [0, 10, 15, 15, 15, 15][min(5, std_count)]

    # 产业带 / 城市
    cities = count_cities(text, GEO_BELT_LIST)
    score += [0, 10, 15, 15, 15, 15][min(5, cities)]

    # AI 八股黑名单
    blacklist_hit = sum(1 for w in BLACKLIST_14 if w in text)
    score += max(0, 15 - 3 * blacklist_hit)

    # 段落均匀度
    paras = split_paragraphs(text)
    longest = max(len(p) for p in paras) if paras else 0
    if longest > 800: score += 0
    elif 200 <= longest <= 600: score += 10
    else: score += 5

    # 标题质量
    title = post['title']
    if any(w in title for w in ['等', '的方法', '深度解析', '完全攻略']):
        score += 0
    elif re.search(r'\d|GB|JB|ISO', title):
        score += 5
    else:
        score += 2

    return score
```

---

## 阈值

| 分段 | 标记 | 处理 |
|---|---|---|
| 80-100 | excellent | 不动 |
| 60-79 | normal | 标记观察，不主动改 |
| 40-59 | weak | 进升级队列（playbook 05） |
| 0-39 | toxic | 直接 status=hidden 下线 |

---

## guonika 实战分布

```
247 万 posts 总分布：
  excellent (80+) :  87 万 (35%)
  normal (60-79)  :  73 万 (30%)
  weak (40-59)    :  53 万 (21%) ← 升级目标
  toxic (<40)     :  34 万 (14%) ← 直接下线
```

首轮升级 1336 篇 weak posts 后：
- 平均分从 48 → 73
- 黑名单命中率 18% → < 2%
- 站点收录率 +12%

---

## 不要做的事

- ❌ 不要单维度判定 — 字数高但数字少的稿子也是 weak
- ❌ 不要让评分自动改稿子 — 必须人审或 LLM 三层结构升级（playbook 05+06）
- ❌ 不要把 toxic 稿子简单删 — 保留 status=hidden 防 404，必要时 301 到相近内容
