# Playbook 06 — LLM Prompting（李继刚四层结构）

> **不论你用 GPT / Claude / GLM / DeepSeek，只要内容给 SEO 用就走这套。**

来源：guonika 全站 8 个 LLM 入口（7 PHP + 1 Python）已 100% 落地此结构。
配套实现：`scripts/llm/llm_prompt_kit.php`（站全局 LLM 配置 + 模板单点真源）。

---

## 四层结构

每个 LLM 调用必须由这四段组成（顺序也重要）：

```
[角色]   含立场 / 经历 / 心法 / 读者画像
   ↓
[规则]   含必带字段 + 黑名单 + 量化锚 + 不许做什么
   ↓
[自检]   交付前必须自己列出 N 条数字 / M 条标准号
   ↓
[温度]   一般 0.7；偏创造 0.8；偏严谨 0.5
```

---

## Layer 1：角色（不是"你是 SEO 写手"那种废话）

**反例（不要这么写）：**
> 你是一个专业的 SEO 写作助手，请帮我写一篇关于阀门的文章。

**正例：**
> 你是李文卿，做工业阀门贸易 12 年。你给采购员写选型笔记，不写广告词。
> 你的读者：80% 是工厂采购、50 岁上下、看价格也看接口标准。
> 你的心法：能给一个具体型号绝不写"多种规格"；能引一条 GB 标准绝不写"国家标准"。

**3 个关键要素：**
- **身份具体**（名字 / 行业年限）
- **读者画像具体**（人群 / 年龄 / 关心点）
- **心法**（"宁可少不可虚"类的写作信条）

---

## Layer 2：规则（含黑名单）

### 必带字段约束

```
必带：
  - 至少 3 个具体数字（含价格区间 / 产值 / 标准号编号 / 城市排名等）
  - 至少 1 条标准号（GB/T xxxx / JB/T xxxx / ISO xxxx）
  - 至少 1 个具体产业带（苏锡常 / 佛山 / 温州乐清 / 柳市 / 慈溪 / 余姚 / 张家港 / 宁波镇海 / 东莞 / ...）
```

### AI 八股黑名单（不许出现）

完整 14 词，参见 `references/prompt-anti-patterns.md`：

```
蓬勃发展 / 未来可期 / 赋能 / 生态闭环 / 数智化 / 降本增效 /
新质生产力 / 护城河 / 底层逻辑 / 综上所述 / 随着...的不断深入 /
作为 AI 模型 / 根据搜索 / 请咨询客服
```

### 反编造约束

```
禁止：
  - 编造具体公司名（除公知品牌如三一 / 徐工 / 富士康）
  - 编造具体人名 / 电话
  - 编造绝对价格点（"永远 5800 元"）；用区间："约 5500-6200 元"
  - 编造未公开的产能数字
```

---

## Layer 3：自检（交付前自己回答）

```
在输出最终文章前，先用 4 行自检：
  1. 数字 ≥ 3 条 ✓ / ✗  （列出具体哪 3 条）
  2. 标准号 ≥ 1 条 ✓ / ✗  （列出哪一条）
  3. 产业带 ≥ 1 个 ✓ / ✗  （列出哪一个）
  4. 黑名单命中数 = 0 ✓ / ✗  （列出命中哪几个）

如果任何一项 ✗，重写。
```

**实测有效**：guonika 加自检前升级稿黑名单命中率 18%；加后降到 < 2%。

---

## Layer 4：温度

| 场景 | 温度 |
|---|---|
| weak posts 升级（保守，怕飘） | 0.5 |
| topic / flagship 写作 | 0.7 |
| FAQ 生成（要灵活） | 0.8 |
| 标题 / TDK（创造性） | 0.85 |

---

## llm_prompt_kit.php 怎么用

```php
require_once 'config/llm_prompt_kit.php';

$res = LlmPromptKit::call([
    'role'     => 'industrial_writer_lijigang',  // 预设角色 ID
    'rules'    => ['must_3_numbers', 'must_1_standard', 'must_1_industry_belt', 'no_ai_cliche'],
    'self_check' => true,
    'temperature' => 0.7,
    'user_input' => $promptBody,
]);
```

`llm_prompt_kit.php` 内置：
- 7 个预设角色（writer / editor / faq_generator / title_optimizer / 等）
- 14 词黑名单 + 自检模板
- LLM endpoint / key / model 单点真源（项目配置只改 config.php）

---

## guonika 8 入口清单（参考）

| # | 入口 | 用途 | 角色 |
|---|---|---|---|
| 1 | upgrade_weak_posts.php | 升级薄稿 | writer |
| 2 | refresh_weak_industrial_posts.php | 重写工业薄稿 | writer |
| 3 | seed_core_industrial_coverage.php | 补全核心覆盖 | writer |
| 4 | enrich_hubs_with_llm.php | 给 hub 加深度 | editor |
| 5 | inject_*_faq.php （4 个） | 生成 FAQ | faq_generator |
| 6 | generate_topic_pages.php | topic 写作 | writer |
| 7 | generate_quote_pages.py | 行情页生成 | analyst |
| 8 | generate_trade_industry_insights.php | 行业洞察 | analyst |

---

## 验收

- 抽 20 篇 LLM 产出查 4 项必带 + 黑名单 → 95%+ 通过
- 修改任意一个 LLM 入口的提示词 → 只需改 `llm_prompt_kit.php`，不需要改入口本身
- LLM endpoint / key 切换 → 只改 `config.php` 一处

---

## 不要做的事

- ❌ 不要让用户的 raw 输入直接当 prompt — 永远先包到角色 + 规则里
- ❌ 不要让 LLM 直接写公司名 / 电话 — 这些字段必须从结构化数据透传
- ❌ 不要省略自检层 — 黑名单命中率会从 < 2% 飙到 18%
- ❌ 不要 prompt 里写"请专业、严谨" — 全是空话，要写"必带 GB/T 编号至少 1 条"
