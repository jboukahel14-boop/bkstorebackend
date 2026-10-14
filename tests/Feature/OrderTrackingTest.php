<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Wilaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(): Order
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
            'stock' => 3,
        ]);

        $wilaya = Wilaya::create([
            'code' => '31',
            'name_en' => 'Oran',
            'name_ar' => 'وهران',
            'delivery_fee_home' => 650.00,
            'delivery_fee_desk' => 350.00,
        ]);

        $order = Order::create([
            'customer_name' => 'Sara M',
            'customer_phone' => '0770 123 456',
            'wilaya_id' => $wilaya->id,
            'commune' => 'Oran Centre',
            'delivery_type' => 'home',
            'shipping_fee' => 650.00,
            'total_amount' => 2750.00,
            'status' => 'shipped',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 2100.00,
        ]);

        return $order;
    }

    public function test_returns_order_by_id_with_status_timestamps(): void
    {
        $order = $this->createOrder();

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $order->id)
            ->assertJsonPath('status', 'shipped')
            ->assertJsonPath('delivery_type', 'home')
            ->assertJsonStructure([
                'id',
                'status',
                'delivery_type',
                'shipping_fee',
                'total_amount',
                'wilaya',
                'items',
            ]);
    }

    public function test_returns_404_for_unknown_order(): void
    {
        $this->getJson('/api/v1/orders/999999')
            ->assertStatus(404);
    }
}