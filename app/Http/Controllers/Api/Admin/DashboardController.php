<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $days = (int) $request->get('days', 30);

        $since = Carbon::now()->subDays($days);

        // Total revenue + orders for the period
        $period = Order::selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue')
            ->where('created_at', '>=', $since)
            ->first();

        // Overall totals (all time)
        $overall = Order::selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue')
            ->first();

        // Status breakdown
        $byStatus = Order::selectRaw('status, COUNT(*) as count')
            ->where('created_at', '>=', $since)
            ->groupBy('status')
            ->pluck('count', 'status');

        // Recent orders
        $recent = Order::with('items.product', 'wilaya')
            ->latest()
            ->limit(10)
            ->get();

        // Orders per wilaya (top 5) for the period
        $topWilayas = Order::with('wilaya')
            ->selectRaw('wilaya_id, COUNT(*) as count')
            ->whereNotNull('wilaya_id')
            ->where('created_at', '>=', $since)
            ->groupBy('wilaya_id')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'wilaya' => $row->wilaya?->name_en ?? 'Unknown',
                'count' => $row->count,
            ]);

        return response()->json([
            'period' => [
                'days' => $days,
                'orders_count' => (int) $period->orders_count,
                'revenue' => round((float) $period->revenue, 2),
            ],
            'overall' => [
                'orders_count' => (int) $overall->orders_count,
                'revenue' => round((float) $overall->revenue, 2),
            ],
            'by_status' => $byStatus,
            'recent_orders' => $recent,
            'top_wilayas' => $topWilayas,
        ]);
    }
}