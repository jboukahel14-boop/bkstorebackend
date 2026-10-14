<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wilaya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WilayaController extends Controller
{
    public function index(): JsonResponse
    {
        $wilayas = Wilaya::orderBy('code', 'asc')->get();

        return response()->json($wilayas);
    }

    public function communes(Request $request, string $code): JsonResponse
    {
        // Codes are stored as 2-digit strings ("01"…"58"); normalise so "1" and
        // "01" both resolve to the same wilaya.
        $normalisedCode = str_pad($code, 2, '0', STR_PAD_LEFT);

        $wilaya = Wilaya::where('code', $normalisedCode)->firstOrFail();

        $communes = $wilaya->communes()
            ->when($request->filled('q'), function ($q) use ($request) {
                $q->where('name_en', 'like', "%{$request->q}%");
            })
            ->orderBy('name_en', 'asc')
            ->pluck('name_en');

        return response()->json($communes);
    }
}