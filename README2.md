# seo-go

> 这是 guonika.com 一年来"内容 + SEO + GEO + 缓存基建"完整升级路径的 SOP 化沉淀。
> 不是教程，不是博客，是**给 AI 助手（Clavue / Claude Code）读的可执行剧本**。

## 我是谁

`seo-go` 是一个 **Clavue/Claude Code skill**。它告诉 AI 助手：
当用户说"帮我把这个站做 SEO / 做 GEO / 做内容矩阵 / 做长尾 / 加缓存 / 救弱稿"，
**按 `playbooks/` 里的剧本逐步执行**，而不是临场发挥。

## 怎么用

### 装到一个新项目里

```bash
cd /path/to/your/project
mkdir -p .claude/skills
git clone https://github.com/papa-mama/seo-go.git .claude/skills/seo-go
```

下一次跟 AI 助手对话，它会自动识别 SKILL.md 的触发词。

### 直接跟 AI 助手讲

> "用 seo-go skill 给我这个站做一次完整审计"
> "按 seo-go 的 playbook 04 把 GEO 城市矩阵铺起来"

AI 应该回的第一步永远是：**读 `playbooks/00-onboarding.md` 摸数据资产基线**。

## 仓库结构

```
seo-go/
├── SKILL.md              ← AI 入口（触发词 + 章节索引）
├── playbooks/            ← 9 个顺序剧本（00 → 08）
├── references/           ← 知识查询（算法、schema、prompt 反模式、城市映射等）
├── scripts/              ← 20 个参考实现（来自 guonika，标了占位符）
└── templates/            ← 复制即用的 robots / sitemap / FAQ / GEO 模板
```

## 设计原则

1. **方法论永远项目无关** — playbooks/references 不依赖任何特定数据库或域名
2. **scripts 是"参考实现"不是"开箱即用"** — 每个脚本头部标了 `PORTABILITY` 块，列出必改占位符 / 不可移植段
3. **AI 必须先盘资产再动手** — playbook 00 强制先识别 5 类数据资产（词库 / 搜索 / 缺口 / 落地 / 内容），不允许跳过
4. **不堆代码堆 SOP** — 真正值钱的是"踩过的坑"和"为什么这样做"，不是 Python/PHP 行数

## 来源项目

[guonika.com](https://guonika.com) — 工业 B2B 站，247 万 posts / 190 万词库 / 50 城 GEO 矩阵 / 791 个 topic 页。
所有 playbook 的"why"段落都来自真实事故和真实复盘。

## License

MIT。但请注意：这是私有 SOP，不接受外部 PR，issue 也可能直接关闭。
