<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $query = Order::with('items.product', 'wilaya')
            ->where('id', $id);

        // If a phone is supplied, require it to match (protects customer data).
        if ($request->filled('phone')) {
            $query->where('customer_phone', $request->phone);
        }

        $order = $query->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json([
            'id' => $order->id,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'status' => $order->status,
            'delivery_type' => $order->delivery_type,
            'commune' => $order->commune,
            'address' => $order->address,
            'shipping_fee' => $order->shipping_fee,
            'total_amount' => $order->total_amount,
            'created_at' => $order->created_at,
            'wilaya' => $order->wilaya ? [
                'code' => $order->wilaya->code,
                'name_en' => $order->wilaya->name_en,
                'name_ar' => $order->wilaya->name_ar,
            ] : null,
            'items' => $order->items->map(function ($item) {
                return [
                    'product' => $item->product ? [
                        'title_en' => $item->product->title_en,
                        'title_ar' => $item->product->title_ar,
                        'price' => $item->price,
                    ] : null,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                ];
            }),
        ]);
    }
}