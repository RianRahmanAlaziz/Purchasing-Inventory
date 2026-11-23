<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class DistributionRecipientMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        config(['database.connections.distribution_migration' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('distribution_migration');

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
        });
        Schema::create('distributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('status', 40);
        });
        DB::table('employees')->insert(['id' => 1, 'name' => 'Penerima Lama']);
        DB::table('distributions')->insert(['id' => 1, 'employee_id' => 1, 'status' => 'requested']);
    }

    protected function tearDown(): void
    {
        DB::purge('distribution_migration');
        DB::setDefaultConnection('mysql');
        parent::tearDown();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_23_120001_allow_distribution_recipient_names.php');
    }

    public function test_existing_recipient_is_backfilled_without_changing_the_document_or_link(): void
    {
        $this->migration()->up();
        DB::table('employees')->where('id', 1)->update(['name' => 'Nama di Master Berubah']);
        $this->migration()->up();

        $row = DB::table('distributions')->sole();
        $this->assertSame(1, $row->employee_id);
        $this->assertSame('requested', $row->status);
        $this->assertSame('Penerima Lama', $row->recipient_name_snapshot);
    }

    public function test_rollback_preserves_linked_documents_and_maps_completion_to_the_legacy_status(): void
    {
        $migration = $this->migration();
        $migration->up();
        DB::table('distributions')->where('id', 1)->update(['status' => 'completed']);
        $migration->down();

        $row = DB::table('distributions')->sole();
        $this->assertSame(1, $row->employee_id);
        $this->assertSame('sent', $row->status);
        $this->assertFalse(Schema::hasColumn('distributions', 'recipient_name_snapshot'));
    }

    public function test_rollback_refuses_to_discard_an_unregistered_recipient(): void
    {
        $migration = $this->migration();
        $migration->up();
        DB::table('distributions')->insert([
            'id' => 2, 'employee_id' => null, 'recipient_name_snapshot' => 'Penerima Bebas', 'status' => 'completed',
        ]);

        try {
            $migration->down();
            $this->fail('Rollback harus menolak penghapusan nama penerima bebas.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback ditolak', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('distributions', 'recipient_name_snapshot'));
        $this->assertSame(2, DB::table('distributions')->count());
        $row = DB::table('distributions')->where('id', 2)->first();
        $this->assertSame('Penerima Bebas', $row->recipient_name_snapshot);
        $this->assertSame('completed', $row->status);
    }
}
