<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->when($request->integer('seller_id'), fn ($query, $sellerId) => $query->where('seller_id', $sellerId))
            ->where('status', ProductStatus::Active)
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar produk berhasil diambil.',
            'data' => ProductResource::collection($products),
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Detail produk berhasil diambil.',
            'data' => new ProductResource($product),
        ]);
    }
}
