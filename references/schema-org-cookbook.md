# Schema.org Cookbook（5 类落地模板）

每个模板都来自 guonika 实际站点，可直接抄。

---

## 1. Product

用在：产品详情页 / 求购供应详情页。

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "@id": "https://your-domain.com/products/<id>#product",
  "name": "<产品名 + 规格>",
  "description": "<160 字内说明>",
  "image": [
    "https://your-domain.com/assets/img/cover/<local-cover>.jpg"
  ],
  "category": "<分类名>",
  "brand": {
    "@type": "Brand",
    "name": "<公司名 / 品牌名>"
  },
  "offers": {
    "@type": "AggregateOffer",
    "priceCurrency": "CNY",
    "lowPrice": 5500,
    "highPrice": 6200,
    "offerCount": 10,
    "availability": "https://schema.org/InStock"
  }
}
</script>
```

**注意：**
- `availability` 求购用 `PreOrder`，供应用 `InStock`
- `lowPrice / highPrice` 必须真实价格段，不要乱写
- `image` 永远本地 URL（看 `feedback_local_images_only`）

---

## 2. LocalBusiness

用在：公司详情页 / GEO 城市页。

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "@id": "https://your-domain.com/companies/<id>#business",
  "name": "<公司名>",
  "image": "https://your-domain.com/assets/img/cover/<cover>.jpg",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "<街道>",
    "addressLocality": "<市>",
    "addressRegion": "<省>",
    "postalCode": "<邮编>",
    "addressCountry": "CN"
  },
  "geo": {
    "@type": "GeoCoordinates",
    "latitude": 31.2304,
    "longitude": 121.4737
  },
  "telephone": "+86-21-XXXX-XXXX",
  "openingHours": "Mo-Sa 08:30-18:00",
  "url": "https://your-domain.com/companies/<id>"
}
</script>
```

---

## 3. ClassifiedAdsPosting

用在：trade leads 详情页（求购供应）。

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ClassifiedAdsPosting",
  "@id": "https://your-domain.com/trade/<id>#post",
  "name": "<求购：xxx 规格 价格 区间 城市>",
  "description": "<160 字内>",
  "datePosted": "2026-05-26",
  "validThrough": "2026-06-26",
  "areaServed": {
    "@type": "Place",
    "name": "<地区>"
  },
  "offers": {
    "@type": "Offer",
    "priceCurrency": "CNY",
    "price": 5800,
    "availability": "https://schema.org/PreOrder"
  }
}
</script>
```

---

## 4. Dataset

用在：行情页 / 数据中心页。

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Dataset",
  "name": "<行业> 价格行情 - <月份>",
  "description": "<行业> 价格走势 + 产业带分布数据",
  "creator": {
    "@type": "Organization",
    "name": "your-domain.com"
  },
  "distribution": {
    "@type": "DataDownload",
    "encodingFormat": "text/html",
    "contentUrl": "https://your-domain.com/data/<slug>"
  },
  "temporalCoverage": "2026-05",
  "spatialCoverage": {
    "@type": "Place",
    "name": "中国"
  }
}
</script>
```

---

## 5. FAQPage（最高 ROI，所有矩阵页都加）

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "<问题 1>",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "<回答 1，必含 GB/T 或 JB/T 或具体数字>"
      }
    },
    {
      "@type": "Question",
      "name": "<问题 2>",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "<回答 2>"
      }
    },
    {
      "@type": "Question",
      "name": "<问题 3>",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "<回答 3>"
      }
    }
  ]
}
</script>
```

**约束：**
- ≥ 3 条
- 至少 1 条 answer 含 GB/T 或 JB/T 或具体数字
- 问题不要全用"是什么"开头，混合"怎么选 / 多少钱 / 哪里有 / 区别"

---

## 校验

每次改完用：
- [Google 富媒体测试](https://search.google.com/test/rich-results)
- [Schema.org Validator](https://validator.schema.org/)
- 百度站长平台 - [结构化数据]
