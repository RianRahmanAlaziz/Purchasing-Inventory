<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Distribution\Actions\DistributionWorkflow;
use App\Domain\Distribution\Models\Distribution;
use App\Domain\Distribution\Models\DistributionItem;
use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Item\Models\Item;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DistributionApiTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;

    private User $employeeUser;

    private User $staff;

    private User $manager;

    private Item $item;

    private Item $otherItem;

    private int $roleSerial = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        foreach ([
            'distribution.view', 'distribution.create', 'distribution.approve',
            'distribution.reject', 'distribution.prepare', 'distribution.send',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $dept = Department::query()->create([
            'code' => 'DIST-DEPT', 'name' => 'Department Distribusi', 'is_active' => true,
        ]);
        $this->employeeUser = User::factory()->create(['is_active' => true]);
        $this->employee = Employee::query()->create([
            'department_id' => $dept->id, 'user_id' => $this->employeeUser->id,
            'employee_number' => 'DIST-EMP-001', 'name' => 'Penerima Distribusi',
            'is_active' => true,
        ]);
        $category = Category::query()->create([
            'code' => 'DIST-CAT', 'name' => 'Kategori Distribusi', 'is_active' => true,
        ]);
        $this->item = Item::query()->create([
            'category_id' => $category->id, 'sku' => 'DIST-ITEM-001',
            'name' => 'Kertas', 'unit' => 'rim', 'minimum_stock' => '2.000', 'is_active' => true,
        ]);
        $this->otherItem = Item::query()->create([
            'category_id' => $category->id, 'sku' => 'DIST-ITEM-002',
            'name' => 'Pulpen', 'unit' => 'pcs', 'minimum_stock' => '2.000', 'is_active' => true,
        ]);
        $this->staff = $this->userWith([
            'distribution.view', 'distribution.create', 'distribution.prepare', 'distribution.send',
        ]);
        $this->manager = $this->userWith([
            'distribution.view', 'distribution.approve', 'distribution.reject',
        ]);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::findOrCreate('distribution_test_role_'.(++$this->roleSerial), 'web');
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user, array $abilities = ['*']): void
    {
        Sanctum::actingAs($user, $abilities);
    }

    private function payload(?Employee $employee = null): array
    {
        return [
            'employee_id' => ($employee ?? $this->employee)->id,
            'purpose' => 'Perlengkapan kerja kantor',
            'items' => [
                ['item_id' => $this->item->id, 'quantity' => '3.000', 'notes' => 'Untuk pekerjaan'],
            ],
        ];
    }

    private function requested(): Distribution
    {
        $this->as($this->staff);
        $result = $this->postJson('/api/v1/distributions', $this->payload())->assertCreated();

        return Distribution::query()->findOrFail($result->json('data.id'));
    }

    private function approved(): Distribution
    {
        $distribution = $this->requested();
        $this->as($this->manager);
        $this->postJson($this->url($distribution, 'approve'))->assertOk();

        return $distribution->fresh();
    }

    private function preparing(): Distribution
    {
        $distribution = $this->approved();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'prepare'))->assertOk();

        return $distribution->fresh();
    }

    private function stock(string $qty = '10.000', ?Item $item = null): void
    {
        $item ??= $this->item;
        app(StockService::class)->adjustTo(
            (int) $item->id, (string) $item->fresh()->stock_quantity,
            $qty, 'Stok awal untuk pengujian distribusi',
            (int) $this->staff->id, (string) Str::uuid()
        );
    }

    private function url(Distribution $distribution, string $action): string
    {
        return '/api/v1/distributions/'.$distribution->id.'/'.$action;
    }

    private function outCount(): int
    {
        return DB::table('stock_movements')->where('movement_type', 'distribution_out')->count();
    }

    public function test_guest_cannot_access_distribution_endpoints(): void
    {
        $this->getJson('/api/v1/distributions')->assertUnauthorized();
        $this->postJson('/api/v1/distributions', $this->payload())->assertUnauthorized();
    }

    public function test_employee_without_operational_role_can_request_own_distribution(): void
    {
        $this->as($this->employeeUser, ['employee:self']);
        $payload = $this->payload();
        unset($payload['employee_id']);
        $this->postJson('/api/v1/distributions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.employee_id', $this->employee->id)
            ->assertJsonPath('data.items.0.quantity', '3.000');
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame(0, $this->outCount());
    }

    public function test_employee_cannot_request_for_other_employee(): void
    {
        $other = Employee::query()->create([
            'department_id' => $this->employee->department_id,
            'employee_number' => 'DIST-EMP-OTHER', 'name' => 'Orang lain', 'is_active' => true,
        ]);
        $this->as($this->employeeUser, ['employee:self']);
        $this->postJson('/api/v1/distributions', $this->payload($other))->assertForbidden();
    }

    public function test_staff_can_create_distribution_on_behalf_of_employee(): void
    {
        $this->as($this->staff);
        $response = $this->postJson('/api/v1/distributions', $this->payload())
            ->assertCreated()->assertJsonPath('data.status', 'requested');
        $this->assertSame($this->staff->id, $response->json('data.requested_by'));
        $this->assertStringStartsWith('DIST-', $response->json('data.distribution_number'));
        $this->assertDatabaseHas('distribution_events', ['distribution_id' => $response->json('data.id'), 'action' => 'requested']);
    }

    public function test_user_without_role_or_employee_cannot_request(): void
    {
        $this->as(User::factory()->create(['is_active' => true]));
        $this->postJson('/api/v1/distributions', $this->payload())->assertForbidden();
    }

    public function test_distribution_must_have_at_least_one_item(): void
    {
        $this->as($this->staff);
        $data = $this->payload();
        $data['items'] = [];
        $this->postJson('/api/v1/distributions', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_repeated_items_are_rejected(): void
    {
        $this->as($this->staff);
        $data = $this->payload();
        $data['items'][] = ['item_id' => $this->item->id, 'quantity' => '1.000'];
        $this->postJson('/api/v1/distributions', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.item_id');
    }

    public function test_zero_negative_and_over_precision_quantities_are_rejected(): void
    {
        $this->as($this->staff);
        foreach (['0.000', '-1.000', '1.1234'] as $quantity) {
            $data = $this->payload();
            $data['items'][0]['quantity'] = $quantity;
            $this->postJson('/api/v1/distributions', $data)->assertUnprocessable();
        }
    }

    public function test_inactive_item_cannot_be_requested(): void
    {
        $this->item->update(['is_active' => false]);
        $this->as($this->staff);
        $this->postJson('/api/v1/distributions', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.item_id');
    }

    public function test_inactive_employee_cannot_be_target(): void
    {
        $this->employee->update(['is_active' => false]);
        $this->as($this->staff);
        $this->postJson('/api/v1/distributions', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    }

    public function test_client_cannot_set_distribution_status(): void
    {
        $this->as($this->staff);
        $data = $this->payload();
        $data['status'] = 'sent';
        $this->postJson('/api/v1/distributions', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_employee_can_only_view_own_distributions(): void
    {
        $owned = $this->requested();
        $otherEmployee = Employee::query()->create([
            'department_id' => $this->employee->department_id,
            'employee_number' => 'DIST-EMP-NONOWNER', 'name' => 'Karyawan lain', 'is_active' => true,
        ]);
        $this->as($this->staff);
        $otherPayload = $this->payload($otherEmployee);
        $otherId = $this->postJson('/api/v1/distributions', $otherPayload)->assertCreated()->json('data.id');

        $this->as($this->employeeUser, ['employee:self']);
        $this->getJson('/api/v1/distributions/'.$owned->id)->assertOk();
        $this->getJson('/api/v1/distributions/'.$otherId)->assertForbidden();
        $this->getJson('/api/v1/distributions')
            ->assertOk()->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_viewer_can_read_but_cannot_approve_or_send(): void
    {
        $distribution = $this->requested();
        $this->as($this->userWith(['distribution.view']));
        $this->getJson('/api/v1/distributions/'.$distribution->id)->assertOk();
        $this->postJson($this->url($distribution, 'approve'))->assertForbidden();
        $this->postJson($this->url($distribution, 'send'))->assertForbidden();
    }

    public function test_approve_requires_requested_status_and_does_not_touch_stock(): void
    {
        $distribution = $this->requested();
        $this->as($this->manager);
        $this->postJson($this->url($distribution, 'approve'))
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson($this->url($distribution, 'approve'))->assertStatus(409);
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame(0, $this->outCount());
    }

    public function test_requester_cannot_approve_their_own_request_even_with_permission(): void
    {
        $actor = $this->userWith(['distribution.create', 'distribution.approve']);
        $this->as($actor);
        $id = $this->postJson('/api/v1/distributions', $this->payload())
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/distributions/'.$id.'/approve')->assertStatus(409);
    }

    public function test_rejection_requires_reason_and_marks_cancelled_without_stock(): void
    {
        $distribution = $this->requested();
        $this->as($this->manager);
        $this->postJson($this->url($distribution, 'reject'), [])->assertUnprocessable();
        $this->postJson($this->url($distribution, 'reject'), ['reason' => 'Tidak sesuai kebutuhan'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'prepare'))->assertStatus(409);
        $this->assertSame(0, $this->outCount());
    }

    public function test_prepare_only_after_approval_without_stock_change(): void
    {
        $distribution = $this->requested();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'prepare'))->assertStatus(409);
        $this->as($this->manager);
        $this->postJson($this->url($distribution, 'approve'))->assertOk();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'prepare'))
            ->assertOk()->assertJsonPath('data.status', 'preparing');
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
    }

    public function test_send_only_from_preparing_and_decrements_stock_with_audit(): void
    {
        $this->stock();
        $distribution = $this->requested();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'send'))->assertStatus(409);
        $this->as($this->manager);
        $this->postJson($this->url($distribution, 'approve'))->assertOk();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'prepare'))->assertOk();
        $this->postJson($this->url($distribution, 'send'))
            ->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertSame('7.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame(1, $this->outCount());
        $lineId = DistributionItem::query()->where('distribution_id', $distribution->id)->value('id');
        $this->assertDatabaseHas('stock_movements', [
            'distribution_item_id' => $lineId, 'movement_type' => 'distribution_out',
            'quantity_change' => '-3.000', 'balance_after' => '7.000',
        ]);
    }

    public function test_duplicate_send_request_does_not_decrement_twice(): void
    {
        $this->stock();
        $distribution = $this->preparing();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'send'))->assertOk();
        $this->postJson($this->url($distribution, 'send'))->assertOk();
        $this->assertSame('7.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame(1, $this->outCount());
        $this->assertSame(1, DB::table('distribution_events')
            ->where('distribution_id', $distribution->id)->where('action', 'sent')->count());
    }

    public function test_insufficient_stock_rolls_back_status_and_audit(): void
    {
        $this->stock('2.000');
        $distribution = $this->preparing();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'send'))->assertStatus(409);
        $this->assertSame('preparing', $distribution->fresh()->status);
        $this->assertNull($distribution->fresh()->sent_at);
        $this->assertSame('2.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame(0, $this->outCount());
        $this->assertSame(0, DB::table('distribution_events')->where('action', 'sent')->count());
    }

    public function test_multiline_send_failure_rolls_back_first_item_movement(): void
    {
        $this->stock('10.000', $this->item);
        $this->stock('1.000', $this->otherItem);
        $this->as($this->staff);
        $data = $this->payload();
        $data['items'][] = ['item_id' => $this->otherItem->id, 'quantity' => '3.000'];
        $id = $this->postJson('/api/v1/distributions', $data)->assertCreated()->json('data.id');
        $distribution = Distribution::query()->findOrFail($id);
        $this->as($this->manager);
        $this->postJson($this->url($distribution, 'approve'))->assertOk();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'prepare'))->assertOk();
        $this->postJson($this->url($distribution, 'send'))->assertStatus(409);
        $this->assertSame('preparing', $distribution->fresh()->status);
        $this->assertSame('10.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame('1.000', (string) $this->otherItem->fresh()->stock_quantity);
        $this->assertSame(0, $this->outCount());
    }

    public function test_receive_only_by_actual_employee_after_sent(): void
    {
        $this->stock();
        $distribution = $this->preparing();
        $this->as($this->employeeUser, ['employee:self']);
        $this->postJson($this->url($distribution, 'receive'))->assertStatus(409);
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'send'))->assertOk();
        $this->postJson($this->url($distribution, 'receive'))->assertForbidden();
        $this->as($this->employeeUser, ['employee:self']);
        $this->postJson($this->url($distribution, 'receive'))
            ->assertOk()->assertJsonPath('data.status', 'received_by_employee');
        $this->assertSame(1, $this->outCount());
        $this->assertSame('7.000', (string) $this->item->fresh()->stock_quantity);
    }

    public function test_other_employee_cannot_receive_even_when_logged_in(): void
    {
        $this->stock();
        $distribution = $this->preparing();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'send'))->assertOk();
        $otherUser = User::factory()->create(['is_active' => true]);
        Employee::query()->create([
            'department_id' => $this->employee->department_id, 'user_id' => $otherUser->id,
            'employee_number' => 'DIST-EMP-004', 'name' => 'Bukan penerima', 'is_active' => true,
        ]);
        $this->as($otherUser, ['employee:self']);
        $this->postJson($this->url($distribution, 'receive'))->assertForbidden();
    }

    public function test_receive_replay_does_not_change_stock_twice(): void
    {
        $this->stock();
        $distribution = $this->preparing();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'send'))->assertOk();
        $this->as($this->employeeUser, ['employee:self']);
        $this->postJson($this->url($distribution, 'receive'))->assertOk();
        $this->postJson($this->url($distribution, 'receive'))->assertOk();
        $this->assertSame(1, $this->outCount());
        $this->assertSame('7.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame(1, DB::table('distribution_events')->where('distribution_id', $distribution->id)
            ->where('action', 'received')->count());
    }

    public function test_inactive_recipient_cannot_be_sent_goods(): void
    {
        $this->stock();
        $distribution = $this->preparing();
        $this->employee->update(['is_active' => false]);
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'send'))->assertStatus(409);
        $this->assertSame('preparing', $distribution->fresh()->status);
        $this->assertSame('10.000', (string) $this->item->fresh()->stock_quantity);
    }

    public function test_item_deactivated_after_request_cannot_be_sent(): void
    {
        $this->stock();
        $distribution = $this->preparing();
        $this->item->update(['is_active' => false]);
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'send'))->assertStatus(409);
        $this->assertSame('preparing', $distribution->fresh()->status);
        $this->assertSame('10.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertSame(0, $this->outCount());
    }

    public function test_list_filters_by_status_and_employee(): void
    {
        $distribution = $this->requested();
        $this->as($this->staff);
        $this->getJson('/api/v1/distributions?status=requested&employee_id='.$this->employee->id.'&per_page=5')
            ->assertOk()->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $distribution->id);
        $this->getJson('/api/v1/distributions?status=sent')
            ->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/v1/distributions?status=unknown')
            ->assertUnprocessable();
    }

    public function test_workflow_action_checks_permission_even_when_called_directly(): void
    {
        $distribution = $this->requested();
        $outsider = $this->userWith(['distribution.view']);
        $this->expectException(AuthorizationException::class);
        app(DistributionWorkflow::class)->approve($distribution, $outsider);
    }

    public function test_status_audit_contains_complete_successful_chain(): void
    {
        $this->stock();
        $distribution = $this->preparing();
        $this->as($this->staff);
        $this->postJson($this->url($distribution, 'send'))->assertOk();
        $this->as($this->employeeUser, ['employee:self']);
        $this->postJson($this->url($distribution, 'receive'))->assertOk();
        $this->assertSame(
            ['requested', 'approved', 'preparing', 'sent', 'received'],
            DB::table('distribution_events')->where('distribution_id', $distribution->id)
                ->orderBy('id')->pluck('action')->all()
        );
    }
}
