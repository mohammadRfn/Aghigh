# Aghigh — تحویل ماژول Catalog / Task 04

این فایل راهنمای نصب بسته است؛ README اصلی مخزن را جایگزین نکنید.

## وضعیت تحویل — مهم

کد برای مخزن https://github.com/mohammadRfn/Aghigh و commit زیر آماده شده است:

`b634d7706fe2e3eb3ae23d347444d6a26218f269`

شاخهٔ تحویل محلی: `feat/catalog`. هیچ تغییری به GitHub شما push نشده است. Task 3 و قرارداد CurrentUser در این نسخه وجود دارند، اما صحت اجرای Task 3 و کل CI باید در محیط خودتان تأیید شوند.

این محیط PHP/Docker ندارد. بنابراین `make lint stan test test-int` و benchmarkهای PostgreSQL 18 اینجا اجرا نشده‌اند. فایل‌های تست همراه بسته‌اند؛ تحویل را «CI سبز» یا «آمادهٔ production» معرفی نمی‌کنیم. گزارش کامل در `docs/catalog-delivery/VALIDATION.md` است. صفحهٔ API explorer فقط پیش‌نمایش مستقل Next.js/PostgreSQL است و اجرای Laravel یا سنجش سرعت آن نیست.

## داخل ZIP چه هست؟

- `README.md`: همین راهنمای نصب، فقط برای مراجعه.
- `catalog.patch`: patch قابل بررسی نسبت به commit بالا؛ شامل فایل‌های جدید و اصلاحات integration.
- `files/`: نسخهٔ کامل فایل‌های تغییرکرده با مسیر مقصد نسبی نسبت به ریشهٔ پروژه.
- `manifest.json`: فهرست مسیرها، اندازه‌ها و وضعیت added/modified. checksum نهایی ZIP در صفحهٔ دانلود است.

### مسیر قرار دادن فایل‌ها

| مسیر داخل files/ | مقصد در ریشهٔ Aghigh | روش |
|---|---|---|
| Modules/Catalog/app/** | Modules/Catalog/app/** | کلاس‌های ماژول؛ فایل‌های هم‌نام provider را ادغام/جایگزین کنید |
| Modules/Catalog/config/config.php | همان مسیر | تنظیمات Catalog |
| Modules/Catalog/routes/api.php | همان مسیر | endpointها زیر /api/v1؛ admin زیر /api/v1/admin |
| Modules/Catalog/database/migrations/** | همان مسیر | شِما، pg_trgm، سپس ایندکس‌های concurrent |
| Modules/Catalog/database/factories/** | همان مسیر | فقط تست؛ seeder بزرگ از factory استفاده نمی‌کند |
| Modules/Catalog/tests/** | همان مسیر | Unit، Feature و Concurrency |
| config/cache.php | config/cache.php | store نام‌دار cache با lock_connection=state اضافه شده؛ تنظیمات خودتان را حفظ کنید |
| tests/Arch/ModulesTest.php | همان مسیر | گسترش مرز لایه‌های خصوصی |
| tests/Arch/CatalogBoundaryTest.php | همان مسیر | مرز public surface ماژول |
| tests/Support/CatalogTesting.php | همان مسیر | adapter تست Identity؛ مدل Identity داخل Catalog import نشده |
| .env.example و .env.docker.example | همان مسیرها | defaults مستند؛ فایل .env.docker واقعی خودتان را دستی به‌روز کنید |
| Makefile | Makefile | targetهای seed-catalog، psql و explain-catalog و انتخاب صریح suiteهای SQLite |
| .github/workflows/ci.yml | همان مسیر | انتخاب صریح Unit/Feature/Arch؛ اجرای CI هنوز لازم است |
| docs/** | docs/** | ADR 0004، قرارداد API، performance، security و گزارش تحویل |

`files/README.md` نسخهٔ راهنمای بسته است و نباید روی README اصلی پروژه کپی شود. patch آن را در مسیر `docs/catalog-delivery/README.md` قرار می‌دهد.

## نصب پیشنهادی با patch

۱. از تغییرات و دیتابیس محلی خود نسخهٔ پشتیبان بگیرید. روی نسخه‌ای باشید که Task 3 در آن merge شده است. Working tree را تمیز کنید.

۲. در ریشهٔ پروژه:

```sh
git switch -c feat/catalog
git rev-parse HEAD
```

۳. ZIP را در یک پوشهٔ جدا باز کنید و catalog.patch را به ریشهٔ پروژه بیاورید. ابتدا فقط بررسی کنید:

```sh
git apply --check catalog.patch
git apply catalog.patch
git diff --stat
git status --short
```

اگر check شکست خورد، نسخهٔ شما با commit مبنا فرق دارد؛ patch را با force اعمال نکنید. از جدول مسیرها و files/ برای ادغام دستی استفاده کنید. فایل‌های ریشه مانند Makefile و config/cache.php را کورکورانه overwrite نکنید. هیچ secret، vendor، node_modules، dump دیتابیس یا کل مخزن داخل ZIP نیست.

۴. تمام فرمان‌های PHP و Composer را **داخل Docker** اجرا کنید:

```sh
make up
docker compose --env-file .env.docker exec app composer dump-autoload
docker compose --env-file .env.docker exec app php artisan optimize:clear
```

## Environment — خطوطی که باید به .env.docker خودتان اضافه کنید

این defaults هم در .env.example و هم در .env.docker.example آمده‌اند. اعداد configuration هستند، نه measurement.

```dotenv
CATALOG_CURRENCY=IRR
CATALOG_IMAGE_BASE_URL=https://cdn.example.com
CATALOG_PER_PAGE=20
CATALOG_PER_PAGE_MAX=50
CATALOG_CACHE_FRESH_SECONDS=60
CATALOG_CACHE_STALE_SECONDS=180
CATALOG_CACHE_LOCK_SECONDS=15
CATALOG_CACHE_LOCK_WAIT_SECONDS=5
CATALOG_PRICING_MAX_IDS=100
CATALOG_IMAGES_MAX=10
CATALOG_CATEGORY_MAX_DEPTH=5
CATALOG_PRICE_MAX_MINOR=9007199254740991
```

- image base URL مثال است؛ پیش از استفاده آن را با origin واقعی خودتان جایگزین کنید. آپلود تصویر در این Task نیست.
- ارز پیش‌فرض IRR است؛ مبلغ‌ها integer minor unit همان ارزند. تغییر env به‌تنهایی تبدیل ارز نیست؛ داده‌ها باید برنامه‌ریزی‌شده migrate شوند.
- stale=180 عمر کل fresh+stale است، نه 180 ثانیه اضافه به fresh.
- lock lease/wait باید پس از اندازه‌گیری تنظیم شوند؛ اگر محاسبه از lease طولانی‌تر شود، جلوگیری از stampede تضمین نمی‌شود.
- cap قیمت‌گذاری 100 باید حداقل سقف واقعی آیتم‌های سفارش Task 5 باشد. کلید توافق‌شدهٔ integration در این بسته `ordering.max_items` است؛ Task 5 باید آن را تأیید/همگام کند.
- APP_KEY موجود برای HMAC cursor استفاده می‌شود؛ secret جدید لازم نیست. با rotate شدن APP_KEY، cursorهای قبلی نامعتبر می‌شوند.

## Migration، seed و تست

برای دیتابیس موجود از `make migrate` استفاده کنید. برای **reset مخرب دیتابیس توسعهٔ قابل حذف**:

```sh
make fresh-seed
make seed-catalog N=100000
make psql
```

fresh-seed همهٔ جدول‌های دیتابیس توسعه را حذف می‌کند؛ روی production اجرا نکنید. seed-catalog با credential migrator و DB_HOST=postgres اجرا می‌شود، نه app role از PgBouncer. بدون --truncate روی جدول غیرخالی متوقف می‌شود؛ make seed-catalog عمداً truncate خودکار ندارد. برای تکرار seed، reset توسعه را صریح انجام دهید یا فرمان داخل کانتینر را با env migrator و --truncate اجرا کنید؛ نمونه در docs/performance-notes.md است.

در psql:

```sql
ANALYZE categories, products, product_images;
```

متن تسک در یک جا چهار جدول می‌گوید، اما شِمای آن فقط سه جدول دارد. این بسته همان سه جدول را می‌سازد و ANALYZE می‌کند؛ جدول چهارم اختراع نشده است.

```sh
make lint stan test test-int
```

این فرمان باید در محیط شما سبز شود. اگر Pint اختلاف style گزارش داد، فقط در کانتینر `./vendor/bin/pint` را اجرا و دوباره همهٔ چهار target را بررسی کنید. خطاهای Larastan یا تست را با baseline یا skip پنهان نکنید. QueryPlanTest نیاز به PostgreSQL 18 و >=50k ردیف دارد؛ اثر planner را با خروجی واقعی بررسی کنید. فرمان‌های evidence:

```sh
make explain-catalog LABEL=before
make explain-catalog LABEL=after
```

برای ترتیب صحیح rollback فقط ایندکس‌ها، اندازه‌گیری و بازسازی، **ابتدا docs/performance-notes.md را بخوانید**؛ دو فرمان بالا بدون تغییر ایندکس‌ها مقایسهٔ قبل/بعد تولید نمی‌کنند.

## راه‌اندازی مجدد سرویس‌ها

بعد از تغییر `.env.docker`، `make up` را اجرا کنید تا compose محیط containerها را دوباره اعمال کند. بعد از تغییر کد/config:

```sh
docker compose --env-file .env.docker exec app php artisan optimize:clear
docker compose --env-file .env.docker exec app php artisan octane:reload
```

Catalog در این Task job جدیدی ندارد. اگر همراه این تغییر deployment مشترک انجام داده‌اید، طبق AGENTS.md کدهای queue را با `docker compose --env-file .env.docker exec horizon php artisan horizon:terminate` reload کنید. برای این ماژول به‌تنهایی تغییر Reverb یا restart Postgres/Redis لازم نیست. state Redis را flush نکنید.

## بررسی دستی endpointها

```sh
curl -i 'http://localhost:8000/api/v1/products?per_page=5'
curl -i 'http://localhost:8000/api/v1/_bench/naive/products?per_page=5'
curl -i 'http://localhost:8000/api/v1/categories'
```

`/docs/api` را باز کنید؛ گروه‌های Catalog، Catalog admin و _bench باید مستند باشند. برای ادمین از access token معتبر Task 3 با role=admin استفاده کنید. anonymous=401، customer=403 و admin مجاز است. توکن را در README، log یا فایل commitشده قرار ندهید. در staging/production مسیر _bench وجود ندارد؛ route cache باید در همان محیط مقصد ساخته شود.

## تصمیم‌های باز و محدودهٔ دقیق

- invalidation سراسری انتخاب شده است: هر admin write هر دو version را بالا می‌برد، پس کل catalog سرد می‌شود. trade-off دسته‌ای و write cost در ADR 0004 آمده است.
- cache payload فقط روی store `cache`؛ version/lock/counter فقط روی `state`. q سرور-cache را دور می‌زند، ولی ETag/HTTP headers یکسان‌اند.
- dirty counter در خرابی invalidation خواندن را مستقیم به DB می‌برد. اگر write با `catalog-invalidation-pending` برگشت، SQL ممکن است commit شده باشد؛ پیش از retry موجودیت را بررسی کنید. پس از توقف همهٔ admin/bulk writeها و پایان تراکنش‌های درحال‌اجرا، `php artisan catalog:cache-reconcile --confirm-writes-paused` را داخل app اجرا کنید.
- public HTTP cache می‌تواند پاسخ قبلی را 30+60 ثانیه نگه دارد؛ server stale window جداست. invalidate ادمین به معنی purge فوری CDN نیست.
- محصول archived حذف سخت ندارد؛ برای انتشار مجدد اول draft شود.
- ordering baseline از ستون محاسبه‌شدهٔ UUID متنیِ بدون ایندکس استفاده می‌کند تا ترتیب آیتم‌ها دقیقاً مثل optimized بماند؛ توضیح «بدون ایندکس» و هزینهٔ OFFSET در performance notes است.
- pg_trgm و composite index فعلاً candidateهای پیاده‌سازی‌اند؛ استفادهٔ واقعی planner و هزینهٔ write/size باید اندازه‌گیری شود. تمام نتیجه‌های اندازه‌گیری‌نشده TODO(measure) هستند.
- Rate limiting عمداً اضافه نشده؛ Task 7 گروه‌های public/admin را می‌گیرد.

## Definition of done

کد و تست و docs در بسته قرار دارند؛ تکمیل واقعی Task 4 مشروط به اجرای موفق `make lint stan test test-int`، CI، seed تمیز 100k و بررسی هر دو endpoint در استک اصلی است. این شروط در محیط تحویل قابل اجرا نبودند و ادعای موفقیتشان نشده است.
