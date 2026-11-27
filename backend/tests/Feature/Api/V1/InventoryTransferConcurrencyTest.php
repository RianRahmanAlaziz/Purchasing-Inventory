<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Company\Models\Company;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Item\Models\Item;
use App\Domain\Transfer\Actions\TransferWorkflow;
use App\Domain\Transfer\Models\InventoryTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Separate processes need committed fixtures. Run this class separately from
 * dashboard aggregation tests; cleanup removes only the generated fixture IDs.
 */
final class InventoryTransferConcurrencyTest extends TestCase
{
    private ?Item $item = null;

    private ?Category $category = null;

    private ?Company $source = null;

    private ?Company $destination = null;

    private ?User $actor = null;

    private ?Role $role = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);
        $suffix = substr((string) Str::uuid(), 0, 8);
        $this->role = Role::findOrCreate('concurrent_transfer_'.$suffix, 'web');
        $this->role->givePermissionTo(['inventory.transfer.create', 'inventory.transfer.send']);
        $this->actor = User::factory()->create(['is_active' => true])->assignRole($this->role);
        $this->source = Company::query()->create(['name' => 'PT Concurrent Source '.$suffix, 'address' => 'Pengujian']);
        $this->destination = Company::query()->create(['name' => 'PT Concurrent Destination '.$suffix, 'address' => 'Pengujian']);
        $this->category = Category::query()->create(['code' => 'CC-'.$suffix, 'name' => 'Concurrent Test', 'is_active' => true]);
        $this->item = Item::query()->create([
            'category_id' => $this->category->id, 'sku' => 'CC-'.$suffix, 'name' => 'Concurrent Test '.$suffix,
            'unit' => 'pcs', 'item_type' => 'consumable', 'is_active' => true,
        ]);
        app(StockService::class)->adjustTo(
            (int) $this->item->id, '0.000', '5.000', 'Stock fixture for concurrent transfer',
            (int) $this->actor->id, (string) Str::uuid(), (int) $this->source->id,
        );
    }

    protected function tearDown(): void
    {
        if ($this->item !== null) {
            DB::transaction(function (): void {
                $ids = DB::table('inventory_transfers')->where('created_by', $this->actor->id)->pluck('id')->all();
                DB::table('stock_movements')->where('item_id', $this->item->id)->delete();
                DB::table('stock_adjustments')->where('item_id', $this->item->id)->delete();
                DB::table('inventory_transfer_events')->whereIn('inventory_transfer_id', $ids)->delete();
                DB::table('inventory_transfer_items')->whereIn('inventory_transfer_id', $ids)->delete();
                DB::table('inventory_transfers')->whereIn('id', $ids)->delete();
                DB::table('inventory_balances')->where('item_id', $this->item->id)->delete();
                DB::table('items')->where('id', $this->item->id)->delete();
            });
        }
        if ($this->category !== null) {
            DB::table('categories')->where('id', $this->category->id)->delete();
        }
        if ($this->actor !== null) {
            $this->actor->syncRoles([]);
            DB::table('users')->where('id', $this->actor->id)->delete();
        }
        $this->role?->delete();
        foreach ([$this->source, $this->destination] as $company) {
            if ($company !== null) {
                DB::table('companies')->where('id', $company->id)->delete();
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    private function draft(): InventoryTransfer
    {
        return app(TransferWorkflow::class)->save([
            'from_company_id' => $this->source->id, 'to_company_id' => $this->destination->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => '3.000']],
        ], $this->actor);
    }

    private function sendTogether(array $transfers): array
    {
        $startAt = microtime(true) + 1.5;
        $connection = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'purchasing_test',
            'DB_URL' => '', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array',
        ];
        $processes = array_map(fn (InventoryTransfer $transfer): Process => new Process([
            PHP_BINARY, base_path('tests/Support/send_inventory_transfer.php'),
            (string) $transfer->id, (string) $this->actor->id, (string) $startAt,
        ], base_path(), $environment, timeout: 30), $transfers);
        foreach ($processes as $process) {
            $process->start();
        }

        return array_map(function (Process $process): array {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $processes);
    }

    public function test_parallel_duplicate_send_decrements_source_only_once(): void
    {
        $transfer = $this->draft();
        $results = $this->sendTogether([$transfer, $transfer]);

        $this->assertSame([true, true], array_column($results, 'sent'));
        $this->assertSame('2.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame(1, DB::table('stock_movements')->where('item_id', $this->item->id)->where('movement_type', 'transfer_out')->count());
        $this->assertSame('sent', $transfer->fresh()->status);
        $this->assertSame('3.000', (string) $transfer->items()->sum('quantity'));
        $this->assertSame('0.000', (string) (DB::table('inventory_balances')
            ->where('item_id', $this->item->id)->where('company_id', $this->destination->id)->value('quantity') ?? '0.000'));
    }

    public function test_parallel_competing_transfers_cannot_overdraw_the_same_pt_balance(): void
    {
        $first = $this->draft();
        $second = $this->draft();
        $results = $this->sendTogether([$first, $second]);

        $this->assertSame(1, count(array_filter(array_column($results, 'sent'))));
        $this->assertSame('2.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame(1, DB::table('stock_movements')->where('item_id', $this->item->id)->where('movement_type', 'transfer_out')->count());
        $this->assertSame(1, DB::table('inventory_transfers')->whereIn('id', [$first->id, $second->id])->where('status', 'sent')->count());
        $this->assertSame(1, DB::table('inventory_transfers')->whereIn('id', [$first->id, $second->id])->where('status', 'draft')->count());
        $this->assertSame('3.000', (string) DB::table('inventory_transfer_items as lines')
            ->join('inventory_transfers as transfers', 'transfers.id', '=', 'lines.inventory_transfer_id')
            ->whereIn('transfers.id', [$first->id, $second->id])->where('transfers.status', 'sent')->sum('lines.quantity'));
    }
}
