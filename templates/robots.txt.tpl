# robots.txt for https://__DOMAIN__/
# Scope: public content only. Keep admin/runtime paths out of crawl.

User-agent: *
Allow: /
Disallow: /admin/
Disallow: /api/
Disallow: /cache/
Disallow: /config/
Disallow: /includes/
Disallow: /logs/
Disallow: /runtime/
Disallow: /vendor/
Disallow: /*.php$

# Site search results — must not be indexed (清风算法)
Disallow: /search
Disallow: /search?
Disallow: /pages/search.php
Disallow: /*?q=

# Canonical crawl resources（按你的实际站点调整）
Allow: /products
Allow: /companies
Allow: /news
Allow: /topics/
Allow: /knowledge-maps
Allow: /data-center
Allow: /site-map
Allow: /llms.txt
Allow: /llms-full.txt

Sitemap: https://__DOMAIN__/sitemap-index.xml
Sitemap: https://__DOMAIN__/sitemap.xml
Host: __DOMAIN__
