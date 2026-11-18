<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Category\Models\Category;
use App\Domain\Employee\Models\Department;
use App\Domain\Employee\Models\Employee;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Item\Models\Item;
use App\Domain\Purchasing\Actions\PurchaseOrderWorkflow;
use App\Domain\Purchasing\Actions\SaveDraftPurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PurchaseOrderApiTest extends TestCase
{
    use DatabaseTransactions;

    private Supplier $supplier;

    private Department $department;

    private Employee $employee;

    private Item $item;

    private int $roleIndex = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('purchasing_test', DB::selectOne('SELECT DATABASE() AS name')->name);

        foreach ([
            'po.view', 'po.create', 'po.update', 'po.submit', 'po.send', 'po.supplier-confirm',
            'po.approve.hr', 'po.approve.gm', 'po.reject.hr', 'po.reject.gm',
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->department = Department::query()->create([
            'code' => 'PO-DEPT', 'name' => 'PO Department', 'is_active' => true,
        ]);
        $this->employee = Employee::query()->create([
            'employee_number' => 'PO-EMP-001', 'department_id' => $this->department->id,
            'name' => 'Pemohon PO', 'is_active' => true,
        ]);
        $this->supplier = Supplier::query()->create([
            'code' => 'PO-SUP', 'name' => 'Supplier Uji',
            'normalized_name' => 'supplier uji', 'is_active' => true,
        ]);
        $category = Category::query()->create([
            'code' => 'PO-CAT', 'name' => 'Kategori Pengadaan', 'is_active' => true,
        ]);
        $this->item = Item::query()->create([
            'category_id' => $category->id, 'sku' => 'PO-SKU-01',
            'name' => 'Barang Pengujian', 'unit' => 'pcs',
            'minimum_stock' => '5.000', 'is_active' => true,
        ]);
    }

    private function actor(array $permissions, ?string $roleName = null): User
    {
        $role = Role::findOrCreate($roleName ?? 'po_dynamic_test_'.(++$this->roleIndex), 'web');
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function payload(string $price = '1000000.00'): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'requested_for_employee_id' => $this->employee->id,
            'requested_for_department_id' => $this->department->id,
            'order_date' => '2026-11-04',
            'expected_date' => '2026-11-20',
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => '1.000',
                'unit_price' => $price,
            ]],
            'tax_rate_ids' => [],
        ];
    }

    private function draft(User $maker, string $price = '1000000.00', ?array $payload = null): PurchaseOrder
    {
        return app(SaveDraftPurchaseOrder::class)->create($payload ?? $this->payload($price), (int) $maker->id);
    }

    private function submitAs(User $maker, PurchaseOrder $po): void
    {
        $this->signIn($maker);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/submit")->assertOk();
    }

    private function approveAs(User $approver, PurchaseOrder $po, string $step): void
    {
        $this->signIn($approver);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/{$step}/approve")->assertOk();
    }

    public function test_guest_cannot_read_purchase_orders(): void
    {
        $this->getJson('/api/v1/purchase-orders')->assertUnauthorized();
    }

    public function test_role_without_create_permission_cannot_create_po(): void
    {
        $this->signIn($this->actor(['po.view']));
        $this->postJson('/api/v1/purchase-orders', $this->payload())->assertForbidden();
    }

    public function test_staff_hr_uploads_documentation_on_approved_po_and_order_shows_its_upload_status(): void
    {
        $disk = Storage::fake('local');
        $staff = $this->actor(['po.view', 'po.update', 'po.submit', 'orders.view'], 'staff_hr');
        $hr = $this->actor(['po.approve.hr'], 'hr');
        $po = $this->draft($staff);
        $this->submitAs($staff, $po);
        $this->approveAs($hr, $po, 'hr');
        $order = $po->fresh()->order;
        $invoiceCount = DB::table('invoices')->count();
        $paymentCount = DB::table('payments')->count();

        $this->signIn($staff);
        $url = "/api/v1/purchase-orders/{$po->id}/invoice-document";
        $orderUrl = "/api/v1/orders/{$order->id}";
        $this->getJson($url)->assertNotFound();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.purchase_order.invoice_upload_status', 'not_uploaded');
        $this->post($url, [
            'file' => UploadedFile::fake()->create('invoice-po.pdf', 12, 'application/pdf'),
            'status' => 'cancelled', 'total_amount' => '1.00',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.invoice_upload_status', 'uploaded');
        $this->getJson("/api/v1/purchase-orders/{$po->id}")->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.total_amount', '1000000.00')
            ->assertJsonPath('data.invoice_file_name', 'invoice-po.pdf')->assertJsonMissingPath('data.invoice_file_path');
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.purchase_order.invoice_upload_status', 'uploaded');
        $this->getJson($orderUrl)->assertOk()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.purchase_order.invoice_file_name', 'invoice-po.pdf');
        $this->get($url)->assertOk()->assertDownload('invoice-po.pdf');

        $oldPath = $po->fresh()->invoice_file_path;
        $this->post($url, ['file' => UploadedFile::fake()->create('revisi.pdf', 12, 'application/pdf')],
            ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.invoice_file_name', 'revisi.pdf');
        $disk->assertMissing($oldPath);
        $this->assertCount(1, $disk->allFiles('purchase-order-invoices'));

        $this->signIn($this->actor(['orders.view']));
        $this->get($orderUrl.'/invoice-document')->assertOk()->assertDownload('revisi.pdf');
        $this->getJson($url)->assertForbidden();
        $this->assertSame($invoiceCount, DB::table('invoices')->count());
        $this->assertSame($paymentCount, DB::table('payments')->count());
        $this->assertSame('0.000', $this->item->fresh()->stock_quantity);
    }

    public function test_po_invoice_upload_requires_permission_and_valid_file_and_gm_cannot_download_small_po_invoice(): void
    {
        $disk = Storage::fake('local');
        $staff = $this->actor(['po.view', 'po.update', 'po.submit'], 'staff_hr');
        $hr = $this->actor(['po.approve.hr'], 'hr');
        $po = $this->draft($staff);
        $this->submitAs($staff, $po);
        $this->approveAs($hr, $po, 'hr');
        $order = $po->fresh()->order;
        $url = "/api/v1/purchase-orders/{$po->id}/invoice-document";
        $this->signIn($staff);
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('file');
        foreach ([UploadedFile::fake()->create('script.html', 1, 'text/html'),
            UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')] as $invalid) {
            $this->post($url, ['file' => $invalid], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertSame([], $disk->allFiles('purchase-order-invoices'));
        $this->signIn($this->actor(['po.view']));
        $this->post($url, ['file' => UploadedFile::fake()->create('invoice.pdf', 1, 'application/pdf')],
            ['Accept' => 'application/json'])->assertForbidden();
        $this->signIn($this->actor(['po.view', 'orders.view'], 'general_manager'));
        $this->getJson($url)->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/invoice-document")->assertNotFound();
    }

    public function test_existing_invoice_attachment_is_available_on_order_and_is_preserved_when_po_gets_a_new_document(): void
    {
        $disk = Storage::fake('local');
        $staff = $this->actor(['po.view', 'po.update', 'po.submit', 'orders.view'], 'staff_hr');
        $hr = $this->actor(['po.approve.hr'], 'hr');
        $po = $this->draft($staff);
        $this->submitAs($staff, $po);
        $this->approveAs($hr, $po, 'hr');
        $order = $po->fresh()->order;
        $disk->put('invoices/legacy.pdf', '%PDF-fixture');
        $values = [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id,
            'invoice_date' => '2026-11-18', 'total_amount' => '1000.00', 'created_by' => $staff->id,
        ];
        Invoice::query()->create($values + ['invoice_number' => 'LEGACY-FILE'])
            ->forceFill(['file_path' => 'invoices/legacy.pdf', 'file_name' => 'lama.pdf'])->save();
        Invoice::query()->create($values + ['invoice_number' => 'WITHOUT-FILE']);
        Invoice::query()->create($values + ['invoice_number' => 'DELETED-FILE'])
            ->forceFill(['file_path' => 'invoices/deleted.pdf', 'file_name' => 'deleted.pdf'])->save();
        Invoice::query()->where('invoice_number', 'DELETED-FILE')->delete();

        $this->signIn($staff);
        $url = "/api/v1/purchase-orders/{$po->id}/invoice-document";
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.purchase_order.invoice_upload_status', 'uploaded')
            ->assertJsonPath('data.0.purchase_order.invoice_file_name', 'lama.pdf');
        $this->get("/api/v1/orders/{$order->id}/invoice-document")->assertOk()->assertDownload('lama.pdf');
        $this->post($url, ['file' => UploadedFile::fake()->create('baru.pdf', 12, 'application/pdf')],
            ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.purchase_order.invoice_file_name', 'baru.pdf');
        $disk->assertExists('invoices/legacy.pdf');
    }

    public function test_can_create_draft_and_preserve_snapshots_without_stock_mutation(): void
    {
        $maker = $this->actor(['po.create']);
        $this->signIn($maker);
        $response = $this->postJson('/api/v1/purchase-orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.sku', 'PO-SKU-01')
            ->assertJsonPath('data.subtotal', '1000000.00')
            ->assertJsonPath('data.order', null);
        $response->assertJsonPath('data.po_number', sprintf('PO-%06d', $response->json('data.id')));
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_staff_hr_can_save_edit_and_submit_po_with_names_and_no_employee_link(): void
    {
        $maker = $this->actor(['po.view', 'po.create', 'po.update', 'po.submit'], 'staff_hr');
        $this->signIn($maker);
        $this->assertNull($maker->employee()->first());
        $payload = $this->payload();
        unset($payload['requested_for_employee_id'], $payload['requested_for_department_id']);
        $payload['requested_for_employee_name'] = '  Pemohon   Lepas  ';
        $payload['requested_for_department_name'] = 'Tim Fasilitas';

        $id = $this->postJson('/api/v1/purchase-orders', $payload)->assertCreated()
            ->assertJsonPath('data.requested_for_employee_id', null)
            ->assertJsonPath('data.requested_for_department_id', null)
            ->assertJsonPath('data.requested_for_employee_name', 'Pemohon Lepas')
            ->assertJsonPath('data.requested_for_department_name', 'Tim Fasilitas')
            ->json('data.id');

        $this->patchJson("/api/v1/purchase-orders/{$id}", ['requested_for_department_name' => 'Operasional Baru'])
            ->assertOk()->assertJsonPath('data.requested_for_employee_name', 'Pemohon Lepas')
            ->assertJsonPath('data.requested_for_department_name', 'Operasional Baru');
        $this->postJson("/api/v1/purchase-orders/{$id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        $this->getJson('/api/v1/purchase-orders/suggestions/employees?q=lepas')->assertOk()
            ->assertJsonFragment(['name' => 'Pemohon Lepas', 'source' => 'history']);
        $this->getJson('/api/v1/purchase-orders/suggestions/departments?q=operasional%20baru')->assertOk()
            ->assertJsonFragment(['name' => 'Operasional Baru', 'source' => 'history']);
        $this->assertDatabaseMissing('employees', ['name' => 'Pemohon Lepas']);
        $this->assertDatabaseMissing('departments', ['name' => 'Operasional Baru']);
    }

    public function test_po_requires_at_least_one_item(): void
    {
        $this->signIn($this->actor(['po.create']));
        $payload = $this->payload();
        $payload['items'] = [];
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_po_rejects_inactive_supplier(): void
    {
        $this->supplier->update(['is_active' => false]);
        $this->signIn($this->actor(['po.create']));
        $this->postJson('/api/v1/purchase-orders', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }

    public function test_po_rejects_employee_department_mismatch(): void
    {
        $other = Department::query()->create(['code' => 'PO-OTHER', 'name' => 'Another PO Department', 'is_active' => true]);
        $this->signIn($this->actor(['po.create']));
        $payload = $this->payload();
        $payload['requested_for_department_id'] = $other->id;
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('requested_for_department_id');
    }

    public function test_duplicate_item_id_is_rejected(): void
    {
        $this->signIn($this->actor(['po.create']));
        $payload = $this->payload();
        $payload['items'][] = $payload['items'][0];
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.item_id');
    }

    public function test_tax_snapshots_and_approval_basis_are_calculated_exactly(): void
    {
        $addition = TaxType::query()->create(['code' => 'PO-TADD', 'name' => 'Pajak Tambah Tes', 'direction' => 'addition', 'is_active' => true]);
        $withholding = TaxType::query()->create(['code' => 'PO-TWH', 'name' => 'Pajak Potong Tes', 'direction' => 'withholding', 'is_active' => true]);
        $addRate = TaxRate::query()->create([
            'tax_type_id' => $addition->id, 'label' => '5% contoh',
            'rate_percent' => '5.0000', 'taxable_base_factor' => '1.00000000',
            'effective_from' => '2026-01-01', 'is_active' => true,
        ]);
        $whRate = TaxRate::query()->create([
            'tax_type_id' => $withholding->id, 'label' => '2% contoh',
            'rate_percent' => '2.0000', 'taxable_base_factor' => '1.00000000',
            'effective_from' => '2026-01-01', 'is_active' => true,
        ]);
        $this->signIn($this->actor(['po.create']));
        $payload = $this->payload('3000000.00');
        $payload['items'][0]['quantity'] = '2.000';
        $payload['tax_rate_ids'] = [$addRate->id, $whRate->id];
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '6000000.00')
            ->assertJsonPath('data.tax_addition_total', '300000.00')
            ->assertJsonPath('data.tax_withholding_total', '120000.00')
            ->assertJsonPath('data.approval_basis_amount', '6300000.00')
            ->assertJsonPath('data.total_amount', '6180000.00')
            ->assertJsonPath('data.requires_gm_approval', false);
    }

    public function test_below_ten_million_needs_only_hr(): void
    {
        $maker = $this->actor(['po.submit']);
        $hr = $this->actor(['po.approve.hr'], 'hr');
        $po = $this->draft($maker, '9999999.99');
        $this->assertSame('10000000.00', $po->approval_threshold_amount);
        $this->assertFalse($po->requires_gm_approval);

        $this->submitAs($maker, $po);
        $this->assertDatabaseCount('purchase_order_approvals', 1);
        $this->assertDatabaseHas('purchase_order_approvals', ['purchase_order_id' => $po->id, 'role_name' => 'hr']);
        $this->approveAs($hr, $po, 'hr');
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'approved']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_exactly_ten_million_needs_only_hr_and_creates_one_order(): void
    {
        Queue::fake([SendQueuedNotifications::class]);
        $maker = $this->actor(['po.view', 'po.submit']);
        $hr = $this->actor(['po.view', 'po.approve.hr'], 'hr');
        $gm = $this->actor(['po.view', 'po.approve.gm'], 'general_manager');
        $po = $this->draft($maker, '10000000.00');
        $this->submitAs($maker, $po);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'requires_gm_approval' => false]);
        $this->assertDatabaseCount('purchase_order_approvals', 1);
        $this->assertDatabaseCount('orders', 0);

        $this->approveAs($hr, $po, 'hr');
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'approved']);
        $this->assertDatabaseHas('orders', ['purchase_order_id' => $po->id, 'status' => 'pending']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame('PO menunggu persetujuan HR', $hr->notifications()->sole()->data['title']);
        $this->assertSame('PO disetujui', $maker->notifications()->sole()->data['title']);
        $this->assertSame(0, $gm->notifications()->count());
    }

    public function test_above_ten_million_requires_hr_then_gm(): void
    {
        $maker = $this->actor(['po.submit']);
        $hr = $this->actor(['po.approve.hr'], 'hr');
        $gm = $this->actor(['po.approve.gm'], 'general_manager');
        $po = $this->draft($maker, '10000000.01');
        $this->submitAs($maker, $po);
        $this->assertDatabaseCount('purchase_order_approvals', 2);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'requires_gm_approval' => true, 'approval_threshold_amount' => '10000000.00']);

        $this->signIn($gm);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/gm/approve")
            ->assertStatus(409);
        $this->assertDatabaseCount('orders', 0);

        $this->approveAs($hr, $po, 'hr');
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'pending_approval']);
        $this->assertDatabaseCount('orders', 0);

        $this->approveAs($gm, $po, 'gm');
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'approved']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_gm_approval_list_and_detail_only_include_orders_with_a_gm_step(): void
    {
        Queue::fake([SendQueuedNotifications::class]);
        $maker = $this->actor(['po.view', 'po.submit'], 'staff_hr');
        $hr = $this->actor(['po.view', 'po.approve.hr'], 'hr');
        $gm = $this->actor(['po.view', 'po.approve.gm'], 'general_manager');
        $admin = $this->actor(['po.view'], 'super_admin');
        $above = $this->draft($maker, '10000000.01');
        $below = $this->draft($maker, '9999999.99');
        $atLimit = $this->draft($maker, '10000000.00');
        $draft = $this->draft($maker, '11000000.00');
        foreach ([$above, $below, $atLimit] as $po) {
            $this->submitAs($maker, $po);
        }

        $this->getJson('/api/v1/purchase-orders/approvals')->assertForbidden();
        $this->getJson("/api/v1/purchase-orders/{$draft->id}")->assertOk();

        foreach ([$hr, $admin] as $approver) {
            $this->signIn($approver);
            $this->getJson('/api/v1/purchase-orders/approvals')->assertOk()->assertJsonCount(3, 'data')
                ->assertJsonPath('data.0.id', $atLimit->id)
                ->assertJsonPath('data.1.id', $below->id)
                ->assertJsonPath('data.2.id', $above->id);
        }

        $this->signIn($gm);
        foreach (['/api/v1/purchase-orders/approvals', '/api/v1/purchase-orders'] as $path) {
            $this->getJson($path.'?per_page=1')->assertOk()
                ->assertJsonPath('data.0.id', $above->id)
                ->assertJsonPath('meta.pagination.total', 1);
        }
        $this->getJson("/api/v1/purchase-orders/{$above->id}")->assertOk();
        foreach ([$below, $atLimit, $draft] as $po) {
            $this->getJson("/api/v1/purchase-orders/{$po->id}")->assertNotFound();
        }
    }

    public function test_approval_alerts_follow_the_active_step_and_inboxes_are_private(): void
    {
        Queue::fake([SendQueuedNotifications::class]);
        $maker = $this->actor(['po.view', 'po.submit'], 'staff_hr');
        $hr = $this->actor(['po.view', 'po.approve.hr'], 'hr');
        $gm = $this->actor(['po.view', 'po.approve.gm'], 'general_manager');
        $po = $this->draft($maker, '10000000.01');

        $this->submitAs($maker, $po);
        $this->submitAs($maker, $po);
        $this->signIn($hr);
        $hrNotice = $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.title', 'PO menunggu persetujuan HR')
            ->assertJsonPath('data.notifications.0.purchase_order_id', $po->id)
            ->assertJsonPath('data.notifications.0.target', 'approvals')
            ->json('data.notifications.0.id');
        $this->signIn($gm);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data.notifications');
        $this->patchJson("/api/v1/notifications/{$hrNotice}/read")->assertNotFound();
        Queue::assertNotPushed(SendQueuedNotifications::class, fn ($job): bool => $job->notifiables->first()->is($gm));

        $this->approveAs($hr, $po, 'hr');
        $this->approveAs($hr, $po, 'hr');
        $this->patchJson("/api/v1/notifications/{$hrNotice}/read")->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertSame('PO disetujui HR', $maker->notifications()->sole()->data['title']);
        $this->assertSame('PO menunggu persetujuan GM', $gm->notifications()->sole()->data['title']);
        $this->assertCount(1, Queue::pushed(
            SendQueuedNotifications::class,
            fn ($job): bool => $job->notifiables->first()->is($hr) && $job->channels === ['mail'],
        ));
        $this->assertCount(1, Queue::pushed(
            SendQueuedNotifications::class,
            fn ($job): bool => $job->notifiables->first()->is($gm) && $job->channels === ['mail'],
        ));

        $this->approveAs($gm, $po, 'gm');
        $this->approveAs($gm, $po, 'gm');
        $this->signIn($maker);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.unread_count', 2)
            ->assertJsonFragment(['title' => 'PO disetujui', 'target' => 'purchase-orders']);
        $this->assertCount(2, Queue::pushed(
            SendQueuedNotifications::class,
            fn ($job): bool => $job->notifiables->first()->is($maker) && $job->channels === ['mail'],
        ));
        $this->patchJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.unread_count', 0)
            ->assertJsonCount(2, 'data.notifications');
        $this->assertSame(1, $gm->unreadNotifications()->count());
    }

    public function test_rolled_back_po_transition_leaves_no_notification_or_email_job(): void
    {
        Queue::fake([SendQueuedNotifications::class]);
        $maker = $this->actor(['po.submit']);
        $hr = $this->actor(['po.view', 'po.approve.hr'], 'hr');
        $po = $this->draft($maker);
        $rollback = new \RuntimeException('Batalkan transaksi uji');

        try {
            DB::transaction(function () use ($po, $maker, $rollback): void {
                app(PurchaseOrderWorkflow::class)->submit($po, $maker);
                throw $rollback;
            });
        } catch (\RuntimeException $error) {
            $this->assertSame($rollback, $error);
        }

        $this->assertSame('draft', $po->fresh()->status);
        $this->assertSame(0, $hr->notifications()->count());
        Queue::assertNothingPushed();
    }

    public function test_creator_cannot_approve_own_po_even_with_permission(): void
    {
        $maker = $this->actor(['po.submit', 'po.approve.hr'], 'hr');
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/hr/approve")
            ->assertForbidden();
        $this->assertDatabaseHas('purchase_order_approvals', ['purchase_order_id' => $po->id, 'status' => 'pending']);
    }

    public function test_approver_can_see_requesting_employee_and_department_in_list_and_detail(): void
    {
        $maker = $this->actor(['po.submit']);
        $approver = $this->actor(['po.view', 'po.approve.hr'], 'hr');
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);

        $employeeName = $this->employee->name;
        $departmentName = $this->department->name;
        $newDepartment = Department::query()->create(['code' => 'PO-NEW-DEPT', 'name' => 'Departemen Baru', 'is_active' => true]);
        $this->employee->update(['department_id' => $newDepartment->id, 'name' => 'Nama Karyawan Baru']);
        $this->department->update(['name' => 'Nama Departemen Baru']);

        $this->signIn($approver);
        $this->getJson('/api/v1/purchase-orders/approvals?status=pending_approval')
            ->assertOk()
            ->assertJsonPath('data.0.id', $po->id)
            ->assertJsonPath('data.0.requested_for_employee_name', $employeeName)
            ->assertJsonPath('data.0.requested_for_department_name', $departmentName);
        $this->getJson("/api/v1/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertJsonPath('data.requested_for_employee_name', $employeeName)
            ->assertJsonPath('data.requested_for_department_name', $departmentName);
    }

    public function test_approval_responses_include_reviewer_names(): void
    {
        $maker = $this->actor(['po.submit', 'po.view']);
        $hr = $this->actor(['po.approve.hr'], 'hr');
        $gm = $this->actor(['po.approve.gm'], 'general_manager');
        $po = $this->draft($maker, '10000000.01');

        $this->signIn($maker);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.approvals.0.decided_by_name', null)
            ->assertJsonPath('data.approvals.1.decided_by_name', null);

        $this->signIn($hr);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/hr/approve")
            ->assertOk()
            ->assertJsonPath('data.approvals.0.decided_by_name', $hr->name)
            ->assertJsonPath('data.approvals.1.decided_by_name', null);

        $this->signIn($gm);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/gm/approve")
            ->assertOk()
            ->assertJsonPath('data.approvals.0.decided_by_name', $hr->name)
            ->assertJsonPath('data.approvals.1.decided_by_name', $gm->name);

        $hr->update(['is_active' => false]);
        $this->signIn($maker);
        $this->getJson("/api/v1/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertJsonPath('data.approvals.0.decided_by_name', $hr->name)
            ->assertJsonPath('data.approvals.1.decided_by_name', $gm->name);
        $this->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonPath('data.0.approvals.0.decided_by_name', $hr->name)
            ->assertJsonPath('data.0.approvals.1.decided_by_name', $gm->name);
    }

    public function test_staff_hr_cannot_decide_po_even_with_an_approval_permission(): void
    {
        $maker = $this->actor(['po.submit']);
        $staff = $this->actor(['po.view', 'po.approve.hr', 'po.reject.hr'], 'staff_hr');
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);

        $this->signIn($staff);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/hr/approve")->assertForbidden();
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/hr/reject", ['reason' => 'Alasan uji'])->assertForbidden();
        $this->assertSame('pending_approval', $po->fresh()->status);
    }

    public function test_user_without_hr_approval_permission_cannot_approve(): void
    {
        $maker = $this->actor(['po.submit']);
        $unauthorized = $this->actor(['po.view'], 'hr');
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->signIn($unauthorized);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/hr/approve")->assertForbidden();
    }

    public function test_same_approval_replay_is_idempotent(): void
    {
        $maker = $this->actor(['po.submit']);
        $hr = $this->actor(['po.approve.hr'], 'hr');
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->submitAs($maker, $po); // replay submit, tidak membuat step kedua
        $this->assertDatabaseCount('purchase_order_approvals', 1);
        $this->approveAs($hr, $po, 'hr');
        $this->approveAs($hr, $po, 'hr');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, DB::table('purchase_order_events')
            ->where('purchase_order_id', $po->id)->where('action', 'fully_approved')->count());
    }

    public function test_draft_can_update_but_submitted_cannot(): void
    {
        $maker = $this->actor(['po.update', 'po.submit']);
        $po = $this->draft($maker);
        $this->signIn($maker);
        $this->patchJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'Revisi draft'])
            ->assertOk()->assertJsonPath('data.notes', 'Revisi draft');
        $this->submitAs($maker, $po);
        $this->patchJson("/api/v1/purchase-orders/{$po->id}", ['notes' => 'Tidak boleh'])
            ->assertStatus(409);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'notes' => 'Revisi draft']);
    }

    public function test_hr_rejection_requires_reason_and_blocks_order(): void
    {
        config(['mail.default' => 'array', 'queue.default' => 'sync', 'purchasing.frontend_url' => 'https://inventory.example.test']);
        $maker = $this->actor(['po.view', 'po.submit']);
        $hr = $this->actor(['po.view', 'po.reject.hr'], 'hr');
        $gm = $this->actor(['po.view', 'po.approve.gm'], 'general_manager');
        $po = $this->draft($maker, '10000000.01');
        $this->submitAs($maker, $po);
        $this->signIn($hr);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/hr/reject", ['reason' => 'Tidak sesuai'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.approvals.0.decided_by_name', $hr->name);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('Tidak sesuai', $maker->notifications()->sole()->data['reason']);
        $this->assertSame(0, $gm->notifications()->count());
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $mail = $messages->last()->getOriginalMessage();
        $this->assertSame($maker->email, $mail->getTo()[0]->getAddress());
        $this->assertSame('PO ditolak · '.$po->po_number, $mail->getSubject());
        $this->assertStringContainsString('https://inventory.example.test/login?next=%2Fpurchase-orders%3Fpo%3D'.$po->id, $mail->getHtmlBody());
    }

    public function test_order_is_confirmed_when_supplier_confirms_and_stock_remains_unchanged(): void
    {
        $maker = $this->actor(['po.submit', 'po.send', 'po.supplier-confirm']);
        $hr = $this->actor(['po.approve.hr'], 'hr');
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->approveAs($hr, $po, 'hr');
        $this->signIn($maker);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")
            ->assertOk()->assertJsonPath('data.status', 'sent');
        $this->postJson("/api/v1/purchase-orders/{$po->id}/supplier-confirm")
            ->assertOk()->assertJsonPath('data.status', 'received_by_supplier')
            ->assertJsonPath('data.order.status', 'confirmed');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('0.000', (string) $this->item->fresh()->stock_quantity);
    }

    public function test_send_before_approval_is_rejected_and_rolled_back(): void
    {
        $maker = $this->actor(['po.send']);
        $po = $this->draft($maker);
        $this->signIn($maker);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/send")->assertStatus(409);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'draft']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_cancellation_needs_hr_approval_before_it_cancels_po_and_order(): void
    {
        Queue::fake([SendQueuedNotifications::class]);
        $maker = $this->actor(['po.view', 'po.submit', 'po.update', 'po.send'], 'staff_hr');
        $hr = $this->actor(['po.view', 'po.approve.hr'], 'hr');
        $gm = $this->actor(['po.view', 'po.approve.hr'], 'general_manager');
        $admin = $this->actor(['po.view', 'po.approve.hr'], 'super_admin');
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->approveAs($hr, $po, 'hr');
        $this->signIn($maker);
        $url = "/api/v1/purchase-orders/{$po->id}";
        $reason = ['reason' => 'Pembelian tidak jadi'];
        $id = $this->postJson($url.'/cancel', $reason)->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.order.status', 'pending')->assertJsonPath('data.cancellation.status', 'pending')->json('data.cancellation.id');
        $this->postJson($url.'/cancel', $reason)->assertOk()->assertJsonPath('data.cancellation.id', $id);
        $this->postJson($url.'/send')->assertConflict();

        $decision = $url."/cancellations/{$id}/approve";
        foreach ([$maker, $gm, $admin] as $nonHr) {
            $this->signIn($nonHr);
            $this->postJson($decision)->assertForbidden();
        }

        $this->signIn($hr);
        $this->getJson('/api/v1/purchase-orders/approvals')->assertOk()
            ->assertJsonPath('data.0.cancellation.reason', 'Pembelian tidak jadi');
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonFragment(['kind' => 'cancellation_request', 'purchase_order_id' => $po->id]);
        $this->postJson($decision)
            ->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.order.status', 'cancelled')->assertJsonPath('data.cancellation.decided_by_name', $hr->name);
        $this->postJson($decision)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('purchase_order_cancellations', 1);
        $this->assertSame(1, $po->events()->where('action', 'cancelled')->count());
    }

    public function test_hr_can_review_draft_cancellation_and_rejection_allows_submission_again(): void
    {
        Queue::fake([SendQueuedNotifications::class]);
        $maker = $this->actor(['po.view', 'po.update', 'po.submit', 'po.reject.hr'], 'hr');
        $hr = User::factory()->create(['is_active' => true])->assignRole('hr');
        $po = $this->draft($maker);
        $url = "/api/v1/purchase-orders/{$po->id}";
        $this->signIn($maker);
        $id = $this->postJson($url.'/cancel', ['reason' => 'Kebutuhan berubah'])->assertOk()->json('data.cancellation.id');
        $this->postJson($url.'/submit')->assertConflict();
        $this->deleteJson($url)->assertConflict();

        $reject = $url."/cancellations/{$id}/reject";
        $reason = ['reason' => 'Barang masih dibutuhkan'];
        $this->postJson($reject, $reason)->assertForbidden();
        $this->signIn($hr);
        $this->getJson('/api/v1/purchase-orders/approvals')->assertOk()->assertJsonPath('data.0.id', $po->id);
        $this->postJson($reject)->assertUnprocessable();
        $this->postJson($reject, $reason)->assertOk()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.cancellation.status', 'rejected')->assertJsonPath('data.cancellation.rejection_reason', $reason['reason']);
        $this->submitAs($maker, $po);
        $next = $this->postJson($url.'/cancel', ['reason' => 'Permintaan baru untuk dibatalkan'])->assertOk()->json('data.cancellation.id');
        $this->assertNotSame($id, $next);
        $this->signIn($hr);
        $this->postJson($reject, $reason)->assertOk()->assertJsonPath('data.cancellation.id', $next)
            ->assertJsonPath('data.cancellation.status', 'pending');
    }

    public function test_draft_can_be_soft_deleted_but_approved_cannot(): void
    {
        $maker = $this->actor(['po.submit', 'po.update']);
        $hr = $this->actor(['po.approve.hr'], 'hr');
        $unused = $this->draft($maker);
        $this->signIn($maker);
        $this->deleteJson("/api/v1/purchase-orders/{$unused->id}")->assertOk();
        $this->assertSoftDeleted('purchase_orders', ['id' => $unused->id]);
        $po = $this->draft($maker);
        $this->submitAs($maker, $po);
        $this->approveAs($hr, $po, 'hr');
        $this->signIn($maker);
        $this->deleteJson("/api/v1/purchase-orders/{$po->id}")->assertStatus(409);
    }

    public function test_po_rejects_tax_rate_outside_order_date(): void
    {
        $type = TaxType::query()->create([
            'code' => 'PO-FUTURE', 'name' => 'Pajak Masa Depan',
            'direction' => 'addition', 'is_active' => true,
        ]);
        $rate = TaxRate::query()->create([
            'tax_type_id' => $type->id, 'label' => 'Tarif belum berlaku',
            'rate_percent' => '5.0000', 'taxable_base_factor' => '1.00000000',
            'effective_from' => '2026-11-05', 'is_active' => true,
        ]);
        $maker = $this->actor(['po.create']);
        $this->signIn($maker);
        $payload = $this->payload();
        $payload['tax_rate_ids'] = [$rate->id];
        $this->postJson('/api/v1/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('tax_rate_ids');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_hr_and_gm_steps_cannot_be_decided_by_same_person(): void
    {
        $maker = $this->actor(['po.submit']);
        $both = $this->actor(['po.approve.hr', 'po.approve.gm'], 'super_admin');
        $po = $this->draft($maker, '10000000.01');
        $this->submitAs($maker, $po);
        $this->approveAs($both, $po, 'hr');
        $this->signIn($both);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approvals/gm/approve")
            ->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id, 'status' => 'pending_approval']);
    }

    public function test_po_calculation_snapshot_survives_supplier_rename(): void
    {
        $maker = $this->actor(['po.view']);
        $po = $this->draft($maker);
        $this->supplier->update(['name' => 'Nama Baru', 'normalized_name' => 'nama baru']);
        $this->signIn($maker);
        $this->getJson("/api/v1/purchase-orders/{$po->id}")
            ->assertOk()->assertJsonPath('data.supplier_name_snapshot', 'Supplier Uji');
    }
}
