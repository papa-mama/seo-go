<!-- GEO 城市页骨架。占位符见底部说明。 -->
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>__CITY__ + __INDUSTRY__ 产业带 — __BELT_NAME__ 选型/价格/厂家</title>
<meta name="description" content="__CITY__ __INDUSTRY__ 产业带（__BELT_NAME__）选型与价格指南。约 __PLANT_COUNT__ 家厂，参考 __STANDARD_NO__，价格区间 __PRICE_RANGE__。包含临近 __NEAR_BELTS__ 工业带数据。">
<link rel="canonical" href="https://__DOMAIN__/geo/__SLUG__">

<!-- Open Graph -->
<meta property="og:title" content="__CITY__ + __INDUSTRY__ 产业带 — __BELT_NAME__">
<meta property="og:description" content="__SHORT_DESC__">
<meta property="og:image" content="https://__DOMAIN__/assets/img/cover/__INDUSTRY_SLUG__.jpg">
<meta property="og:type" content="article">
<meta property="og:url" content="https://__DOMAIN__/geo/__SLUG__">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="__CITY__ + __INDUSTRY__ 产业带">
<meta name="twitter:description" content="__SHORT_DESC__">
<meta name="twitter:image" content="https://__DOMAIN__/assets/img/cover/__INDUSTRY_SLUG__.jpg">

<!-- LocalBusiness schema -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "__BELT_NAME__ 集散区",
  "description": "__SHORT_DESC__",
  "address": {
    "@type": "PostalAddress",
    "addressLocality": "__CITY__",
    "addressRegion": "__PROVINCE__",
    "addressCountry": "CN"
  },
  "areaServed": "__BELT_NAME__"
}
</script>
</head>
<body>
<h1>__CITY__ + __INDUSTRY__ 产业带 — __BELT_NAME__</h1>

<section class="overview">
  <p>__BELT_NAME__ 集中在 __CITY__ ___ADDR_DETAIL__ 一带，约 __PLANT_COUNT__ 家工厂、年产值约 __OUTPUT_VALUE__ 亿元，参考标准 __STANDARD_NO__。距 __NEAR_BELT_1__ 约 __DISTANCE_1__ km，与 __NEAR_BELT_2__ 共同构成 __INDUSTRY__ 长江三角洲/珠江三角洲走廊。</p>
</section>

<section class="price-table">
  <h2>价格 / 选型一览</h2>
  <!-- 至少 1 张含真实价格区间的表 -->
</section>

<section class="company-list">
  <h2>代表企业</h2>
  <!-- 只列公知品牌或留位"按需问询"，禁止编造企业名 -->
</section>

<section class="services">
  <h2>本地配套（物流 / 仓储 / 检测）</h2>
</section>

<section class="faq">
  <h2>常见问题</h2>
  <!-- 嵌入 faq-schema-block.html.tpl 内容，至少 3 条 -->
</section>

<aside class="internal-links">
  <h3>同城其他行业</h3>
  <!-- 同城 ≥ 3 链 -->
  <h3>同行业其他城市</h3>
  <!-- 同行业 ≥ 3 链 -->
  <h3>相关 topic / flagship</h3>
  <!-- ≥ 2 链 -->
</aside>
</body>
</html>

<!-- ============== 占位符说明 ==============
__DOMAIN__         站点裸域 (e.g. example.com)
__CITY__           城市中文名 (e.g. 乐清)
__PROVINCE__       省份 (e.g. 浙江)
__INDUSTRY__       行业中文名 (e.g. 低压电气)
__INDUSTRY_SLUG__  行业 slug (e.g. low-voltage-electric)
__BELT_NAME__      产业带名 (e.g. 柳市镇)
__SLUG__           本页 slug (e.g. yueqing-low-voltage)
__PLANT_COUNT__    工厂数量（必须真实，参 references/geo-cities-china.md）
__OUTPUT_VALUE__   产值（亿元）
__STANDARD_NO__    标准号 (e.g. GB/T 14048)
__PRICE_RANGE__    价格区间 (e.g. 50-3500元/件)
__NEAR_BELTS__     临近工业带列表
__SHORT_DESC__     80-160 字描述
============================================ -->
