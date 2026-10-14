<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ShopSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['Phones', 'هواتف', 'phones', 'phones.png'],
            ['Computers', 'حواسيب', 'computers', 'computers.png'],
            ['Accessories', 'إكسسوارات', 'accessories', 'accessories.png'],
            ['Audio', 'صوتيات', 'audio', 'audio.png'],
        ];

        foreach ($categories as [$en, $ar, $slug, $img]) {
            Category::create([
                'name_en' => $en,
                'name_ar' => $ar,
                'slug' => $slug,
                'image_url' => $img,
            ]);
        }

        $products = [
            ['phones', 'iPhone 15 Pro Max 256GB', 'آيفون 15 برو ماكس 256', 'iphone-15-pro-max', 210000, null, 15, true],
            ['phones', 'Samsung Galaxy S24 Ultra', 'سامسونج جالاكسي S24 ألترا', 'samsung-s24-ultra', 188000, 178000, 12, true],
            ['computers', 'MacBook Pro 14" M3', 'ماك بوك برو 14 إنش', 'macbook-pro-14-m3', 320000, null, 8, true],
            ['computers', 'HP Pavilion Gaming 15', 'إتش بي بافيليون جيمينغ 15', 'hp-pavilion-gaming', 165000, 155000, 10, true],
            ['accessories', 'Bluetooth Earbuds Pro', 'سماعات بلوتوث برو', 'bluetooth-earbuds-pro', 8500, 6500, 100, true],
            ['audio', 'JBL Tune 510BT', 'جي بي إل تيون 510', 'jbl-tune-510bt', 12000, 9900, 30, true],
        ];

        foreach ($products as [$cat, $en, $ar, $slug, $price, $sale, $stock, $featured]) {
            $category = Category::where('slug', $cat)->first();
            Product::create([
                'category_id' => $category->id,
                'title_en' => $en,
                'title_ar' => $ar,
                'slug' => $slug,
                'price' => $price,
                'sale_price' => $sale,
                'stock' => $stock,
                'description' => 'Premium product available at BK STORE. تمتع بجودة عالية مع بي كاي ستور.',
                'image_url' => $slug . '.jpg',
                'is_featured' => $featured,
            ]);
        }
    }
}