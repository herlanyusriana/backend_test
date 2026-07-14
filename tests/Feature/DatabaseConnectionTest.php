<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_database_connection_matches_the_expected_test_driver(): void
    {
        $expectedDriver = getenv('EXPECTED_DB_DRIVER') ?: config('database.default');

        $this->assertSame($expectedDriver, DB::connection()->getDriverName());
    }
}
