<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    public function checkout(CheckoutOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->checkout($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Checkout berhasil dibuat.',
            'data' => new OrderResource($order),
        ], $order->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Detail pesanan berhasil diambil.',
            'data' => new OrderResource($order->load(['items', 'statusLogs'])),
        ]);
    }

    public function cancel(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil dibatalkan.',
            'data' => new OrderResource($this->orderService->cancel($order)),
        ]);
    }

    public function markPaid(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil ditandai telah dibayar.',
            'data' => new OrderResource($this->orderService->markPaid($order)),
        ]);
    }
}
