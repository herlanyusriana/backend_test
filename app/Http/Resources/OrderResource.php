<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_id' => $this->id,
            'order_code' => $this->order_code,
            'customer_id' => $this->customer_id,
            'seller_id' => $this->seller_id,
            'status' => $this->status->value,
            'subtotal' => $this->subtotal,
            'shipping_cost' => $this->shipping_cost,
            'total' => $this->total,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name_snapshot,
                'sku' => $item->product_sku_snapshot,
                'price' => $item->price_snapshot,
                'quantity' => $item->quantity,
                'subtotal' => $item->subtotal,
            ])),
            'status_logs' => $this->whenLoaded('statusLogs', fn () => $this->statusLogs->map(fn ($log) => [
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'actor_type' => $log->actor_type,
                'actor_id' => $log->actor_id,
                'notes' => $log->notes,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toISOString(),
            ])),
        ];
    }
}
