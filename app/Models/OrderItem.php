<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id', 'product_name_snapshot', 'product_sku_snapshot',
        'price_snapshot', 'quantity', 'subtotal',
    ];

    protected function casts(): array
    {
        return ['price_snapshot' => 'decimal:2', 'subtotal' => 'decimal:2', 'quantity' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
