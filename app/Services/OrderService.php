<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Product;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function checkout(array $data): Order
    {
        if ($existing = $this->findIdempotentOrder($data)) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($data) {
                $itemsByProduct = collect($data['items'])->keyBy('product_id');
                $productIds = $itemsByProduct->keys()->sort()->values();

                // A stable lock order prevents overselling and reduces deadlock risk.
                $products = Product::query()
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // A concurrent identical request may have completed while this one waited for product locks.
                if ($existing = $this->findIdempotentOrder($data)) {
                    return $existing;
                }

                $orderItems = [];
                $subtotal = BigDecimal::zero()->toScale(2);

                foreach ($data['items'] as $index => $item) {
                    $product = $products->get($item['product_id']);

                    if (! $product) {
                        throw new BusinessRuleException('Produk tidak ditemukan.', [
                            "items.$index.product_id" => ['Produk tidak ditemukan.'],
                        ]);
                    }

                    if ($product->seller_id !== (int) $data['seller_id']) {
                        throw new BusinessRuleException('Semua produk harus berasal dari seller yang dipilih.', [
                            "items.$index.product_id" => ['Produk bukan milik seller yang dipilih.'],
                        ]);
                    }

                    if ($product->status !== ProductStatus::Active) {
                        throw new BusinessRuleException('Produk tidak aktif.', [
                            "items.$index.product_id" => ['Produk sedang tidak aktif.'],
                        ]);
                    }

                    if ($product->stock < $item['quantity']) {
                        throw new BusinessRuleException('Stok produk tidak mencukupi.', [
                            "items.$index.quantity" => ["Stok tersedia hanya {$product->stock}."],
                        ]);
                    }

                    $lineSubtotal = BigDecimal::of($product->price)
                        ->multipliedBy($item['quantity'])
                        ->toScale(2);
                    $subtotal = $subtotal->plus($lineSubtotal);

                    $orderItems[] = [
                        'product_id' => $product->id,
                        'product_name_snapshot' => $product->name,
                        'product_sku_snapshot' => $product->sku,
                        'price_snapshot' => $product->price,
                        'quantity' => $item['quantity'],
                        'subtotal' => (string) $lineSubtotal,
                    ];

                    $product->decrement('stock', $item['quantity']);
                }

                $shippingCost = BigDecimal::of($data['shipping_cost'])->toScale(2);
                $total = $subtotal->plus($shippingCost);

                $order = Order::create([
                    'order_code' => $this->newOrderCode(),
                    'customer_id' => $data['customer_id'],
                    'seller_id' => $data['seller_id'],
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                    'idempotency_hash' => isset($data['idempotency_key']) ? $this->idempotencyHash($data) : null,
                    'status' => OrderStatus::PendingPayment,
                    'subtotal' => (string) $subtotal,
                    'shipping_cost' => (string) $shippingCost,
                    'total' => (string) $total,
                ]);

                $order->items()->createMany($orderItems);
                $order->statusLogs()->create([
                    'from_status' => null,
                    'to_status' => OrderStatus::PendingPayment->value,
                    'actor_type' => 'customer',
                    'actor_id' => $data['customer_id'],
                    'notes' => 'Checkout dibuat.',
                    'created_at' => now(),
                ]);

                return $order->load(['items', 'statusLogs']);
            }, 3);
        } catch (QueryException $exception) {
            // On databases without gap locking, the unique constraint resolves a simultaneous key race.
            if ($existing = $this->findIdempotentOrder($data)) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status !== OrderStatus::PendingPayment) {
                throw new BusinessRuleException(
                    'Pesanan hanya dapat dibatalkan saat menunggu pembayaran.',
                    ['status' => ['Status pesanan bukan pending_payment.']],
                    409,
                );
            }

            $items = $lockedOrder->items()->orderBy('product_id')->get();
            Product::query()
                ->whereIn('id', $items->pluck('product_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                Product::whereKey($item->product_id)->increment('stock', $item->quantity);
            }

            $lockedOrder->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
            ]);
            $lockedOrder->statusLogs()->create([
                'from_status' => OrderStatus::PendingPayment->value,
                'to_status' => OrderStatus::Cancelled->value,
                'actor_type' => 'customer',
                'actor_id' => $lockedOrder->customer_id,
                'notes' => 'Pesanan dibatalkan.',
                'created_at' => now(),
            ]);

            return $lockedOrder->load(['items', 'statusLogs']);
        }, 3);
    }

    public function markPaid(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status !== OrderStatus::PendingPayment) {
                throw new BusinessRuleException(
                    'Pesanan hanya dapat dibayar saat menunggu pembayaran.',
                    ['status' => ['Status pesanan bukan pending_payment.']],
                    409,
                );
            }

            $lockedOrder->update(['status' => OrderStatus::Paid]);
            $lockedOrder->statusLogs()->create([
                'from_status' => OrderStatus::PendingPayment->value,
                'to_status' => OrderStatus::Paid->value,
                'actor_type' => 'system',
                'notes' => 'Pembayaran dikonfirmasi.',
                'created_at' => now(),
            ]);

            return $lockedOrder->load(['items', 'statusLogs']);
        }, 3);
    }

    private function newOrderCode(): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    private function findIdempotentOrder(array $data): ?Order
    {
        if (empty($data['idempotency_key'])) {
            return null;
        }

        $order = Order::query()
            ->where('customer_id', $data['customer_id'])
            ->where('idempotency_key', $data['idempotency_key'])
            ->with(['items', 'statusLogs'])
            ->first();

        if ($order && ! hash_equals($order->idempotency_hash, $this->idempotencyHash($data))) {
            throw new BusinessRuleException(
                'Idempotency-Key sudah digunakan untuk payload checkout yang berbeda.',
                ['idempotency_key' => ['Gunakan key baru untuk checkout yang berbeda.']],
                409,
            );
        }

        return $order;
    }

    private function idempotencyHash(array $data): string
    {
        $items = collect($data['items'])
            ->map(fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ])
            ->sortBy('product_id')
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'customer_id' => (int) $data['customer_id'],
            'seller_id' => (int) $data['seller_id'],
            'shipping_cost' => (string) BigDecimal::of($data['shipping_cost'])->toScale(2),
            'items' => $items,
        ], JSON_THROW_ON_ERROR));
    }
}
