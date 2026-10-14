<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Wilaya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CheckoutController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Normalize common alias fields from different checkout components
        if (!$request->filled('customer_phone') && $request->filled('phone')) {
            $request->merge(['customer_phone' => $request->input('phone')]);
        }
        if (!$request->filled('commune')) {
            $request->merge(['commune' => 'Centre']);
        }
        if (!$request->filled('delivery_type')) {
            $request->merge(['delivery_type' => 'home']);
        }

        // Phone: allow +, spaces, dashes and parentheses, but the string MUST
        // contain 9 to 15 actual digits (Algerian mobile numbers are 9-10).
        $phoneRule = [
            'required',
            'string',
            'regex:/^\+?[0-9+\s\-()]{8,20}$/',
            'regex:/^(?=(?:\D*\d){9,15}\D*$).+$/',
        ];

        $validator = Validator::make($request->all(), [
            'customer_name' => 'required|string|max:255',
            'customer_phone' => $phoneRule,
            'wilaya_id' => 'required|integer|exists:wilayas,id',
            'commune' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'delivery_type' => 'required|in:home,desk',
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|between:1,99',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Shipping fee is computed server-side from the DB (never trusted from the client).
        $wilaya = Wilaya::find($data['wilaya_id']);
        if (!$wilaya) {
            return response()->json(['errors' => ['wilaya_id' => ['Invalid wilaya.']]], 422);
        }

        $shippingFee = $data['delivery_type'] === 'home'
            ? (float) $wilaya->delivery_fee_home
            : (float) $wilaya->delivery_fee_desk;

        try {
            [$order, $totals] = DB::transaction(function () use ($data, $shippingFee) {
                // Fetch products with row locks so two concurrent checkouts can
                // never oversell the same stock (check + decrement is atomic).
                $ids = array_column($data['items'], 'product_id');
                $lockedProducts = Product::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

                $itemsTotal = 0.0;
                $preparedItems = [];

                foreach ($data['items'] as $item) {
                    $product = $lockedProducts->get($item['product_id']);
                    if (!$product) {
                        continue;
                    }

                    // Stock guard: refuse over-selling instead of silently truncating.
                    if ((int) $product->stock < (int) $item['quantity']) {
                        throw new \InvalidArgumentException(
                            "{$product->title_en} — only {$product->stock} left in stock."
                        );
                    }

                    // Use sale price if available, otherwise base price.
                    $unitPrice = (float) ($product->sale_price ?? $product->price);
                    $itemsTotal += $unitPrice * (int) $item['quantity'];

                    $preparedItems[] = [
                        'product' => $product,
                        'quantity' => (int) $item['quantity'],
                        'price' => $unitPrice,
                    ];
                }

                if (empty($preparedItems)) {
                    throw new \InvalidArgumentException('No valid items in the order.');
                }

                $totalAmount = round($itemsTotal + $shippingFee, 2);

                $order = Order::create([
                    'customer_name' => $data['customer_name'],
                    'customer_phone' => $data['customer_phone'],
                    'wilaya_id' => $data['wilaya_id'],
                    'commune' => $data['commune'],
                    'address' => $data['address'] ?? null,
                    'delivery_type' => $data['delivery_type'],
                    'shipping_fee' => $shippingFee,
                    'total_amount' => $totalAmount,
                    'status' => 'pending',
                ]);

                foreach ($preparedItems as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product']->id,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                    ]);

                    $item['product']->decrement('stock', $item['quantity']);
                }

                return [$order->load('items.product', 'wilaya'), compact('itemsTotal', 'totalAmount', 'shippingFee')];
            });

            return response()->json([
                'message' => 'Order created successfully',
                'order' => [
                    'id' => $order->id,
                    'total_amount' => $totals['totalAmount'],
                    'shipping_fee' => $totals['shippingFee'],
                    'items_total' => round($totals['itemsTotal'], 2),
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Insufficient stock.',
                'errors' => ['items' => [$e->getMessage()]],
            ], 422);
        } catch (\Throwable $e) {
            logger()->error('Checkout failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'An unexpected error occurred. Please try again.'], 500);
        }
    }
}