<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Seller $seller;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create(['name' => 'Customer', 'email' => 'customer@example.com']);
        $this->seller = Seller::create(['name' => 'Seller']);
        $this->productA = Product::create([
            'seller_id' => $this->seller->id,
            'name' => 'Produk A',
            'sku' => 'SKU-A',
            'price' => '100000.00',
            'stock' => 5,
            'status' => ProductStatus::Active,
        ]);
        $this->productB = Product::create([
            'seller_id' => $this->seller->id,
            'name' => 'Produk B',
            'sku' => 'SKU-B',
            'price' => '50000.00',
            'stock' => 3,
            'status' => ProductStatus::Active,
        ]);
    }

    public function test_checkout_creates_complete_order_and_reduces_stock(): void
    {
        $response = $this->postJson('/api/orders/checkout', $this->checkoutPayload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.subtotal', '250000.00')
            ->assertJsonPath('data.shipping_cost', '15000.00')
            ->assertJsonPath('data.total', '265000.00')
            ->assertJsonCount(2, 'data.items');

        $orderId = $response->json('data.order_id');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'pending_payment']);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $this->productA->id,
            'product_name_snapshot' => 'Produk A',
            'price_snapshot' => '100000.00',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $orderId,
            'from_status' => null,
            'to_status' => 'pending_payment',
        ]);
        $this->assertSame(3, $this->productA->fresh()->stock);
        $this->assertSame(2, $this->productB->fresh()->stock);
    }

    public function test_insufficient_stock_rolls_back_the_whole_checkout(): void
    {
        $payload = $this->checkoutPayload();
        $payload['items'][1]['quantity'] = 99;

        $this->postJson('/api/orders/checkout', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Stok produk tidak mencukupi.')
            ->assertJsonValidationErrors(['items.1.quantity']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(5, $this->productA->fresh()->stock);
        $this->assertSame(3, $this->productB->fresh()->stock);
    }

    public function test_checkout_rejects_duplicate_products(): void
    {
        $payload = $this->checkoutPayload();
        $payload['items'][1]['product_id'] = $this->productA->id;

        $this->postJson('/api/orders/checkout', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.product_id']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_idempotency_key_prevents_double_checkout(): void
    {
        $headers = ['Idempotency-Key' => 'checkout-attempt-123'];

        $first = $this->postJson('/api/orders/checkout', $this->checkoutPayload(), $headers)
            ->assertCreated();
        $second = $this->postJson('/api/orders/checkout', $this->checkoutPayload(), $headers)
            ->assertOk();

        $this->assertSame($first->json('data.order_id'), $second->json('data.order_id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(3, $this->productA->fresh()->stock);
        $this->assertSame(2, $this->productB->fresh()->stock);
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_payload(): void
    {
        $headers = ['Idempotency-Key' => 'checkout-attempt-123'];
        $this->postJson('/api/orders/checkout', $this->checkoutPayload(), $headers)
            ->assertCreated();

        $changedPayload = $this->checkoutPayload();
        $changedPayload['items'][0]['quantity'] = 1;

        $this->postJson('/api/orders/checkout', $changedPayload, $headers)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(3, $this->productA->fresh()->stock);
    }

    public function test_double_checkout_with_limited_stock_never_makes_stock_negative(): void
    {
        $this->productA->update(['stock' => 2]);
        $payload = [
            'customer_id' => $this->customer->id,
            'seller_id' => $this->seller->id,
            'shipping_cost' => 0,
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 2],
            ],
        ];

        $this->postJson('/api/orders/checkout', $payload, ['Idempotency-Key' => 'first-checkout'])
            ->assertCreated();
        $this->postJson('/api/orders/checkout', $payload, ['Idempotency-Key' => 'second-checkout'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stok produk tidak mencukupi.');

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(0, $this->productA->fresh()->stock);
        $this->assertGreaterThanOrEqual(0, $this->productA->fresh()->stock);
    }

    public function test_checkout_rejects_product_from_another_seller(): void
    {
        $otherSeller = Seller::create(['name' => 'Seller Lain']);
        $otherProduct = Product::create([
            'seller_id' => $otherSeller->id,
            'name' => 'Produk Lain',
            'sku' => 'SKU-OTHER',
            'price' => '1000.00',
            'stock' => 5,
            'status' => ProductStatus::Active,
        ]);
        $payload = $this->checkoutPayload();
        $payload['items'][0]['product_id'] = $otherProduct->id;

        $response = $this->postJson('/api/orders/checkout', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Semua produk harus berasal dari seller yang dipilih.');

        $this->assertSame(
            'Produk bukan milik seller yang dipilih.',
            $response->json('errors')['items.0.product_id'][0],
        );
    }

    public function test_checkout_rejects_inactive_product_without_changing_stock(): void
    {
        $this->productA->update(['status' => ProductStatus::Inactive]);

        $this->postJson('/api/orders/checkout', $this->checkoutPayload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Produk tidak aktif.')
            ->assertJsonValidationErrors(['items.0.product_id']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $this->productA->fresh()->stock);
    }

    public function test_checkout_validates_customer_seller_shipping_and_quantity(): void
    {
        $payload = $this->checkoutPayload();
        $payload['customer_id'] = 999999;
        $payload['seller_id'] = 999999;
        $payload['shipping_cost'] = -1;
        $payload['items'][0]['quantity'] = 0;

        $response = $this->postJson('/api/orders/checkout', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.customer_id.0', 'Customer tidak ditemukan.')
            ->assertJsonPath('errors.seller_id.0', 'Seller tidak ditemukan.')
            ->assertJsonPath('errors.shipping_cost.0', 'Biaya pengiriman tidak boleh negatif.')
            ->assertJsonValidationErrors([
                'customer_id',
                'seller_id',
                'shipping_cost',
                'items.0.quantity',
            ]);

        $this->assertSame('Quantity minimal 1.', $response->json('errors')['items.0.quantity'][0]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_ignores_client_supplied_prices(): void
    {
        $payload = $this->checkoutPayload();
        $payload['items'][0]['price'] = '1.00';
        $payload['items'][0]['subtotal'] = '2.00';

        $this->postJson('/api/orders/checkout', $payload)
            ->assertCreated()
            ->assertJsonPath('data.items.0.price', '100000.00')
            ->assertJsonPath('data.items.0.subtotal', '200000.00')
            ->assertJsonPath('data.total', '265000.00');
    }

    public function test_cancel_restores_stock_once_and_records_status(): void
    {
        $orderId = $this->postJson('/api/orders/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->json('data.order_id');

        $this->postJson("/api/orders/$orderId/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_at', fn ($value) => is_string($value));

        $this->assertSame(5, $this->productA->fresh()->stock);
        $this->assertSame(3, $this->productB->fresh()->stock);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $orderId,
            'from_status' => 'pending_payment',
            'to_status' => 'cancelled',
        ]);

        $this->postJson("/api/orders/$orderId/cancel")
            ->assertStatus(409)
            ->assertJsonPath('success', false);
        $this->assertSame(5, $this->productA->fresh()->stock);
        $this->assertSame(3, $this->productB->fresh()->stock);
    }

    public function test_mark_paid_allows_only_one_pending_to_paid_transition(): void
    {
        $orderId = $this->postJson('/api/orders/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->json('data.order_id');

        $this->postJson("/api/orders/$orderId/mark-paid")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->postJson("/api/orders/$orderId/mark-paid")
            ->assertStatus(409)
            ->assertJsonPath('success', false);
        $this->postJson("/api/orders/$orderId/cancel")
            ->assertStatus(409);
        $this->assertSame(3, $this->productA->fresh()->stock);
    }

    public function test_cancelled_order_cannot_be_marked_paid(): void
    {
        $orderId = $this->postJson('/api/orders/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->json('data.order_id');

        $this->postJson("/api/orders/$orderId/cancel")->assertOk();
        $this->postJson("/api/orders/$orderId/mark-paid")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'cancelled']);
        $this->assertDatabaseMissing('order_status_logs', [
            'order_id' => $orderId,
            'to_status' => 'paid',
        ]);
    }

    public function test_products_endpoints_use_consistent_envelope(): void
    {
        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/products/{$this->productA->id}")
            ->assertOk()
            ->assertJsonPath('data.sku', 'SKU-A');

        $this->getJson('/api/products/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->postJson('/api/products')
            ->assertStatus(405)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    private function checkoutPayload(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'seller_id' => $this->seller->id,
            'shipping_cost' => 15000,
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 2],
                ['product_id' => $this->productB->id, 'quantity' => 1],
            ],
        ];
    }
}
