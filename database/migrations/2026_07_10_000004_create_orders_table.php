<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100)->nullable();
            $table->char('idempotency_hash', 64)->nullable();
            $table->enum('status', ['pending_payment', 'paid', 'cancelled']);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('shipping_cost', 15, 2);
            $table->decimal('total', 15, 2);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['seller_id', 'status']);
            $table->unique(['customer_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
