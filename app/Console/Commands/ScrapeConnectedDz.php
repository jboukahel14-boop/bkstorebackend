<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class ScrapeConnectedDz extends Command
{
    protected $signature = 'scrape:connected-dz
                            {--limit=0 : Maximum products to import (0 = all pages)}
                            {--category= : Only import products of this source category id}
                            {--dry-run : Crawl the remote store but do not write to the database}';

    protected $description = 'Import categories & products from connecteddz.com (FlexDZ store API)';

    private const BASE_URL = 'https://api.flexdz.com/client';
    private const APP_KEY = 'G8//WmIP3dGe8TUuWz4r3sxlqFFsbYWodI8Ssau0tqk=';
    private const STORE_ID = '671d5cd3017972debdb97891';
    private const PAGE_SIZE = 32; // FlexDZ serves 32 items per page
    private const DEFAULT_STOCK = 50;
    private const MAX_PAGES = 20;

    /** @var array<string, int> map of FlexDZ category _id => local category id */
    private array $categoryMap = [];

    private int $categories = 0;
    private int $imported = 0;
    private int $updated = 0;
    private int $skipped = 0;
    private int $errors = 0;

    public function handle(): int
    {
        set_time_limit(0);

        $this->line('<info>Scraping connecteddz.com (FlexDZ store API)…</info>');

        try {
            $sourceCategories = $this->fetchCategories();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the ConnectedDZ API: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach (array_values($sourceCategories) as $sourceCategory) {
            $category = $this->saveCategory($sourceCategory);
            if ($category !== null) {
                $this->categoryMap[$sourceCategory['_id']] = $category->id;
                $this->categories++;
            }
        }
        $this->line('  categories fetched: '.$this->categories);

        if ($this->option('dry-run')) {
            $this->line('  <comment>dry-run: crawling products without touching the database</comment>');
        }

        $cursor = null;
        $pages = 0;

        do {
            try {
                $items = $cursor ? $this->fetchProductsPage($cursor) : $this->fetchProductsPage();
            } catch (\Throwable $e) {
                $this->warn('  product list request failed: '.$e->getMessage());
                break;
            }

            if (count($items) === 0) {
                break;
            }

foreach ($items as $item) {
                if ($this->limitReached()) {
                    break 2;
                }
                $this->saveProduct($item);
            }

            $cursor = $items[array_key_last($items)]['creationDate'] ?? null;
            $pages++;
        } while (count($items) > 0 && $cursor && $pages < self::MAX_PAGES);

        if ($pages >= self::MAX_PAGES) {
            $this->warn('Reached page cap ('.self::MAX_PAGES.'). Stopping.');
        }

        $this->line(
            sprintf(
                '  done: categories=%d imported=%d updated=%d skipped=%d errors=%d',
                $this->categories, $this->imported, $this->updated, $this->skipped, $this->errors
            )
        );

        // Machine-readable line so AdminProductController::scrape() can return a summary.
        $this->line(
            sprintf(
                '[summary] categories=%d imported=%d updated=%d skipped=%d errors=%d',
                $this->categories, $this->imported, $this->updated, $this->skipped, $this->errors
            )
        );

        return self::SUCCESS;
    }

    private function limitReached(): bool
    {
        $limit = (int) $this->option('limit');

        return $limit > 0 && ($this->imported + $this->updated) >= $limit;
    }

    // ── Remote API ─────────────────────────────────────────────────────────

    private function headers(): array
    {
        return [
            'X-Requested-With' => 'XMLHttpRequest',
            'App-Key' => self::APP_KEY,
            'Store-Id' => self::STORE_ID,
            'Client-Session-Id' => (string) Str::uuid(),
            'Browser-Id' => (string) Str::uuid(),
            'Referer' => 'https://connecteddz.com/',
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        ];
    }

    private function fetchCategories(): array
    {
        $res = Http::withHeaders($this->headers())
            ->timeout(60)
            ->connectTimeout(30)
            ->get(self::BASE_URL.'/fetch/categories/', ['filters' => '']);

        $res->throw();

        $data = $res->json('response');

        return is_array($data) ? array_values($data) : [];
    }

    private function fetchProductsPage(?string $cursor = null): array
    {
        $params = ['filters' => ''];
        if ($cursor !== null) {
            $params['creationDate'] = $cursor;
        }

        $res = Http::withHeaders($this->headers())
            ->timeout(60)
            ->connectTimeout(30)
            ->get(self::BASE_URL.'/fetch/products/', $params);

        $res->throw();

        $data = $res->json('response');

        return is_array($data) ? array_values($data) : [];
    }

    private function fetchProductDetails(string $id): array
    {
        $res = Http::withHeaders($this->headers())
            ->timeout(120)
            ->connectTimeout(60)
            ->retry(2, 4000, throw: false)
            ->get(self::BASE_URL.'/fetch/products/details', ['productId' => $id]);

        $res->throw();

        $data = $res->json('response');

        return is_array($data) ? $data : [];
    }

    // ── Persistence ───────────────────────────────────────────────────────

    private function saveCategory(array $source): ?Category
    {
        $id = $source['_id'] ?? null;
        $nameAr = trim($source['name'] ?? '');
        $imageUrl = $source['photo']['src'] ?? null;

        if ($id === null || $nameAr === '') {
            $this->skipped++;

            return null;
        }

        $nameEn = $this->translate($nameAr);
        $slug = $this->makeSlug($nameEn, $nameAr, 'cat', $id);

        if (! $this->option('dry-run') && $imageUrl) {
            // Download the real category photo to public storage. Falls back to
            // the remote CDN URL if the download fails, so icons never break.
            $downloaded = $this->downloadImage($imageUrl, $slug, 'categories');
            if ($downloaded !== null) {
                $imageUrl = $downloaded;
            }
        }

        return Category::updateOrCreate(
            ['slug' => $slug],
            ['name_en' => $nameEn, 'name_ar' => $nameAr, 'image_url' => $imageUrl]
        );
    }

    private function saveProduct(array $item): void
    {
        $id = $item['_id'] ?? null;
        if ($id === null) {
            $this->skipped++;

            return;
        }

        try {
            $details = $this->fetchProductDetails($id);
        } catch (\Throwable $e) {
            $this->errors++;
            $this->warn('  details failed for '.$id.': '.$e->getMessage());

            return;
        }

        $sourceCategoryId = $details['categoryId'] ?? null;

        if ($this->option('category') && $sourceCategoryId !== (string) $this->option('category')) {
            $this->skipped++;

            return;
        }

        if (($details['type'] ?? 'physical') === 'digital') {
            $this->skipped++;

            return;
        }

        $nameAr = trim($details['name'] ?? $item['name'] ?? '');
        $price = (float) ($details['price'] ?? $item['price'] ?? 0);
        $description = $this->cleanDescription($details['description'] ?? '');
        $photos = $details['photos'] ?? [];

        if ($nameAr === '') {
            $this->skipped++;

            return;
        }

        $nameEn = $this->translate($nameAr);
        $slug = $this->makeSlug($nameEn, $nameAr, 'p', $id);

        $categoryId = $sourceCategoryId !== null && isset($this->categoryMap[$sourceCategoryId])
            ? $this->categoryMap[$sourceCategoryId]
            : $this->fallbackCategoryId();

        if ($categoryId === null) {
            $this->skipped++;

            return;
        }

        $imageUrl = null;
        $remoteImage = $photos[0]['src'] ?? null;

        if ($this->option('dry-run')) {
            $this->line('  [dry-run] '.$nameEn.' — '.number_format($price, 0).' DA');

            return;
        }

        if ($remoteImage) {
            // Download the real product photo to public storage. Falls back to
            // the remote CDN URL if the download fails, so images never break.
            $imageUrl = $this->downloadImage($remoteImage, $slug) ?? $remoteImage;
        }

        $product = Product::updateOrCreate(
            ['slug' => $slug],
            [
                'category_id' => $categoryId,
                'title_en' => $nameEn,
                'title_ar' => $nameAr,
                'price' => round((float) $price, 2),
                'description' => $description,
                'image_url' => $imageUrl,
            ]
        );

        if ($product->wasRecentlyCreated) {
            $product->stock = self::DEFAULT_STOCK;
            $product->save();
            $this->imported++;
        } else {
            $this->updated++;
        }
    }

    private function fallbackCategoryId(): ?int
    {
        $category = Category::where('slug', 'uncategorized')->first()
            ?? Category::where('slug', 'like', 'cat-%')->first();

        if ($category !== null) {
            return $category->id;
        }

        return Category::orderBy('id')->value('id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function makeSlug(string $nameEn, string $nameAr, string $prefix, string $sourceId): string
    {
        if (preg_match('/[\p{L}\p{N}]/u', $nameEn) && strtolower($nameEn) !== strtolower($nameAr)) {
            $slug = Str::slug($nameEn);
        }

        if (empty($slug)) {
            $slug = $prefix.'-'.substr(md5($sourceId), 0, 8);
        }

        return $slug;
    }

    /**
     * Download a raw image and save it under the given public-storage sub-folder
     * (products/ or categories/). Returns the RELATIVE path ("products/x.webp")
     * so the API Resources can append the configured APP_URL at serialization
     * time. Returns null on failure.
     */
    private function downloadImage(string $url, string $slug, string $dir = 'products'): ?string
    {
        try {
            $res = Http::withHeaders($this->headers())
                ->timeout(60)
                ->withoutVerifying()
                ->get($url);

            if (! $res->successful()) {
                return null;
            }

            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $ext = 'webp';
            }

            $filename = $slug.'.'.$ext;
            Storage::disk('public')->put($dir.'/'.$filename, $res->body());

            // Relative path — the API resource prefixes config('app.url').
            return $dir.'/'.$filename;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Strip markup from the FlexDZ HTML description and return readable lines.
     */
    private function cleanDescription(string $html): string
    {
        $html = trim($html);

        if ($html === '' || $html[0] !== '<') {
            return $html;
        }

        try {
            $crawler = new Crawler($html);
        } catch (\Throwable $e) {
            return trim(strip_tags($html));
        }

        $lines = [];
        $crawler->filter('p, li, h1, h2, h3, h4, h5, h6')->each(function (Crawler $node) use (&$lines) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->text()));
            if ($text !== '' && ! in_array($text, $lines, true)) {
                $lines[] = $text;
            }
        });

        if (count($lines) === 0) {
            $text = trim(preg_replace('/\s+/u', ' ', $crawler->text()));
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Best-effort Arabic → English title helper. Multi-word phrases are matched
     * first; words without a translation are kept as-is (Arabic).
     */
    private function translate(string $text): string
    {
        static $pairs;
        $pairs ??= collect($this->translations())->sortByDesc(fn ($word) => mb_strlen($word))->all();

        $result = $text;

        foreach ($pairs as $word => $replacement) {
            $result = preg_replace('/'.preg_quote($word, '/').'/u', $replacement, $result);
        }

        $result = trim(preg_replace('/\s+/u', ' ', $result));

        return $result !== '' ? $result : $text;
    }

    /**
     * Small electronics dictionary. Extended over time as new categories appear.
     *
     * @return array<string, string>
     */
    private function translations(): array
    {
        return [
            'سماعة بلوتوث' => 'Bluetooth Earbuds',
            'باور بنك' => 'Power Bank',
            'مكبر صوت' => 'Speaker',
            'لوحة مفاتيح' => 'Keyboard',
            'شاشة' => 'Screen',
            'مغناطيسي' => 'Magnetic',
            'ألمنيوم' => 'Aluminium',
            'سيليكون' => 'Silicone',
            'إكسسوارات' => 'Accessories',
            'إكسسوار' => 'Accessory',
            'اكسسوارات' => 'Accessories',
            'اكسسوار' => 'Accessory',
            'سماعات' => 'Earbuds',
            'سماعة' => 'Earbud',
            'حقيبة' => 'Case',
            'كفر' => 'Cover',
            'غطاء' => 'Cover',
            'شاحن' => 'Charger',
            'هواتف' => 'Phones',
            'هاتف' => 'Phone',
            'جوال' => 'Mobile',
            'موبايل' => 'Mobile',
            'ساعة' => 'Watch',
            'ساعات' => 'Watches',
            'لابتوب' => 'Laptop',
            'حاسوب' => 'Computer',
            'تابلت' => 'Tablet',
            'ماوس' => 'Mouse',
            'كيبورد' => 'Keyboard',
            'مايك' => 'Microphone',
            'صوت' => 'Audio',
            'بلوتوث' => 'Bluetooth',
            'واي فاي' => 'WiFi',
            'ذكية' => 'Smart',
            'ذكي' => 'Smart',
            'كاميرا' => 'Camera',
            'سلك' => 'Cable',
            'قلم' => 'Pen',
            'أقلام' => 'Pens',
            'حامل' => 'Holder',
            'مسند' => 'Stand',
            'عصا' => 'Stand',
            'إضاءة' => 'Light',
            'مصباح' => 'Lamp',
            'مروحة' => 'Fan',
            'مكواة' => 'Iron',
            'مكنسة' => 'Vacuum',
            'روبوت' => 'Robot',
            'مساج' => 'Massage',
            'رياضة' => 'Sports',
            'لياقة' => 'Fitness',
            'ألعاب' => 'Gaming',
            'لعبة' => 'Game',
            'تحكم' => 'Controller',
            'مقاوم للماء' => 'Waterproof',
            'إلغاء الضوضاء' => 'ANC',
            'باور' => 'Power',
            'قهوة' => 'Coffee',
            'مطبخ' => 'Kitchen',
            'طبخ' => 'Cooking',
        ];
    }
}