<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutOrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'seller_id' => ['required', 'integer', 'exists:sellers,id'],
            'shipping_cost' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'idempotency_key' => ['sometimes', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct:strict', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Customer wajib diisi.',
            'customer_id.integer' => 'Customer ID harus berupa bilangan bulat.',
            'customer_id.exists' => 'Customer tidak ditemukan.',
            'seller_id.required' => 'Seller wajib diisi.',
            'seller_id.integer' => 'Seller ID harus berupa bilangan bulat.',
            'seller_id.exists' => 'Seller tidak ditemukan.',
            'shipping_cost.required' => 'Biaya pengiriman wajib diisi.',
            'shipping_cost.decimal' => 'Biaya pengiriman maksimal memiliki dua angka desimal.',
            'items.*.product_id.distinct' => 'Produk yang sama tidak boleh muncul lebih dari satu kali.',
            'shipping_cost.min' => 'Biaya pengiriman tidak boleh negatif.',
            'shipping_cost.max' => 'Biaya pengiriman melebihi batas nominal yang diizinkan.',
            'idempotency_key.string' => 'Idempotency-Key harus berupa teks.',
            'idempotency_key.max' => 'Idempotency-Key maksimal 100 karakter.',
            'items.required' => 'Item checkout wajib diisi.',
            'items.array' => 'Item checkout harus berupa array.',
            'items.min' => 'Checkout minimal memiliki satu item.',
            'items.*.product_id.required' => 'Product ID wajib diisi.',
            'items.*.product_id.integer' => 'Product ID harus berupa bilangan bulat.',
            'items.*.product_id.exists' => 'Produk tidak ditemukan.',
            'items.*.quantity.required' => 'Quantity wajib diisi.',
            'items.*.quantity.integer' => 'Quantity harus berupa bilangan bulat.',
            'items.*.quantity.min' => 'Quantity minimal 1.',
        ];
    }
}
