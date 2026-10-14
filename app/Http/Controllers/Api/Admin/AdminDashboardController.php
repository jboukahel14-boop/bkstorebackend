<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Revenue that positively contributes to KPIs (canceled orders excluded).
     *
     * @return string[]
     */
    private function activeStatuses(): array
    {
        return ['pending', 'confirmed', 'shipped', 'delivered'];
    }

    /**
     * Daily metrics overview: revenue today, pending orders, low stock count.
     */
    public function metrics(Request $request): JsonResponse
    {
        $today = Carbon::today();

        $todayStats = Order::selectRaw('COUNT(*) as orders_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->where('created_at', '>=', $today)
            ->whereIn('status', $this->activeStatuses())
            ->first();

        return response()->json([
            'daily_revenue' => round((float) $todayStats->revenue, 2),
            'orders_today' => (int) $todayStats->orders_count,
            'pending_orders' => (int) Order::where('status', 'pending')->count(),
            'total_orders' => (int) Order::count(),
            'low_stock_count' => (int) Product::where('stock', '<', 5)->count(),
        ]);
    }

    /**
     * Revenue (+ orders count) broken down per day for the last N days.
     */
    public function revenueSeries(Request $request): JsonResponse
    {
        $days = min(max((int) $request->get('days', 7), 1), 90);
        $start = Carbon::today()->subDays($days - 1);

        $rows = Order::selectRaw('DATE(created_at) as date, COUNT(*) as orders_count, COALESCE(SUM(total_amount), 0) as revenue')
            ->where('created_at', '>=', $start->copy()->startOfDay())
            ->whereIn('status', $this->activeStatuses())
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $row = $rows->get($date->toDateString());

            $series[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('D'),
                'full_label' => $date->format('d M'),
                'revenue' => round((float) ($row->revenue ?? 0), 2),
                'orders' => (int) ($row->orders_count ?? 0),
            ];
        }

        return response()->json(['days' => $series]);
    }

    /**
     * Best-selling products by quantity sold for the period.
     */
    public function topProducts(Request $request): JsonResponse
    {
        $days = (int) $request->get('days', 30);
        $since = Carbon::now()->subDays($days);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('
                products.id,
                products.title_en,
                products.title_ar,
                products.slug,
                SUM(order_items.quantity) as quantity_sold,
                SUM(order_items.quantity * order_items.price) as revenue
            ')
            ->where('orders.created_at', '>=', $since)
            ->whereIn('orders.status', $this->activeStatuses())
            ->groupBy('products.id', 'products.title_en', 'products.title_ar', 'products.slug')
            ->orderByDesc('quantity_sold')
            ->limit(min(max((int) $request->get('limit', 5), 1), 50))
            ->get();

        $products = $rows->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'title_en' => $row->title_en,
                'title_ar' => $row->title_ar,
                'slug' => $row->slug,
                'quantity_sold' => (int) $row->quantity_sold,
                'revenue' => round((float) $row->revenue, 2),
            ];
        })->values();

        return response()->json(['top_products' => $products, 'days' => $days]);
    }

    /**
     * Top wilayas by number of orders for the period.
     */
    public function topWilayas(Request $request): JsonResponse
    {
        $days = (int) $request->get('days', 30);
        $since = Carbon::now()->subDays($days);

        $rows = Order::with('wilaya')
            ->selectRaw('wilaya_id, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue')
            ->whereNotNull('wilaya_id')
            ->where('created_at', '>=', $since)
            ->groupBy('wilaya_id')
            ->orderByDesc('count')
            ->limit(min(max((int) $request->get('limit', 8), 1), 58))
            ->get()
            ->map(function ($row) {
                return [
                    'code' => (string) ($row->wilaya?->code ?? ''),
                    'wilaya' => (string) ($row->wilaya?->name_en ?? 'Unknown'),
                    'name_ar' => (string) ($row->wilaya?->name_ar ?? ''),
                    'count' => (int) $row->count,
                    'revenue' => round((float) $row->revenue, 2),
                ];
            })
            ->values();

        return response()->json(['top_wilayas' => $rows, 'days' => $days]);
    }
}