\pset pager off
\echo 'Catalog evidence: record CPU, RAM, storage, container limits, buffer conditions and git commit alongside this file.'
SELECT now() AS measured_at, current_user, version();
SELECT count(*) AS products, count(*) FILTER (WHERE status = 'active') AS active_products FROM products;
SELECT count(*) AS categories FROM categories;
SELECT count(*) AS images FROM product_images;
SHOW shared_buffers;
SHOW work_mem;
SHOW random_page_cost;
ANALYZE categories, products, product_images;
SELECT id AS category_id FROM categories WHERE slug = 'bulk-category-199' \gset
SELECT id AS cursor_id, price_minor AS cursor_price FROM products WHERE status = 'active' ORDER BY price_minor, id OFFSET 40000 LIMIT 1 \gset
\echo 'category + newest'
EXPLAIN (ANALYZE, BUFFERS) SELECT id, category_id, sku, name, slug, description, price_minor, currency FROM products WHERE status = 'active' AND category_id = :'category_id' ORDER BY id DESC LIMIT 20;
\echo 'price ascending'
EXPLAIN (ANALYZE, BUFFERS) SELECT id, category_id, sku, name, slug, description, price_minor, currency FROM products WHERE status = 'active' ORDER BY price_minor, id LIMIT 20;
\echo 'price descending'
EXPLAIN (ANALYZE, BUFFERS) SELECT id, category_id, sku, name, slug, description, price_minor, currency FROM products WHERE status = 'active' ORDER BY price_minor DESC, id DESC LIMIT 20;
\echo 'category + price'
EXPLAIN (ANALYZE, BUFFERS) SELECT id, category_id, sku, name, slug, description, price_minor, currency FROM products WHERE status = 'active' AND category_id = :'category_id' ORDER BY price_minor, id LIMIT 20;
\echo 'Persian trigram'
EXPLAIN (ANALYZE, BUFFERS) SELECT id, category_id, sku, name, slug, description, price_minor, currency FROM products WHERE status = 'active' AND lower(name) LIKE '%زعفران collection 12%' ESCAPE '\' ORDER BY id DESC LIMIT 20;
\echo 'primary image lookup'
EXPLAIN (ANALYZE, BUFFERS) SELECT id, product_id, path, width, height, position, alt FROM product_images WHERE product_id = :'cursor_id' ORDER BY position, id LIMIT 1;
\echo 'composite keyset after 40000 rows; cursor setup not part of query timing'
EXPLAIN (ANALYZE, BUFFERS) SELECT id, category_id, sku, name, slug, description, price_minor, currency FROM products WHERE status = 'active' AND (price_minor, id) > (:'cursor_price'::bigint, :'cursor_id'::uuid) ORDER BY price_minor, id LIMIT 20;
\echo 'naive newest computed unindexed sort'
EXPLAIN (ANALYZE, BUFFERS) SELECT *, CAST(id AS TEXT) AS naive_sort_key FROM products WHERE (status || '') = 'active' ORDER BY naive_sort_key DESC LIMIT 20 OFFSET 40000;
\echo 'naive price OFFSET'
EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM products WHERE (status || '') = 'active' ORDER BY (price_minor + 0), CAST(id AS TEXT) LIMIT 20 OFFSET 40000;
\echo 'naive raw ILIKE'
EXPLAIN (ANALYZE, BUFFERS) SELECT *, CAST(id AS TEXT) AS naive_sort_key FROM products WHERE (status || '') = 'active' AND name ILIKE '%زعفران collection 12%' ORDER BY naive_sort_key DESC LIMIT 20;
\echo 'actual index sizes; do not interpret as write throughput'
SELECT indexrelname, pg_size_pretty(pg_relation_size(indexrelid)) AS index_size, idx_scan FROM pg_stat_user_indexes WHERE relname IN ('products', 'product_images') ORDER BY indexrelname;
