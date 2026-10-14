<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Wilaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeCatalog(): array
    {
        $category = Category::create([
            'name_en' => 'Cases',
            'name_ar' => 'كفرات',
            'slug' => 'cases',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'title_en' => '3D Modern Case',
            'title_ar' => 'كفر 3D مودرن',
            'slug' => '3d-modern-case',
            'price' => 2100.00,
            'sale_price' => 1800.00,
            'stock' => 10,
            'is_featured' => true,
        ]);

        $wilaya = Wilaya::create([
            'code' => '16',
            'name_en' => 'Alger',
            'name_ar' => 'الجزائر',
            'delivery_fee_home' => 700.00,
            'delivery_fee_desk' => 400.00,
        ]);

        return compact('category', 'product', 'wilaya');
    }

    public function test_creates_order_with_server_side_pricing_and_decrements_stock(): void
    {
        extract($this->makeCatalog());

        $response = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Amine Ben',
            'customer_phone' => '+213 661 123 456',
            'wilaya_id' => $wilaya->id,
            'commune' => 'Alger Centre',
            'address' => '12 Rue Didouche',
            'delivery_type' => 'home',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.total_amount', 4300) // 2 × 1800 (sale) + 700 (home fee)
            ->assertJsonPath('order.shipping_fee', 700)
            ->assertJsonPath('order.items_total', 3600);

        $this->assertDatabaseHas('orders', [
            'customer_phone' => '+213 661 123 456',
            'status' => 'pending',
            'total_amount' => 4300.00,
        ]);

        $this->assertSame(8, (int) $product->refresh()->stock);
    }

    public function test_uses_sale_price_fallback_to_base_price(): void
    {
        extract($this->makeCatalog());
        $product->update(['sale_price' => null]);

        $response = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Lina K',
            'customer_phone' => '0661123456',
            'wilaya_id' => $wilaya->id,
            'commune' => 'Alger Centre',
            'delivery_type' => 'desk',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('order.total_amount', 2100 + 400); // base price + desk fee
    }

    public function test_rejects_overselling_and_keeps_stock_unchanged(): void
    {
        extract($this->makeCatalog());
        $product->update(['stock' => 2]);

        $response = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Amine Ben',
            'customer_phone' => '0661123456',
            'wilaya_id' => $wilaya->id,
            'commune' => 'Alger Centre',
            'delivery_type' => 'home',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(2, (int) $product->refresh()->stock);
    }

    public function test_rejects_phone_with_too_few_digits(): void
    {
        extract($this->makeCatalog());

        $response = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Amine Ben',
            'customer_phone' => '123', // needs 9-15 digits
            'wilaya_id' => $wilaya->id,
            'commune' => 'Alger Centre',
            'delivery_type' => 'desk',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('customer_phone');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_rejects_duplicate_items(): void
    {
        extract($this->makeCatalog());

        $response = $this->postJson('/api/v1/checkout', [
            'customer_name' => 'Amine Ben',
            'customer_phone' => '0661123456',
            'wilaya_id' => $wilaya->id,
            'commune' => 'Alger Centre',
            'delivery_type' => 'desk',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');
        $this->assertDatabaseCount('orders', 0);
    }
}