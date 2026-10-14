<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    /**
     * List orders with advanced filters: status, wilaya, date range and search.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with('items.product', 'wilaya');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('wilaya_id')) {
            $query->where('wilaya_id', $request->wilaya_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('customer_name', 'like', "%{$term}%")
                  ->orWhere('customer_phone', 'like', "%{$term}%")
                  ->orWhere('id', $term);
            });
        }

        $perPage = min(max((int) $request->get('per_page', 50), 1), 100);
        $orders = $query->latest()->paginate($perPage);

        return response()->json($orders);
    }

    /**
     * Update a single order status.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,shipped,delivered,canceled',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $order = Order::findOrFail($id);
        $order->update(['status' => $request->status]);

        return response()->json([
            'message' => 'Order status updated',
            'order' => $order->load('items.product', 'wilaya'),
        ]);
    }

    /**
     * Bulk update the status of many orders at once (e.g. mark 10 as shipped).
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_ids' => 'required|array|min:1|max:500',
            'order_ids.*' => 'integer|exists:orders,id',
            'status' => 'required|in:pending,confirmed,shipped,delivered,canceled',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $count = Order::whereIn('id', $request->order_ids)->update(['status' => $request->status]);

        return response()->json([
            'message' => "{$count} order(s) updated",
            'updated' => $count,
            'order_ids' => $request->order_ids,
            'status' => $request->status,
        ]);
    }

    /**
     * Export filtered orders as a CSV for Algerian delivery companies
     * (Yalidine, ZR Express, ...). French headers + semicolon separator so it
     * opens cleanly in a French-locale Excel.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = Order::with('wilaya', 'items.product');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('wilaya_id')) {
            $query->where('wilaya_id', $request->wilaya_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('customer_name', 'like', "%{$term}%")
                  ->orWhere('customer_phone', 'like', "%{$term}%")
                  ->orWhere('id', $term);
            });
        }

        $orders = $query->latest()->get();

        $filename = 'bkstore-orders-' . now()->format('Y-m-d-His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Client',
            'Téléphone',
            'Wilaya',
            'Commune',
            'Adresse',
            'Produits',
            'Quantité totale',
            'Montant colis (DA)',
            'Livraison (DA)',
            'Total à encaisser (DA)',
            'Mode',
            'Statut',
            'Date',
        ];

        return response()->stream(function () use ($orders, $columns) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM so Excel detects the encoding correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $columns, ';');

            foreach ($orders as $order) {
                $products = $order->items->map(function ($item) {
                    $label = $item->product?->title_en ?? ('#' . $item->product_id);
                    return $label . ' x' . $item->quantity;
                })->implode(' | ');

                $total = number_format((float) $order->total_amount, 2, ',', ' ');
                $shipping = number_format((float) $order->shipping_fee, 2, ',', ' ');

                fputcsv($out, [
                    $order->customer_name,
                    $order->customer_phone,
                    $order->wilaya?->name_en ?? ($order->wilaya_id ? 'Wilaya ' . $order->wilaya_id : ''),
                    $order->commune ?? '',
                    $order->address ?? '',
                    $products,
                    (string) $order->items->sum('quantity'),
                    $total,
                    $shipping,
                    $total,
                    $order->delivery_type === 'home' ? 'Domicile' : 'Point relais',
                    $order->status,
                    optional($order->created_at)->format('d/m/Y H:i'),
                ], ';');
            }

            fclose($out);
        }, 200, $headers);
    }

    /**
     * Delete an order (admin only).
     */
    public function destroy(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $order->items()->delete();
        $order->delete();

        return response()->json([
            'message' => 'Commande supprimée avec succès',
        ]);
    }
}