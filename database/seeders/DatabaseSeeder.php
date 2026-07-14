<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Customer::query()->updateOrCreate(['id' => 1], [
            'name' => 'Budi Customer',
            'email' => 'budi@example.com',
        ]);

        Seller::query()->updateOrCreate(['id' => 10], [
            'name' => 'OkaIki Store',
        ]);

        Seller::query()->updateOrCreate(['id' => 20], [
            'name' => 'Seller Lain',
        ]);

        Product::query()->upsert([
            [
                'id' => 101,
                'seller_id' => 10,
                'name' => 'Produk A',
                'sku' => 'SKU-A',
                'price' => '100000.00',
                'stock' => 10,
                'status' => ProductStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 102,
                'seller_id' => 10,
                'name' => 'Produk B',
                'sku' => 'SKU-B',
                'price' => '50000.00',
                'stock' => 5,
                'status' => ProductStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 103,
                'seller_id' => 10,
                'name' => 'Produk Nonaktif',
                'sku' => 'SKU-INACTIVE',
                'price' => '25000.00',
                'stock' => 3,
                'status' => ProductStatus::Inactive->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 104,
                'seller_id' => 10,
                'name' => 'Produk Stok Terbatas',
                'sku' => 'SKU-LIMITED',
                'price' => '30000.00',
                'stock' => 1,
                'status' => ProductStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 201,
                'seller_id' => 20,
                'name' => 'Produk Seller Lain',
                'sku' => 'SKU-OTHER-SELLER',
                'price' => '75000.00',
                'stock' => 10,
                'status' => ProductStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['id'], ['seller_id', 'name', 'sku', 'price', 'stock', 'status', 'updated_at']);
    }
}
