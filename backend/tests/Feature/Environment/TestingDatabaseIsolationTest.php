<?php

declare(strict_types=1);

namespace Tests\Feature\Environment;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TestingDatabaseIsolationTest extends TestCase
{
    public function test_feature_tests_use_dedicated_mysql_testing_database(): void
    {
        $this->assertSame(
            'testing',
            app()->environment()
        );

        $this->assertSame(
            'mysql',
            config('database.default')
        );

        $expectedDatabase = 'purchasing_test';

        $this->assertSame(
            $expectedDatabase,
            config('database.connections.mysql.database'),
            'Periksa phpunit.xml dan .env.testing.'
        );

        $result = DB::selectOne(
            'SELECT DATABASE() AS database_name'
        );

        $this->assertNotNull($result);

        $this->assertSame(
            $expectedDatabase,
            $result->database_name,
            'Koneksi MySQL mengarah ke database yang salah.'
        );
    }
}
